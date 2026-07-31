<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Bee;
use BeeSwarm\Hive\BeeWorker;

/**
 * Story F1 Phase 2: BeeWorker wraps Bee for RoadRunner HTTP handling
 */
class BeeWorkerTest extends TestCase
{
    private const E0 = 10.0;

    public function testWorkerHoldsBee(): void
    {
        $bee = new Bee(['add', 'mul']);
        $worker = new BeeWorker($bee);

        $this->assertSame($bee, $worker->bee());
    }

    public function testStatusReturnsBeeState(): void
    {
        $bee = new Bee(['add', 'mul'], self::E0);
        $worker = new BeeWorker($bee);

        $status = $worker->status();

        $this->assertSame(self::E0, $status['energy']);
        $this->assertSame(['add', 'mul'], $status['grammar']);
        $this->assertTrue($status['alive']);
        $this->assertSame(0, $status['discoveries']);
    }

    public function testStatusReflectsEnergyDrain(): void
    {
        $bee = new Bee(['add'], self::E0);
        $worker = new BeeWorker($bee);

        $bee->tick();
        $bee->chargeSearch();

        $status = $worker->status();
        $this->assertEqualsWithDelta(
            self::E0 - 0.01 - 0.1,
            $status['energy'],
            0.0001,
            'Status must reflect current energy after tick + search'
        );
    }

    /** handleTask изменяет энергию (chargeSearch −0.1, возможно rewardDiscovery +2.0) */
    public function testHandleTaskChargesEnergy(): void
    {
        $bee = new Bee(['add', 'mul'], self::E0);
        $worker = new BeeWorker($bee);

        $result = $worker->handleTask('{"data": [[7,3,5]]}');

        $this->assertTrue($result['accepted']);
        // Energy always changes after search. With discovery: +1.9, without: −0.1.
        $this->assertNotEquals(self::E0, $bee->energy(), 'Task handling must change energy');
    }

    public function testHandleTaskReturnsGrammar(): void
    {
        $bee = new Bee(['add', 'sq'], self::E0);
        $worker = new BeeWorker($bee);

        $result = $worker->handleTask('{"data": [[1,2]]}');

        $this->assertSame(['add', 'sq'], $result['grammar']);
    }
}
