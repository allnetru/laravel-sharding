<?php

namespace Allnetru\Sharding;

use Allnetru\Sharding\Exceptions\UnsupportedCrossShardQuery;
use Allnetru\Sharding\Support\Coroutine\CoroutineDispatcher;
use ArrayIterator;
use Closure;
use Generator;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Iterator;

/**
 * Eloquent builder that queries across multiple shard connections.
 *
 * @method ShardBuilder withoutReplicas()
 */
class ShardBuilder extends EloquentBuilder
{
    /**
     * Indicates whether the builder operates on a single shard connection.
     */
    protected bool $singleConnection = false;

    /**
     * @return array<string, array{weight:int}>
     */
    protected function connections(): array
    {
        return app(ShardingManager::class)->connectionsFor($this->getModel());
    }

    /**
     * Create a replica builder for the specified connection.
     *
     * @param string $connection
     * @return self
     */
    protected function replicateForConnection(string $connection): self
    {
        $model = $this->getModel()->newInstance([], true)->setConnection($connection);
        $query = clone $this->getQuery();
        $query->connection = $model->getConnection();
        $builder = new self($query);
        $builder->setModel($model);
        $builder->singleConnection = true;
        $builder->withoutReplicas();
        $builder->setEagerLoads($this->getEagerLoads());
        $this->copyScopesTo($builder);

        return $builder;
    }

    /**
     * Carry the builder's global scopes over to a per-shard copy.
     *
     * Global scopes live on the builder and are applied lazily, so cloning the
     * query alone produced a copy with none of them: every fanned-out read
     * returned the rows its scopes existed to hide, soft-deleted ones first.
     *
     * The scope objects are passed rather than their constraints, because
     * withGlobalScope() re-runs extend() — which is what puts SoftDeletes'
     * delete callback and its macros back on the copy. Removals are carried
     * too, so withTrashed() on the original still means withTrashed() here.
     *
     * @param self $builder
     * @return void
     */
    protected function copyScopesTo(self $builder): void
    {
        foreach ($this->scopes as $identifier => $scope) {
            $builder->withGlobalScope($identifier, $scope);
        }

        foreach ($this->removedScopes as $identifier) {
            $builder->withoutGlobalScope($identifier);
        }
    }

