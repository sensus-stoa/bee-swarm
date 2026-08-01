<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Forager\Forager;
use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\PlateauDetector;

/**
 * Story D2: Hive class (agenda.php → OOP)
 */
class HiveTest extends TestCase
{
    /**
     * Hive можно создать без аргументов (использует defaults)
     */
    public function testHiveConstructsWithDefaults(): void
    {
        $hive = new Hive();
        $this->assertInstanceOf(Hive::class, $hive);
    }

    /**
     * Hive принимает внешние зависимости
     */
    public function testHiveConstructsWithDependencies(): void
    {
        $plateau = new PlateauDetector(50);
        $forager = new Forager();
        $hive = new Hive($plateau, $forager);
        $this->assertInstanceOf(Hive::class, $hive);
    }

    /**
     * tick() возвращает количество задач
     */
    public function testTickReturnsTaskCount(): void
    {
        $hive = new Hive(maxTicks: 1);
        $status = $hive->tick();
        $this->assertIsArray($status);
        $this->assertArrayHasKey('tasks_processed', $status);
        $this->assertGreaterThan(0, $status['tasks_processed']);
    }

    /**
     * run() с maxTicks=1 выполняет ровно 1 тик
     */
    public function testRunWithMaxTicks(): void
    {
        $hive = new Hive(maxTicks: 1);
        $totalTicks = $hive->run();
        $this->assertSame(1, $totalTicks);
    }

    /**
     * S1.11: laws-таблица содержит колонки source_path и content_sample
     */
    public function testLawsTableHasSourceColumns(): void
    {
        $db = \BeeSwarm\Infra\Database::get();
        $cols = $db->query('PRAGMA table_info(laws)')->fetchAll(\PDO::FETCH_ASSOC);
        $names = array_column($cols, 'name');
        $this->assertContains('source_path', $names, 'laws table must have source_path column');
        $this->assertContains('content_sample', $names, 'laws table must have content_sample column');
    }
}
