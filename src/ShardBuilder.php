<?php

namespace Allnetru\Sharding;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

/**
 * Eloquent builder that queries across multiple shard connections.
 */
class ShardBuilder extends EloquentBuilder
{
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
     * @param  string  $connection
     * @return EloquentBuilder
     */
    protected function replicateForConnection(string $connection): EloquentBuilder
    {
        $model = $this->getModel()->newInstance([], true)->setConnection($connection);
        $query = clone $this->getQuery();
        $query->connection = $model->getConnection();
        $builder = new EloquentBuilder($query);
        $builder->setModel($model);
        $builder->withoutReplicas();
        $builder->setEagerLoads($this->getEagerLoads());

        return $builder;
    }

    /**
     * @inheritdoc
     */
    public function get($columns = ['*'])
    {
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

        foreach ($this->connections() as $name => $config) {
            $builder = $this->replicateForConnection($name);
            $batches[$name] = $builder->get($columns)->all();
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
     * Retrieve models with a global limit and offset across shards.
     *
     * @param  int|null  $limit
     * @param  int|null  $offset
     * @param  array  $columns
     * @return \Illuminate\Support\Collection
     */
    protected function getWithLimitAndOffset(?int $limit, ?int $offset, array $columns)
    {
        $iterators = [];
        $current = [];

        foreach ($this->connections() as $name => $config) {
            $builder = $this->replicateForConnection($name);
            $iterator = $builder->cursor($columns)->getIterator();
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
        $page = $page ?: Paginator::resolveCurrentPage($pageName);
        $perPage = $perPage ?: $this->getModel()->getPerPage();

        $total = $total ?: 0;
        $iterators = [];
        $current = [];

        foreach ($this->connections() as $name => $config) {
            $builder = $this->replicateForConnection($name);
            $total += $builder->count();
            $iterator = $builder->cursor()->getIterator();
            $iterator->rewind();

            if ($iterator->valid()) {
                $current[$name] = $iterator->current();
                $iterators[$name] = $iterator;
            }
        }

        $skip = max(0, ($page - 1) * $perPage);
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
     */
    public function firstOrCreate(array $attributes = [], array $values = [])
    {
        if ($instance = $this->firstAcrossConnections($attributes)) {
            return $instance;
        }

        return parent::create(array_merge($attributes, $values));
    }

    /**
     * @inheritdoc
     */
    public function updateOrCreate(array $attributes, array $values = [])
    {
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
     * @param  array  $attributes
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
     * @param  \Illuminate\Database\Eloquent\Model  $a
     * @param  \Illuminate\Database\Eloquent\Model  $b
     * @return int
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
