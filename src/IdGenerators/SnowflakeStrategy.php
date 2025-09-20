<?php

namespace Allnetru\Sharding\IdGenerators;

/**
 * Generate identifiers using a simplified Snowflake algorithm.
 */
class SnowflakeStrategy implements Strategy
{
    /**
     * @inheritdoc
     */
    public function generate(array $config): int
    {
        return (int) ((int) (microtime(true) * 1000) << 16) | random_int(0, 0xffff);
    }
}
