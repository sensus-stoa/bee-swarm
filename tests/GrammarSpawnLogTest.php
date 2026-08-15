<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\PlateauDetector;

/**
 * VERIFY_1_3 (14.08): SPAWN логирует грамматику (GRAMMAR_SPAWN) —
 * для проверки изоляции грамматик (verify_1_3 SKIP без этого!).
 */
class GrammarSpawnLogTest extends TestCase
{
    public function testSpawnLogsGrammar(): void
    {
        \BeeSwarm\Infra\Database::get()->exec('DELETE FROM bee_persistence');
        $logFile = tempnam(sys_get_temp_dir(), 'gspawn_');
        $hive = new Hive(
            plateau: new PlateauDetector(50, plateauSleepUs: 0),
            maxTicks: 0,
            logFile: $logFile
        );
        $hive->run(); // bootstrap
        // ДЕТЕРМИНИЗМ: убить всех → SEED_SPAWN → GRAMMAR_SPAWN
        $ref = new \ReflectionProperty(Hive::class, 'bees');
        foreach ($ref->getValue($hive) as $bee) {
            $k = new \ReflectionProperty($bee, 'energy');
            $k->setValue($bee, 0.0);
        }
        $prop = new \ReflectionProperty(Hive::class, 'maxTicks');
        $prop->setValue($hive, 30);
        $hive->run();
        $log = (string) file_get_contents($logFile);
        unlink($logFile);

        $this->assertStringContainsString('GRAMMAR_SPAWN', $log,
            'спавн логирует грамматику (verify_1_3!)');

        // ИЗОЛЯЦИЯ: periodic-save пишет bee_persistence — не загрязняем
        // соседние тесты процесса (:memory: общая!)
        \BeeSwarm\Infra\Database::get()->exec('DELETE FROM bee_persistence');
    }
}
