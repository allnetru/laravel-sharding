<?php

namespace Allnetru\Sharding\Relations\Concerns;

use Allnetru\Sharding\ShardingManager;
use Illuminate\Database\Eloquent\Model;

/**
 * @internal
 */
trait ResolvesShard
{
    /**
     * Switch the relation query to the shard determined by the given key.
     *
     * @param  mixed  $key
     * @return void
     */
    protected function switchConnection(mixed $key): void
    {
        $connection = app(ShardingManager::class)->connectionFor($this->related, $key)[0];

        if (isset($this->throughParent) && $this->throughParent instanceof Model) {
            $this->throughParent->setConnection($connection);
        }

        $this->query->getModel()->setConnection($connection);
        $this->query->getQuery()->connection = $this->query->getModel()->getConnection();
    }
}
