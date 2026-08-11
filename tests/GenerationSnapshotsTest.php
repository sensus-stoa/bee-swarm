<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\Database;
use BeeSwarm\Infra\PlateauDetector;

/**
 * S1.5 (11.08): ПОКОЛЕНЧЕСКИЕ СНИМКИ — эволюционная динамика в БД.
 * Таблица generation_snapshots (gen, diversity, avg_g, unique_grammars,
 * alive, timestamp) — для verify_1_* (13.08) и анализа монокультуры.
 */
class GenerationSnapshotsTest extends TestCase
{
    private function freshHive(int $maxTicks, string $tag): Hive
    {
        return new Hive(
            plateau: new PlateauDetector(50, plateauSleepUs: 0),
            maxTicks: $maxTicks,
            logFile: tempnam(sys_get_temp_dir(), 'gensnap_' . $tag)
        );
    }

    public function testSnapshotWrittenOnGeneration(): void
    {
        Database::get()->exec('DELETE FROM generation_snapshots');
        $hive = $this->freshHive(0, 'a');
        $hive->run(); // bootstrap
        // ДЕТЕРМИНИЗМ: убиваем всех → SEED_SPAWN → смена поколения
        $ref = new \ReflectionProperty(Hive::class, 'bees');
        foreach ($ref->getValue($hive) as $bee) {
            $k = new \ReflectionProperty($bee, 'energy');
            $k->setValue($bee, 0.0);
        }
        $prop = new \ReflectionProperty(Hive::class, 'maxTicks');
        $prop->setValue($hive, 30);
        $hive->run();

        $rows = Database::get()->query(
            'SELECT gen, diversity, avg_g, unique_grammars, alive FROM generation_snapshots'
        )->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertNotEmpty($rows, 'снимок поколения записан');
        $last = end($rows);
        $this->assertGreaterThanOrEqual(0, (float) $last['gen']);
        $this->assertGreaterThanOrEqual(0.0, (float) $last['diversity']);
        $this->assertLessThanOrEqual(1.0, (float) $last['diversity'],
            'diversity ∈ [0,1]');
        $this->assertGreaterThanOrEqual(1, (int) $last['avg_g'],
            'avg |G| ≥ 1 (пчелы с непустой грамматикой)');
        $this->assertGreaterThanOrEqual(1, (int) $last['unique_grammars']);
        $this->assertGreaterThanOrEqual(0, (int) $last['alive']);
    }

    public function testSnapshotTableHasTimestamp(): void
    {
        Database::get()->exec('DELETE FROM generation_snapshots');
        $hive = $this->freshHive(0, 'b');
        $hive->run();
        $ref = new \ReflectionProperty(Hive::class, 'bees');
        foreach ($ref->getValue($hive) as $bee) {
            $k = new \ReflectionProperty($bee, 'energy');
            $k->setValue($bee, 0.0);
        }
        $prop = new \ReflectionProperty(Hive::class, 'maxTicks');
        $prop->setValue($hive, 30);
        $hive->run();

        $ts = Database::get()->query(
            'SELECT timestamp FROM generation_snapshots LIMIT 1'
        )->fetchColumn();
        $this->assertNotEmpty($ts, 'timestamp в снимке');
    }
}