    /**
     * Pin the builder to one shard connection.
     *
     * What makes colocation pay off. Setting the model's connection alone does
     * nothing here: every fan-out method checks $singleConnection first, so a
     * builder whose connection was chosen but not pinned still reads every
     * shard and merges. Relations call this once they know the shard for
     * certain; when they do not know it they leave the builder alone and the
     * fan-out answers.
     *
     * @param string $connection
     * @return static
     */
    public function onShardConnection(string $connection): static
    {
        $model = $this->getModel();
        $model->setConnection($connection);
        $this->getQuery()->connection = $model->getConnection();
        $this->singleConnection = true;

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function get($columns = ['*'])
    {
        if ($this->singleConnection) {
            return parent::get($columns);
        }

        $this->refuseUnmergeableOrder('get');

        $limit = $this->getQuery()->limit;
        $offset = $this->getQuery()->offset;

        if ($limit !== null || $offset !== null) {
            $this->getQuery()->limit = null;
            $this->getQuery()->offset = null;

            return $this->getWithLimitAndOffset($limit, $offset, $columns);
        }

        $batches = $this->runOnConnections(function (string $name, array $config) use ($columns) {
            $builder = $this->replicateForConnection($name);

            return $builder->get($columns)->all();
        });

        // the shards were read in parallel, so the merge runs over what is
        // already in memory rather than over open cursors
        $iterators = array_map(static fn (array $items): ArrayIterator => new ArrayIterator($items), $batches);

        return $this->getModel()->newCollection(
            iterator_to_array($this->mergeShardResults($iterators), false),
        );
    }

    /**
     * Execute the callback for each configured connection, leveraging Swoole when available.
     *
     * @template TValue
     *
     * @param callable(string, array<string, mixed>): TValue $callback
     * @return array<string, TValue>
     */
    protected function runOnConnections(callable $callback): array
    {
        $connections = $this->connections();

        if ($connections === []) {
            return [];
        }

        $tasks = [];

        foreach ($connections as $name => $config) {
            $tasks[$name] = fn () => $callback($name, $config);
        }

        return CoroutineDispatcher::run($tasks);
    }

    /**
     * @inheritdoc
     *
     * Emptying one shard is not emptying the table.
     */
    public function truncate(): void
    {
        if ($this->singleConnection) {
            $this->toBase()->truncate();

            return;
        }

        $this->runOnConnections(function (string $name): bool {
            $this->replicateForConnection($name)->toBase()->truncate();

            return true;
        });
    }

    /**
     * Choose the columns for one shard's query.
     *
     * Mirrors what Eloquent does on a single connection: the columns passed to
     * get() apply only when the query selected nothing of its own. Overwriting
     * unconditionally — which is what this used to do — dropped every
     * selectRaw() the caller had added, silently and only on the path a limit
     * takes.
     *
     * @param self $builder
     * @param array<int, mixed> $columns
     * @return void
     */
    protected function applySelect(self $builder, array $columns): void
    {
        if ($builder->getQuery()->columns === null) {
            $builder->select($columns);
        }

        $this->selectColumnsTheMergeOrdersBy($builder);
    }

    /**
     * Add the ordering columns to a narrowed select.
     *
     * The merge compares models, so a query that orders by a column it does
     * not select would be merged on a property that is null on every row —
     * which reads as "the order was ignored" and is impossible to see from
     * the outside. The rows come back carrying those columns; that is the
     * visible cost, and it is smaller than an order that quietly does nothing.
     *
     * Skipped when anything in the select is an expression: an alias defined
     * in the same select list cannot be re-selected, and there is no way to
     * tell from here whether the ordering column is one.
     *
     * @param self $builder
     * @return void
     */
    protected function selectColumnsTheMergeOrdersBy(self $builder): void
    {
        $columns = $builder->getQuery()->columns;

        if ($columns === null || $columns === []) {
            return;
        }

        foreach ($columns as $column) {
            if (!is_string($column)) {
                return;
            }

            if (str_contains($column, '*')) {
                return;
            }
        }

        $selected = array_map($this->bareColumn(...), $columns);

        foreach ($this->getQuery()->orders ?? [] as $order) {
            $column = $order['column'];
            $bare = $this->bareColumn($column);

            if (in_array($bare, $selected, true)) {
                continue;
            }

            $builder->addSelect($column);
            $selected[] = $bare;
        }
    }

    /**
     * A column name without its table.
     *
     * @param string $column
     * @return string
     */
    protected function bareColumn(string $column): string
    {
        return last(explode('.', $column));
    }

    /**
     * Refuse an order the merge cannot reproduce.
     *
     * compareModels() reads the ordering column off the models, so it can only
     * follow an order that names one. `orderByRaw` records no column at all —
     * reading one produced an undefined key and left the rows in whatever
     * order the shards happened to be visited, which is the quietest kind of
     * wrong. An expression is the same case wearing an object.
     *
     * @param string $method
     * @return void
     *
     * @throws UnsupportedCrossShardQuery
     */
    protected function refuseUnmergeableOrder(string $method): void
    {
        foreach ($this->getQuery()->orders ?? [] as $order) {
            if (isset($order['column']) && is_string($order['column'])) {
                continue;
            }

            throw new UnsupportedCrossShardQuery(sprintf(
                '%s::%s() cannot follow a raw order across shards: the results are merged by comparing the models, '
                . 'so the order has to name a column. Order by a column name, or pin the query with '
                . 'onShardConnection().',
                $this->getModel()::class,
                $method,
            ));
        }
    }

    /**
     * The most rows a single shard can contribute to a bounded read.
     *
     * To produce the global rows [offset, offset + limit) the merge below
     * never needs more than that many rows from any one shard: a row that
     * lands in the global window is preceded there by every row that precedes
     * it on its own shard. So the database can stop counting at the bound
     * instead of returning the table.
     *
     * Null means there is no bound. Without a limit every row after the
     * offset may still be needed, and pdo_pgsql buffers whatever comes back,
     * so an unbounded read of a large shard is exactly as expensive as it
     * looks.
     *
     * @param int|null $limit
     * @param int|null $offset
     * @return int|null
     */
    protected function shardBound(?int $limit, ?int $offset): ?int
    {
        if ($limit === null) {
            return null;
        }

        return max(0, $offset ?? 0) + $limit;
    }

    /**
     * Constrain one shard's query to the rows the merge can still use.
     *
     * The ordering is applied here and not only in compareModels(), because
     * the two have to agree: a limit over an unordered query returns an
     * arbitrary subset, and merging arbitrary subsets gives an arbitrary
     * answer. compareModels() falls back to the primary key when nothing was
     * ordered, so the query does the same.
     *
     * @param self $builder
     * @param int|null $bound
     * @return void
     */
    protected function applyShardBound(self $builder, ?int $bound): void
    {
        if ($bound === null) {
            return;
        }

        if (empty($this->getQuery()->orders)) {
            $builder->orderBy($this->getModel()->getKeyName());
        }

        $builder->limit($bound);
    }

    /**
     * Retrieve models with a global limit and offset across shards.
     *
     * @param int|null $limit
     * @param int|null $offset
     * @param array<int, string> $columns
     * @return \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model>
     */
    protected function getWithLimitAndOffset(?int $limit, ?int $offset, array $columns)
    {
        $iterators = [];
        $bound = $this->shardBound($limit, $offset);

        foreach ($this->connections() as $name => $config) {
            $builder = $this->replicateForConnection($name);
            $this->applySelect($builder, $columns);
            $this->applyShardBound($builder, $bound);
            $iterators[$name] = $builder->cursor()->getIterator();
        }

        return $this->getModel()->newCollection(
            $this->takeFromMerge($this->mergeShardResults($iterators), $limit, $offset),
        );
    }

    /**
     * @inheritdoc
     *
     * The shards are read one row at a time and merged as they come, so the
     * ordering is the query's own rather than shard after shard.
     *
     * It is lazy in the sense the caller cares about — models are hydrated as
     * they are pulled — but note that pdo_pgsql buffers a result set on the
     * client, so this does not make an unbounded read of a large shard cheap.
     * Give the query a limit, or use chunkById() and pay one round trip per
     * chunk.
     */
    public function cursor()
    {
        if ($this->singleConnection) {
            return parent::cursor();
        }

        $this->refuseUnmergeableOrder('cursor');

        return new LazyCollection(function (): Generator {
            $iterators = [];

            foreach ($this->connections() as $name => $config) {
                $iterators[$name] = $this->replicateForConnection($name)->cursor()->getIterator();
            }

            yield from $this->mergeShardResults($iterators);
        });
    }

    /**
     * @inheritdoc
     *
     * Every column is read rather than just the two being plucked: the merge
     * orders by comparing the models, so a query ordered by a column that was
     * not selected would be merged by a property that is null everywhere.
     */
    public function pluck($column, $key = null)
    {
        if ($this->singleConnection) {
            return parent::pluck($column, $key);
        }

        $table = $this->getModel()->getTable();

        // not a static closure: the Expression branch needs the grammar, and
        // reaching for $this in a static one is a fatal the tests would only
        // hit on a raw column
        $name = fn ($value): string => Str::after(
            $value instanceof Expression ? (string) $value->getValue($this->getGrammar()) : (string) $value,
            "{$table}.",
        );

        return $this->get()->pluck($name($column), $key === null ? null : $name($key));
    }

    /**
     * Merge per-shard results into one stream ordered by the query's own order.
     *
     * The shards each answer in order, so the smallest unconsumed row across
     * them is the next row overall — the ordinary merge step, and the reason
     * a fanned-out read costs the page rather than the table.
     *
     * @param array<string, Iterator<int, \Illuminate\Database\Eloquent\Model>> $iterators
     * @return Generator<int, \Illuminate\Database\Eloquent\Model>
     */
    protected function mergeShardResults(array $iterators): Generator
    {
        $current = [];

        foreach ($iterators as $name => $iterator) {
            $iterator->rewind();

            if ($iterator->valid()) {
                $current[$name] = $iterator->current();
            }
        }

        while ($current !== []) {
            $candidate = null;
            $candidateKey = null;

            foreach ($current as $name => $model) {
                if ($candidate === null || $this->compareModels($model, $candidate) < 0) {
                    $candidate = $model;
                    $candidateKey = $name;
                }
            }

            yield $candidate;

            $iterators[$candidateKey]->next();

            if ($iterators[$candidateKey]->valid()) {
                $current[$candidateKey] = $iterators[$candidateKey]->current();
            } else {
                unset($current[$candidateKey]);
            }
        }
    }

    /**
     * Skip the offset and take the limit out of a merged stream.
     *
     * @param Generator<int, \Illuminate\Database\Eloquent\Model> $merged
     * @param int|null $limit
     * @param int|null $offset
     * @return list<\Illuminate\Database\Eloquent\Model>
     */
    protected function takeFromMerge(Generator $merged, ?int $limit, ?int $offset): array
    {
        $skip = max(0, $offset ?? 0);
        $items = [];

        foreach ($merged as $model) {
            if ($skip > 0) {
                $skip--;

                continue;
            }

            $items[] = $model;

            if ($limit !== null && count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /**
     * @inheritdoc
     */
    public function chunk($count, callable $callback)
    {
        if ($this->singleConnection) {
            return parent::chunk($count, $callback);
        }

        foreach ($this->connections() as $name => $config) {
            $builder = $this->replicateForConnection($name);
            $builder->chunk($count, $callback);
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function chunkById($count, callable $callback, $column = null, $alias = null)
    {
        if ($this->singleConnection) {
            return parent::chunkById($count, $callback, $column, $alias);
        }

        foreach ($this->connections() as $name => $config) {
            $builder = $this->replicateForConnection($name);
            $builder->chunkById($count, $callback, $column, $alias);
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function paginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null, $total = null)
    {
        if ($this->singleConnection) {
            return parent::paginate($perPage, $columns, $pageName, $page, $total);
        }

        $this->refuseUnmergeableOrder('paginate');

        $page = $page ?: Paginator::resolveCurrentPage($pageName);
        $perPage = $perPage ?: $this->getModel()->getPerPage();

        $total = $total ?: 0;
        $iterators = [];
        $skip = max(0, ($page - 1) * $perPage);
        $bound = $this->shardBound($perPage, $skip);

        // counted in parallel, like every other fan-out. The cursors are
        // opened afterwards and in order, because they are consumed lazily
        // and outlive the dispatcher that would have created them
        $total += array_sum($this->runOnConnections(
            fn (string $name): int => (int) $this->replicateForConnection($name)->count(),
        ));

        foreach ($this->connections() as $name => $config) {
            // a fresh builder: the count above had to see the whole shard,
            // this one only the rows the page can reach
            $builder = $this->replicateForConnection($name);
            $this->applyShardBound($builder, $bound);
            $iterators[$name] = $builder->cursor()->getIterator();
        }

        $collection = $this->getModel()->newCollection(
            $this->takeFromMerge($this->mergeShardResults($iterators), $perPage, $skip),
        );

        return new LengthAwarePaginator($collection, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    /**
     * @inheritdoc
     *
     * Counts add up, so the shards are counted and the counts are summed.
     * Replicas are excluded by replicateForConnection(), without which a row
     * copied onto a second shard would be counted twice.
     */
    public function count($columns = '*')
    {
        if ($this->singleConnection) {
            return parent::count($columns);
        }

        $this->refuseUncombinableAggregate('count');

        return (int) array_sum($this->runOnConnections(
            fn (string $name): int => (int) $this->replicateForConnection($name)->count($columns),
        ));
    }

    /**
     * @inheritdoc
     */
    public function sum($column)
    {
        if ($this->singleConnection) {
            return parent::sum($column);
        }

        $this->refuseUncombinableAggregate('sum');

        return array_sum($this->runOnConnections(
            fn (string $name) => $this->replicateForConnection($name)->sum($column),
        ));
    }

    /**
     * @inheritdoc
     */
    public function min($column)
    {
        if ($this->singleConnection) {
            return parent::min($column);
        }

        return $this->extremeAcrossShards('min', $column);
    }

    /**
     * @inheritdoc
     */
    public function max($column)
    {
        if ($this->singleConnection) {
            return parent::max($column);
        }

        return $this->extremeAcrossShards('max', $column);
    }

    /**
     * @inheritdoc
     *
     * Deliberately not the average of the averages: that is only the average
     * when every shard holds the same number of rows, and shards do not. The
     * sums and the counts are collected instead and divided once.
     *
     * The divisor counts the column rather than the rows, because SQL AVG
     * ignores nulls and this has to answer what AVG would have answered.
     */
    public function avg($column)
    {
        if ($this->singleConnection) {
            return parent::avg($column);
        }

        $this->refuseUncombinableAggregate('avg');

        $parts = $this->runOnConnections(function (string $name) use ($column): array {
            $builder = $this->replicateForConnection($name);

            return ['sum' => $builder->sum($column), 'count' => (int) $builder->count($column)];
        });

        $counted = array_sum(array_column($parts, 'count'));

        if ($counted === 0) {
            return null;
        }

        return array_sum(array_column($parts, 'sum')) / $counted;
    }

    /**
     * @inheritdoc
     */
    public function average($column)
    {
        return $this->avg($column);
    }

    /**
     * @inheritdoc
     *
     * One shard is enough to answer yes, and every shard has to be asked
     * before answering no.
     */
    public function exists()
    {
        if ($this->singleConnection) {
            return parent::exists();
        }

        $answers = $this->runOnConnections(
            fn (string $name): bool => (bool) $this->replicateForConnection($name)->exists(),
        );

        return in_array(true, $answers, true);
    }

    /**
     * @inheritdoc
     */
    public function doesntExist()
    {
        if ($this->singleConnection) {
            return parent::doesntExist();
        }

        return !$this->exists();
    }

    /**
     * The smallest of the smallest, or the largest of the largest.
     *
     * @param 'min'|'max' $function
     * @param string $column
     * @return mixed
     */
    protected function extremeAcrossShards(string $function, string $column)
    {
        $this->refuseUncombinableAggregate($function);

        $values = array_filter(
            $this->runOnConnections(
                fn (string $name) => $this->replicateForConnection($name)->{$function}($column),
            ),
            static fn ($value): bool => $value !== null,
        );

        if ($values === []) {
            return null;
        }

        return $function === 'min' ? min($values) : max($values);
    }

    /**
     * Refuse an aggregate whose shards cannot be added back together.
     *
     * Everything here has the same shape: the shard's answer is not a part of
     * the whole answer, it is an answer to a different question. Summing those
     * produces a number that looks plausible and is wrong, which is the one
     * outcome worth throwing over.
     *
     * @param string $method
     * @return void
     *
     * @throws UnsupportedCrossShardQuery
     */
    protected function refuseUncombinableAggregate(string $method): void
    {
        $query = $this->getQuery();

        $reason = match (true) {
            !empty($query->groups) => 'a grouped aggregate has one value per group, and a group may have rows on several shards',
            !empty($query->havings) => 'having filters groups, and a group may have rows on several shards',
            $query->distinct !== false => 'a distinct aggregate would count a value once for every shard that holds it',
            !empty($query->unions) => 'a union is resolved by the connection it runs on',
            default => null,
        };

        if ($reason === null) {
            return;
        }

        throw new UnsupportedCrossShardQuery(sprintf(
            '%s::%s() cannot be combined across shards: %s. Give the query its shard key, or pin it with onShardConnection().',
            $this->getModel()::class,
            $method,
            $reason,
        ));
    }

    /**
     * @inheritdoc
     *
     * Runs on every shard and reports the rows all of them touched together.
     * Not one transaction: there is no such thing across connections, and the
     * dispatcher lets every shard attempt the write before surfacing the first
     * failure, so a partial write is possible and is the honest outcome. It
     * beats the previous one, which was to write to a single shard chosen by
     * hashing an invented identifier and report that as the whole job.
     */
    public function update(array $values)
    {
        if ($this->singleConnection) {
            return parent::update($values);
        }

        $this->refuseBoundedWrite('update');
        $this->refuseShardKeyChange($values, 'update');

        return $this->writeAcrossShards(static fn (self $builder): int => (int) $builder->update($values));
    }

    /**
     * @inheritdoc
     *
     * A soft delete stays a soft delete: the per-shard copy is given the
     * model's global scopes, and registering SoftDeletes re-runs its extend(),
     * which is what puts the delete callback back on the copy.
     */
    public function delete()
    {
        if ($this->singleConnection) {
            return parent::delete();
        }

        $this->refuseBoundedWrite('delete');

        return $this->writeAcrossShards(static fn (self $builder): int => (int) $builder->delete());
    }

    /**
     * @inheritdoc
     */
    public function forceDelete()
    {
        if ($this->singleConnection) {
            return parent::forceDelete();
        }

        $this->refuseBoundedWrite('forceDelete');

        return $this->writeAcrossShards(static fn (self $builder): int => (int) $builder->forceDelete());
    }

    /**
     * @inheritdoc
     */
    public function increment($column, $amount = 1, array $extra = [])
    {
        if ($this->singleConnection) {
            return parent::increment($column, $amount, $extra);
        }

        $this->refuseBoundedWrite('increment');
        $this->refuseShardKeyChange([$column => $amount] + $extra, 'increment');

        return $this->writeAcrossShards(
            static fn (self $builder): int => (int) $builder->increment($column, $amount, $extra),
        );
    }

    /**
     * @inheritdoc
     */
    public function decrement($column, $amount = 1, array $extra = [])
    {
        if ($this->singleConnection) {
            return parent::decrement($column, $amount, $extra);
        }

        $this->refuseBoundedWrite('decrement');
        $this->refuseShardKeyChange([$column => $amount] + $extra, 'decrement');

        return $this->writeAcrossShards(
            static fn (self $builder): int => (int) $builder->decrement($column, $amount, $extra),
        );
    }

    /**
     * @inheritdoc
     *
     * Refused rather than fanned out. Every other write here repeats one
     * statement on each shard, which works because the rows a shard owns are
     * the rows it should change. An upsert does not fit that: each row belongs
     * to whichever shard its key hashes to, so the statement would have to be
     * split per row, and the uniqueness it turns on cannot be enforced across
     * connections anyway — the conflicting row may sit on a shard this
     * statement never reaches, and the upsert would insert a duplicate.
     */
    public function upsert(array $values, $uniqueBy, $update = null)
    {
        if ($this->singleConnection) {
            return parent::upsert($values, $uniqueBy, $update);
        }

        throw new UnsupportedCrossShardQuery(sprintf(
            '%s::upsert() cannot be spread across shards: each row belongs to the shard its key hashes to, and the '
            . 'conflicting row may live on a shard this statement never reaches. Save the models one by one, which '
            . 'routes each of them, or pin the call with onShardConnection().',
            $this->getModel()::class,
        ));
    }

    /**
     * Repeat a write on every shard and add up what each of them touched.
     *
     * @param callable(self): int $write
     * @return int
     */
    protected function writeAcrossShards(callable $write): int
    {
        return (int) array_sum($this->runOnConnections(
            fn (string $name): int => $write($this->replicateForConnection($name)),
        ));
    }

    /**
     * Refuse a write the shards would each apply in full.
     *
     * `update ... limit 5` means five rows. Repeated on four shards it means
     * up to twenty, and nothing about the call says so.
     *
     * @param string $method
     * @return void
     *
     * @throws UnsupportedCrossShardQuery
     */
    protected function refuseBoundedWrite(string $method): void
    {
        $query = $this->getQuery();

        if ($query->limit === null && $query->offset === null) {
            return;
        }

        throw new UnsupportedCrossShardQuery(sprintf(
            '%s::%s() cannot carry a limit across shards: every shard would apply it in full, so a limit of %s '
            . 'would touch that many rows per shard. Select the rows first, then write by their keys.',
            $this->getModel()::class,
            $method,
            var_export($query->limit, true),
        ));
    }

    /**
     * Refuse a write that would move a row to another shard.
     *
     * The shard key decides where the row lives. Changing it means deleting
     * the row here and inserting it there, which is not what an UPDATE does:
     * the row would keep sitting on a shard its key no longer points at, and
     * every later read would miss it.
     *
     * @param array<string, mixed> $values
     * @param string $method
     * @return void
     *
     * @throws UnsupportedCrossShardQuery
     */
    protected function refuseShardKeyChange(array $values, string $method): void
    {
        $model = $this->getModel();

        if (!method_exists($model, 'getShardKey')) {
            return;
        }

        $shardKey = $model->getShardKey();

        foreach (array_keys($values) as $column) {
            $name = last(explode('.', (string) $column));

            if ($name !== $shardKey) {
                continue;
            }

            throw new UnsupportedCrossShardQuery(sprintf(
                '%s::%s() cannot change %s: it is the shard key, and the row would have to move to another '
                . 'connection. Delete the row and create it again with the new key.',
                $model::class,
                $method,
                $shardKey,
            ));
        }
    }

    /**
     * @inheritdoc
     */
    public function firstOrCreate(array $attributes = [], Closure|array $values = [])
    {
        if ($instance = $this->firstAcrossConnections($attributes)) {
            return $instance;
        }

        if ($values instanceof Closure) {
            $values = $values();
        }

        return parent::create(array_merge($attributes, $values));
    }

    /**
     * @inheritdoc
     */
    public function updateOrCreate(array $attributes, Closure|array $values = [])
    {
        if ($values instanceof Closure) {
            $values = $values();
        }

        if ($instance = $this->firstAcrossConnections($attributes)) {
            $instance->fill($values);
            $instance->save();

            return $instance;
        }

        return parent::create(array_merge($attributes, $values));
    }

    /**
     * Find the first model across all shard connections.
     *
     * @param array<string, mixed> $attributes
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    protected function firstAcrossConnections(array $attributes)
    {
        foreach ($this->connections() as $name => $config) {
            $builder = $this->replicateForConnection($name);
            $instance = $builder->where($attributes)->first();
            if ($instance) {
                return $instance;
            }
        }

        return null;
    }

    /**
     * Compare two models based on the builder's order clauses.
     *
     * @param \Illuminate\Database\Eloquent\Model $a
     * @param \Illuminate\Database\Eloquent\Model $b
     */
    protected function compareModels($a, $b): int
    {
        $orders = $this->getQuery()->orders ?? [];

        if (!$orders) {
            $orders[] = ['column' => $this->getModel()->getKeyName(), 'direction' => 'asc'];
        }

        foreach ($orders as $order) {
            $column = $order['column'];
            $direction = strtolower($order['direction'] ?? 'asc');

            // the models carry bare attribute names, so orderBy('t.col')
            // has to be compared as 'col' rather than as a property nothing has
            $name = $this->bareColumn($column);

            $result = $a->{$name} <=> $b->{$name};

            if ($result === 0) {
                continue;
            }

            return $direction === 'desc' ? -$result : $result;
        }

        return 0;
    }
}
