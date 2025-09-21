<?php

namespace Allnetru\Sharding\Tests\Unit;

use Allnetru\Sharding\Strategies\HashStrategy;
use Allnetru\Sharding\Tests\TestCase;

class HashStrategyTest extends TestCase
{
    public function testLargeKeyDistribution(): void
    {
        $strategy = new HashStrategy();

        $config = [
            'connections' => [
                'shard1' => ['weight' => 1],
                'shard2' => ['weight' => 1],
                'shard3' => ['weight' => 1],
            ],
            'replica_count' => 1,
        ];

        $key = 'key2';

        $expectedHash = (int) sprintf('%u', crc32((string) $key));
        $expectedHash %= 3;
        $names = array_values(array_keys($config['connections']));
        sort($names);
        $primary = $names[$expectedHash];
        $replica = $names[($expectedHash + 1) % 3];

        $this->assertSame([$primary, $replica], $strategy->determine($key, $config));
    }
}
