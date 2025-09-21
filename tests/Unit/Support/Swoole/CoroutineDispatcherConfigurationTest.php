<?php

namespace Allnetru\Sharding\Tests\Unit\Support\Swoole;

use Allnetru\Sharding\Support\Swoole\CoroutineDispatcher;
use Allnetru\Sharding\Tests\Stubs\FakeCoroutineDriver;
use Allnetru\Sharding\Tests\TestCase;

/**
 * @covers \Allnetru\Sharding\Support\Swoole\CoroutineDispatcher
 */
class CoroutineDispatcherConfigurationTest extends TestCase
{
    /**
     * @var mixed
     */
    private $originalConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConfig = config('sharding.coroutines');

        CoroutineDispatcher::useDriver(null);
    }

    protected function tearDown(): void
    {
        config()->set('sharding.coroutines', $this->originalConfig);

        if ($this->app->bound(FakeCoroutineDriver::class)) {
            $this->app->forgetInstance(FakeCoroutineDriver::class);
        }

        CoroutineDispatcher::useDriver(null);

        parent::tearDown();
    }

    public function testConfiguredDriverResolvedFromContainer(): void
    {
        $fake = new FakeCoroutineDriver();
        $this->app->instance(FakeCoroutineDriver::class, $fake);

        config()->set('sharding.coroutines', [
            'default' => 'fake',
            'drivers' => [
                'fake' => FakeCoroutineDriver::class,
            ],
        ]);

        $results = CoroutineDispatcher::run([
            'first' => fn (): int => 1,
            'second' => fn (): int => 2,
        ]);

        $this->assertSame(['first' => 1, 'second' => 2], $results);
        $this->assertSame(1, $fake->runCalls);
        $this->assertSame(2, $fake->createCalls);
    }

    public function testConfiguredDriverResolvedFromClosure(): void
    {
        $fake = new FakeCoroutineDriver();

        config()->set('sharding.coroutines', [
            'default' => 'closure',
            'drivers' => [
                'closure' => fn () => $fake,
            ],
        ]);

        $results = CoroutineDispatcher::run([
            'alpha' => fn (): int => 10,
            'beta' => fn (): int => 20,
        ]);

        $this->assertSame(['alpha' => 10, 'beta' => 20], $results);
        $this->assertSame(1, $fake->runCalls);
        $this->assertSame(2, $fake->createCalls);
    }
}
