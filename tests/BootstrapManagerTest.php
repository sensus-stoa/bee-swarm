<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\BootstrapManager;
use BeeSwarm\Hive\Bee;

/**
 * Story D14 Phase 1: BootstrapManager — извлечение bootstrap() из Hive.
 */
class BootstrapManagerTest extends TestCase
{
    /**
     * BootstrapManager создаёт 3 seed-пчелы с попарно разными грамматиками.
     *
     * Predicted: FAIL — класс BootstrapManager не существует.
     */
    public function testCreatesThreeSeedBees(): void
    {
        $manager = new BootstrapManager();
        $bees = $manager->createSeedBees();

        $this->assertCount(3, $bees);
        $this->assertInstanceOf(Bee::class, $bees[0]);
        $this->assertInstanceOf(Bee::class, $bees[1]);
        $this->assertInstanceOf(Bee::class, $bees[2]);
    }

    /**
     * Seed-пчёлы имеют попарно разные грамматики.
     *
     * Predicted: FAIL — класс не существует.
     */
    public function testSeedBeesHaveDistinctGrammars(): void
    {
        $manager = new BootstrapManager();
        $bees = $manager->createSeedBees();

        $grammars = array_map(fn (Bee $b) => $b->grammar(), $bees);
        $this->assertNotEquals($grammars[0], $grammars[1], 'G₁ ≠ G₂');
        $this->assertNotEquals($grammars[0], $grammars[2], 'G₁ ≠ G₃');
        $this->assertNotEquals($grammars[1], $grammars[2], 'G₂ ≠ G₃');
    }

    /**
     * Seed-пчёлы имеют начальную энергию 10.0.
     *
     * Predicted: FAIL — класс не существует.
     */
    public function testSeedBeesHaveInitialEnergy(): void
    {
        $manager = new BootstrapManager();
        $bees = $manager->createSeedBees();

        foreach ($bees as $bee) {
            $this->assertSame(10.0, $bee->energy());
        }
    }
}
