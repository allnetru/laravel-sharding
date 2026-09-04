<?php

namespace Allnetru\Sharding\Relations\Concerns;

use Allnetru\Sharding\ShardingManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneOrManyThrough;

/**
 * @internal
 */
trait ResolvesShard
{
    /**
     * Switch the relation query to the shard determined by the given key.
     *
     * @param mixed $key
     * @return void
     */
    protected function switchConnection(mixed $key): void
    {
        $manager = app(ShardingManager::class);

        // a relation from a sharded model can point at a global table:
        // reference data lives on the default connection. Routing such a query
        // to a shard sends it where the table does not exist, and the failure
        // is confusing rather than obvious, because the strategy silently
        // treats the unknown table as shardable and looks for its slots.
        if (!$manager->isShardable($this->related)) {
            return;
        }

        $connection = $manager->connectionFor($this->related, $key)[0];

        if ($this instanceof HasOneOrManyThrough) {
            $this->throughParent->setConnection($connection);
        }

        $this->query->getModel()->setConnection($connection);
        $this->query->getQuery()->connection = $this->query->getModel()->getConnection();
    }
}
