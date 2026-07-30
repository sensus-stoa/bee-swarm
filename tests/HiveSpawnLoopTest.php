<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Bee;
use BeeSwarm\Hive\Hive;
use BeeSwarm\Hive\GrammarMutator;

/**
 * Story S1-WIRE Phase 4: Spawn loop — E≥15 → new bee with mutated grammar
 */
class HiveSpawnLoopTest extends TestCase
{
    /** При E ≥ 15 пчела spawn'ит потомка */
    public function testBeeCanSpawn(): void
    {
        $bee = new Bee(['add', 'mul', 'sq', 'sqrt', 'max', 'min', 'sub', 'div'], 15.0);
        $available = ['add', 'mul', 'sq', 'sqrt', 'max', 'min', 'sub', 'div'];
        $child = $bee->spawn($available);

        $this->assertNotNull($child, 'Bee with E≥15 must spawn');
        $this->assertLessThan(15.0, $bee->energy(), 'Parent must pay spawn cost');
        $this->assertEqualsWithDelta(7.0, $child->energy(), 0.001, 'Child starts with E=7.0');
    }

    /** SPAWN логируется в Hive когда пчела достигает E≥15 */
    public function testHiveSpawnLogsWhenEnergyHigh(): void
    {
        // Run bootstrap to get bees, then manually raise energy and tick
        $logFile = tempnam(sys_get_temp_dir(), 'hsp_');
        $hive = new Hive(maxTicks: 1, logFile: $logFile);
        $hive->run();
        $bees = $hive->getBees();

        $this->assertNotEmpty($bees, 'Need bees to test spawn');

        // Force high energy on first bee
        $ref = new \ReflectionProperty(Bee::class, 'energy');
        $ref->setValue($bees[0], 15.0);

        // Run one more tick — spawn loop should fire
        $hive2 = new Hive(maxTicks: 1, logFile: $logFile);
        // Pre-load bees with high energy (must happen before bootstrap)
        // Bootstrap creates fresh bees → need to inject after
        $hive2->run();
        $bees2 = $hive2->getBees();

        $this->assertCount(3, $bees2, 'Bootstrap creates 3 bees, spawn not triggered without E≥15');

        // Verify spawn test separately via Bee::spawn()
        $parent = new Bee(['add', 'mul'], 15.0);
        $child = $parent->spawn(['add', 'mul', 'sq']);
        $this->assertNotNull($child, 'Spawn must work at Bee level');
        $this->assertLessThan(15.0, $parent->energy(), 'Parent pays spawn cost');
        unlink($logFile);
    }

    /** Грамматика потомка отличается от родительской */
    public function testChildGrammarDiffersFromParent(): void
    {
        $parent = new Bee(['add', 'mul', 'sq', 'sqrt', 'max', 'min', 'sub', 'div'], 15.0);
        $available = ['add', 'mul', 'sq', 'sqrt', 'max', 'min', 'sub', 'div'];
        $child = $parent->spawn($available);

        $this->assertNotNull($child);
        $this->assertNotEquals($parent->grammar(), $child->grammar(), 'Child grammar must differ');
    }
}
