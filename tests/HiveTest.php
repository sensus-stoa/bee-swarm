<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\PlateauDetector;
use BeeSwarm\Forager\Forager;

/**
 * Story D2: Hive class (agenda.php → OOP)
 */
class HiveTest extends TestCase
{
    /** Hive можно создать без аргументов (использует defaults) */
    public function test_hive_constructs_with_defaults(): void
    {
        $hive = new Hive();
        $this->assertInstanceOf(Hive::class, $hive);
    }

    /** Hive принимает внешние зависимости */
    public function test_hive_constructs_with_dependencies(): void
    {
        $plateau = new PlateauDetector(50);
        $forager = new Forager();
        $hive = new Hive($plateau, $forager);
        $this->assertInstanceOf(Hive::class, $hive);
    }

    /** tick() возвращает количество задач */
    public function test_tick_returns_task_count(): void
    {
        $hive = new Hive(maxTicks: 1);
        $status = $hive->tick();
        $this->assertIsArray($status);
        $this->assertArrayHasKey('tasks_processed', $status);
        $this->assertGreaterThan(0, $status['tasks_processed']);
    }

    /** run() с maxTicks=1 выполняет ровно 1 тик */
    public function test_run_with_max_ticks(): void
    {
        $hive = new Hive(maxTicks: 1);
        $totalTicks = $hive->run();
        $this->assertSame(1, $totalTicks);
    }
}
