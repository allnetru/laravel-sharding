<?php

namespace Allnetru\Sharding\Tests\Unit;

use Allnetru\Sharding\IdGenerators\SnowflakeStrategy;
use Allnetru\Sharding\Tests\TestCase;
use InvalidArgumentException;

class SnowflakeStrategyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        SnowflakeStrategy::reset();
    }

    /**
     * The defect this suite exists for: the previous implementation filled the
     * low bits with random values, so a tight loop produced duplicates well
     * before ten thousand ids.
     */
    public function testGeneratesNoDuplicatesUnderTightLoop(): void
    {
        $strategy = new SnowflakeStrategy();
        $ids = [];

        for ($i = 0; $i < 20000; $i++) {
            $ids[] = $strategy->generate(['worker_id' => 1]);
        }

        $this->assertCount(20000, array_unique($ids), 'Snowflake produced duplicate identifiers.');
    }

    public function testIdentifiersAreMonotonicallyIncreasing(): void
    {
        $strategy = new SnowflakeStrategy();
        $previous = 0;

        for ($i = 0; $i < 5000; $i++) {
            $id = $strategy->generate(['worker_id' => 3]);

            $this->assertGreaterThan($previous, $id, 'Identifiers must be sortable.');

            $previous = $id;
        }
    }

    public function testIdentifiersAreDisjointAcrossWorkers(): void
    {
        $first = new SnowflakeStrategy();
        $ids = [];

        // Both workers mint inside the same millisecond window, so only the
        // worker bits keep them apart. This is exactly what the old
        // implementation could not do: it carried no worker identity.
        for ($i = 0; $i < 200; $i++) {
            $ids[] = $first->generate(['worker_id' => 1]);
        }

        SnowflakeStrategy::reset();

        for ($i = 0; $i < 200; $i++) {
            $ids[] = $first->generate(['worker_id' => 2]);
        }

        $this->assertCount(400, array_unique($ids));
    }

    public function testWorkerIdIsEncodedInTheIdentifier(): void
    {
        $strategy = new SnowflakeStrategy();

        $id = $strategy->generate(['worker_id' => 517]);

        $this->assertSame(517, ($id >> 12) & 0x3FF);
    }

    public function testIdentifierFitsSignedSixtyFourBits(): void
    {
        $strategy = new SnowflakeStrategy();

        $id = $strategy->generate(['worker_id' => 1023]);

        $this->assertGreaterThan(0, $id);
        $this->assertLessThanOrEqual(PHP_INT_MAX, $id);
    }

    public function testRejectsWorkerIdAboveRange(): void
    {
        $strategy = new SnowflakeStrategy();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('worker_id must be between 0 and 1023');

        $strategy->generate(['worker_id' => 1024]);
    }

    public function testRejectsNegativeWorkerId(): void
    {
        $strategy = new SnowflakeStrategy();

        $this->expectException(InvalidArgumentException::class);

        $strategy->generate(['worker_id' => -1]);
    }

    public function testRejectsEpochInTheFuture(): void
    {
        $strategy = new SnowflakeStrategy();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('epoch is in the future');

        $strategy->generate([
            'worker_id' => 1,
            'epoch_ms' => (int) ((microtime(true) + 3600) * 1000),
        ]);
    }

    public function testDerivesWorkerIdWhenNotConfigured(): void
    {
        $strategy = new SnowflakeStrategy();

        $first = $strategy->generate([]);
        $second = $strategy->generate([]);

        $this->assertNotSame($first, $second);
        $this->assertSame(($first >> 12) & 0x3FF, ($second >> 12) & 0x3FF);
    }
}
