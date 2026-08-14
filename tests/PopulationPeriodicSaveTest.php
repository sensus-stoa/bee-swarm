<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\Database;
use BeeSwarm\Infra\PlateauDetector;

/**
 * POPULATION-PERSISTENCE ПЕРИОДИЧЕСКАЯ (14.08): savePopulation только в
 * shutdown-hook → pkill -9 (SIGKILL) терял ВСЮ популяцию (13 дней!).
 * Фикс: периодическое сохранение (каждые N тиков) — теряем ≤ N тиков.
 */
class PopulationPeriodicSaveTest extends TestCase
{
    public function testBeesPersistedPeriodically(): void
    {
        Database::get()->exec('DELETE FROM bee_persistence');
        $hive = new Hive(
            plateau: new PlateauDetector(50, plateauSleepUs: 0),
            maxTicks: 110,
            logFile: tempnam(sys_get_temp_dir(), 'persist_')
        );
        $hive->run();

        $n = (int) Database::get()->query(
            'SELECT COUNT(*) FROM bee_persistence WHERE is_alive = 1'
        )->fetchColumn();
        $this->assertGreaterThan(0, $n,
            'популяция сохранена ПЕРИОДИЧЕСКИ (не только shutdown!)');

        // ИЗОЛЯЦИЯ: savePopulation DELETE-ит таблицу — не загрязняем
        // соседние тесты процесса (:memory: общая!)
        Database::get()->exec('DELETE FROM bee_persistence');
    }
}
