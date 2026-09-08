<?php

namespace Allnetru\Sharding\Tests\Unit\Support\Coroutine;

use Allnetru\Sharding\Support\Coroutine\CoroutineDispatcher;
use Allnetru\Sharding\Tests\Stubs\FakeCoroutineDriver;
use Allnetru\Sharding\Tests\TestCase;

/**
 * @covers \Allnetru\Sharding\Support\Coroutine\CoroutineDispatcher
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
        // inside a coroutine, because that is where tasks are dispatched
        // concurrently since v0.3.13 — outside one the dispatcher stays
        // sequential and would touch the driver only to ask
        $fake->inCoroutine = true;
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
        $this->assertSame(0, $fake->runCalls);
        $this->assertSame(2, $fake->createCalls);
    }

    public function testConfiguredDriverResolvedFromClosure(): void
    {
        $fake = new FakeCoroutineDriver();
        // inside a coroutine, because that is where tasks are dispatched
        // concurrently since v0.3.13 — outside one the dispatcher stays
        // sequential and would touch the driver only to ask
        $fake->inCoroutine = true;

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
        $this->assertSame(0, $fake->runCalls);
        $this->assertSame(2, $fake->createCalls);
    }
}
