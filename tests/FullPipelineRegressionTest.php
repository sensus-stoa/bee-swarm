<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\PlateauDetector;

/**
 * Полный регресс-тест пайплайна. 25 тиков, ~10 секунд.
 *
 * Инварианты, которые были сломаны в production:
 * - srand(42) → array_rand детерминизм (1 закон вместо 450)
 * - scanWithAccumulator → 0 открытий (D9 regression)
 * - MAX_CROSS_PAIR=2000 → пул раздут (text_pair flood)
 * - nFeat=0 задачи → фильтр не работал
 *
 * Запускать: vendor/bin/phpunit tests/FullPipelineRegressionTest.php
 */
class FullPipelineRegressionTest extends TestCase
{
    /**
     * Главный инвариант: ≥3 открытий за 25 тиков на чистой БД.
     */
    public function testDiscoveriesMade(): void
    {
        \BeeSwarm\Infra\Database::get()->exec('DELETE FROM laws');
        $hive = $this->runHive(12);

        $laws = \BeeSwarm\Infra\Database::get()->query(
            'SELECT COUNT(*) FROM laws'
        )->fetchColumn();

        $this->assertGreaterThanOrEqual(3, (int) $laws,
            "Expected ≥3 laws. Got {$laws}. srand(42)? scanWithAccumulator?");
    }

    /**
     * Инвариант: открытия в ≥2 доменах (не только logic).
     */
    public function testMultipleDomains(): void
    {
        \BeeSwarm\Infra\Database::get()->exec('DELETE FROM laws');
        $this->runHive(12);

        $domains = \BeeSwarm\Infra\Database::get()->query(
            'SELECT COUNT(DISTINCT domain) FROM laws'
        )->fetchColumn();

        $this->assertGreaterThanOrEqual(2, (int) $domains,
            "Expected ≥2 domains. Got {$domains}. All logic-only?");
    }

    /**
     * Инвариант: пул задач не раздут.
     */
    public function testTaskPoolNotBloated(): void
    {
        \BeeSwarm\Infra\Database::get()->exec('DELETE FROM laws');
        $hive = new Hive(plateau: new PlateauDetector(50, plateauSleepUs: 0), maxTicks: 0);
        $hive->run();
        $ref = new \ReflectionMethod(Hive::class, 'getTasks');
        $tasks = $ref->invoke($hive);

        $this->assertLessThan(500, count($tasks),
            'Task pool bloated: ' . count($tasks) . ' tasks. MAX_CROSS_PAIR=2000?');
    }

    /**
     * Инвариант: нет задач с nFeat=0.
     */
    public function testNoZeroFeatureTasks(): void
    {
        \BeeSwarm\Infra\Database::get()->exec('DELETE FROM laws');
        $hive = new Hive(plateau: new PlateauDetector(50, plateauSleepUs: 0), maxTicks: 0);
        $hive->run();
        $ref = new \ReflectionMethod(Hive::class, 'getTasks');
        $tasks = $ref->invoke($hive);

        $zeroFeat = 0;
        foreach ($tasks as $t) {
            if (! isset($t['data'][0]) || ! is_array($t['data'][0])) continue;
            if (count($t['data'][0]) - 1 < 1) {
                $zeroFeat++;
            }
        }

        $this->assertEquals(0, $zeroFeat,
            "Found {$zeroFeat} tasks with nFeat=0. Filter regression?");
    }

    /**
     * Инвариант: пчёлы живы после 25 тиков.
     */
    public function testBeesAliveAfterTicks(): void
    {
        \BeeSwarm\Infra\Database::get()->exec('DELETE FROM laws');
        $hive = $this->runHive(12);

        $alive = count(array_filter(
            $hive->getBees(),
            fn ($b) => $b->isAlive()
        ));

        $this->assertGreaterThan(0, $alive,
            'All bees dead after 25 ticks');
    }

    private function runHive(int $maxTicks): Hive
    {
        $logFile = tempnam(sys_get_temp_dir(), 'regress_');
        $plateau = new PlateauDetector(50, plateauSleepUs: 0);
        $hive = new Hive(plateau: $plateau, maxTicks: $maxTicks, logFile: $logFile);
        $hive->run();
        unlink($logFile);
        return $hive;
    }
}
