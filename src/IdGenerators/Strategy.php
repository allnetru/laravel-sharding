<?php

namespace Allnetru\Sharding\IdGenerators;

/**
 * Contract for ID generation strategies.
 */
interface Strategy
{
    /**
     * Generate an identifier for given table.
     *
     * @param  array<string, mixed>  $config
     */
    public function generate(array $config): int;
}
