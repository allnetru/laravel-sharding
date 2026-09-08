<?php

namespace Allnetru\Sharding;

use Allnetru\Sharding\Exceptions\UnsupportedCrossShardQuery;
use Allnetru\Sharding\Support\Coroutine\CoroutineDispatcher;
use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

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

        $limit = $this->getQuery()->limit;
        $offset = $this->getQuery()->offset;

        if ($limit !== null || $offset !== null) {
            $this->getQuery()->limit = null;
            $this->getQuery()->offset = null;

            return $this->getWithLimitAndOffset($limit, $offset, $columns);
        }

        $results = [];
        $batches = [];
        $indexes = [];

        $batches = $this->runOnConnections(function (string $name, array $config) use ($columns) {
            $builder = $this->replicateForConnection($name);

            return $builder->get($columns)->all();
        });

        foreach ($batches as $name => $items) {
            $indexes[$name] = 0;
        }

        while (true) {
            $candidate = null;
            $candidateKey = null;

            foreach ($batches as $name => $items) {
                $index = $indexes[$name];

                if (!isset($items[$index])) {
                    continue;
                }

                if (!$candidate || $this->compareModels($items[$index], $candidate) < 0) {
                    $candidate = $items[$index];
                    $candidateKey = $name;
                }
            }

            if (!$candidate) {
                break;
            }

            $results[] = $candidate;
            $indexes[$candidateKey]++;
        }

        return $this->getModel()->newCollection($results);
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
        $current = [];
        $bound = $this->shardBound($limit, $offset);

        foreach ($this->connections() as $name => $config) {
            $builder = $this->replicateForConnection($name)->select($columns);
            $this->applyShardBound($builder, $bound);
            /** @var \Generator<int, \Illuminate\Database\Eloquent\Model> $iterator */
            $iterator = $builder->cursor()->getIterator();
            $iterator->rewind();

            if ($iterator->valid()) {
                $current[$name] = $iterator->current();
                $iterators[$name] = $iterator;
            }
        }

        $skip = max(0, $offset ?? 0);
        $items = [];

        while (!empty($current)) {
            $candidate = null;
            $candidateKey = null;

            foreach ($current as $name => $model) {
                if (!$candidate || $this->compareModels($model, $candidate) < 0) {
                    $candidate = $model;
                    $candidateKey = $name;
                }
            }

            if ($skip > 0) {
                $skip--;
            } else {
                $items[] = $candidate;

                if ($limit !== null && count($items) >= $limit) {
                    break;
                }
            }

            $iterators[$candidateKey]->next();

            if ($iterators[$candidateKey]->valid()) {
                $current[$candidateKey] = $iterators[$candidateKey]->current();
            } else {
                unset($current[$candidateKey], $iterators[$candidateKey]);
            }
        }

        return $this->getModel()->newCollection($items);
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

        $page = $page ?: Paginator::resolveCurrentPage($pageName);
        $perPage = $perPage ?: $this->getModel()->getPerPage();

        $total = $total ?: 0;
        $iterators = [];
        $current = [];
        $skip = max(0, ($page - 1) * $perPage);
        $bound = $this->shardBound($perPage, $skip);

        foreach ($this->connections() as $name => $config) {
            // the count has to see the whole shard, the cursor only the rows
            // this page can reach, so they cannot share a builder
            $total += $this->replicateForConnection($name)->count();

            $builder = $this->replicateForConnection($name);
            $this->applyShardBound($builder, $bound);
            /** @var \Generator<int, \Illuminate\Database\Eloquent\Model> $iterator */
            $iterator = $builder->cursor()->getIterator();
            $iterator->rewind();

            if ($iterator->valid()) {
                $current[$name] = $iterator->current();
                $iterators[$name] = $iterator;
            }
        }

        $items = [];

        while (!empty($current)) {
            $candidate = null;
            $candidateKey = null;

            foreach ($current as $name => $model) {
                if (!$candidate || $this->compareModels($model, $candidate) < 0) {
                    $candidate = $model;
                    $candidateKey = $name;
                }
            }

            if ($skip > 0) {
                $skip--;
            } else {
                $items[] = $candidate;
                if (count($items) >= $perPage) {
                    break;
                }
            }

            $iterators[$candidateKey]->next();
            if ($iterators[$candidateKey]->valid()) {
                $current[$candidateKey] = $iterators[$candidateKey]->current();
            } else {
                unset($current[$candidateKey], $iterators[$candidateKey]);
            }
        }

        $collection = $this->getModel()->newCollection($items);

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

            $result = $a->{$column} <=> $b->{$column};

            if ($result === 0) {
                continue;
            }

            return $direction === 'desc' ? -$result : $result;
        }

        return 0;
    }
}
