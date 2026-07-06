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
    /** Hive можно создать с зависимостями */
    public function test_hive_constructs_with_dependencies(): void
    {
        $plateau = new PlateauDetector(50);
        $forager = new Forager();

        $hive = new Hive($plateau, $forager);

        $this->assertInstanceOf(Hive::class, $hive);
    }

    /** tick() выполняет один цикл и возвращает статус */
    public function test_tick_runs_one_cycle(): void
    {
        $plateau = new PlateauDetector(50);
        $forager = new Forager();
        $hive = new Hive($plateau, $forager);

        $status = $hive->tick();

        $this->assertIsArray($status);
        $this->assertArrayHasKey('tasks_processed', $status);
        $this->assertArrayHasKey('discoveries', $status);
    }

    /** Без задач — tick возвращает empty status */
    public function test_tick_with_no_tasks(): void
    {
        $plateau = new PlateauDetector(50);
        $forager = new Forager();
        $hive = new Hive($plateau, $forager, tasks: []);

        $status = $hive->tick();

        $this->assertSame(0, $status['tasks_processed']);
        $this->assertSame(0, $status['discoveries']);
    }

    /** run() запускает главный цикл (только 1 итерацию в тесте) */
    public function test_run_executes_ticks(): void
    {
        $plateau = new PlateauDetector(50);
        $forager = new Forager();
        $hive = new Hive($plateau, $forager, tasks: [], maxTicks: 1);

        $totalTicks = $hive->run();

        $this->assertSame(1, $totalTicks);
    }
}
