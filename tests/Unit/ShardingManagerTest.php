<?php

namespace Allnetru\Sharding\Tests\Unit;

use Allnetru\Sharding\ShardingManager;
use Allnetru\Sharding\Strategies\Strategy;
use Allnetru\Sharding\Tests\TestCase;
use RuntimeException;

class ShardingManagerTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    private array $baseConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseConfig = [
            'default' => 'fake',
            'strategies' => [
                'fake' => FakeStrategy::class,
            ],
            'connections' => [
                'global-primary' => ['weight' => 1],
                'global-replica' => ['weight' => 1],
            ],
            'tables' => [
                'users' => [
                    'connections' => [
                        'users-primary' => ['weight' => 2],
                        'users-archive' => ['weight' => 1],
                    ],
                    'replica_count' => 1,
                ],
            ],
            'groups' => [
                'user-data' => ['users', 'user_profiles'],
            ],
        ];
    }

    public function testConnectionsForUsesTableSpecificDefinition(): void
    {
        $manager = new ShardingManager($this->baseConfig);

        $this->assertSame(
            $this->baseConfig['tables']['users']['connections'],
            $manager->connectionsFor('users'),
        );
    }

    public function testConnectionsForFallsBackToGlobalConnections(): void
    {
        $manager = new ShardingManager($this->baseConfig);

        $this->assertSame(
            $this->baseConfig['connections'],
            $manager->connectionsFor('invoices'),
        );
    }

    public function testConnectionsForResolvesGroupOwner(): void
    {
        $manager = new ShardingManager($this->baseConfig);

        $this->assertSame(
            $this->baseConfig['tables']['users']['connections'],
            $manager->connectionsFor('user_profiles'),
        );
    }

    public function testConnectionForFiltersMigratingConnectionsBeforeStrategyRuns(): void
    {
        $config = $this->baseConfig;
        $config['tables']['users']['connections']['users-migrating'] = ['weight' => 1];
        $config['migrations'] = ['users-migrating' => true];
        $strategy = new FakeStrategy();
        app()->instance(FakeStrategy::class, $strategy);
        $manager = new ShardingManager($config);

        $connections = $manager->connectionFor('users', 42);

        $this->assertSame(['users-primary', 'users-archive'], $connections);
        $this->assertSame('users', $strategy->lastConfig['table']);
        $this->assertArrayNotHasKey('users-migrating', $strategy->lastConfig['connections']);
    }

    public function testStrategyForThrowsWhenStrategyIsMissing(): void
    {
        $manager = new ShardingManager([
            'default' => 'missing',
            'strategies' => [],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Sharding strategy [missing] not configured.');

        $manager->strategyFor('users');
    }

    public function testGroupForReturnsConfiguredGroup(): void
    {
        $manager = new ShardingManager($this->baseConfig);

        $this->assertSame('user-data', $manager->groupFor('user_profiles'));
        $this->assertNull($manager->groupFor('orders'));
    }
}

class FakeStrategy implements Strategy
{
    /**
     * @var array<string, mixed>
     */
    public array $lastConfig = [];

    public function determine(mixed $key, array $config): array
    {
        $this->lastConfig = $config;

        return array_keys($config['connections'] ?? []);
    }

    public function recordMeta(mixed $key, array $connections, array $config): void
    {
        $this->lastConfig = $config;
    }

    public function recordReplica(mixed $key, string $connection, array $config): void
    {
        $this->lastConfig = $config;
    }

    public function canRebalance(): bool
    {
        return false;
    }

    public function rebalance(string $table, string $key, ?string $from, ?string $to, ?int $start, ?int $end, array $config): int
    {
        $this->lastConfig = $config;

        return 0;
    }
}
