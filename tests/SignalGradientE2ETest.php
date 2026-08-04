<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\PlateauDetector;

/**
 * E2E: Foraged pipeline — метрики дают открытия.
 *
 * С сильными фикстурами (R²~0.85) NullCalibrator поднимает epsilon
 * и foraged-задачи производят НАСТОЯЩИЕ открытия (не сигнал).
 *
 * История: тест использовал реальные домашние директории (недетерминированно,
 * улей застревал на logic-дублях и не доходил до foraged за 25 тиков).
 * Теперь: FORAGER_SOURCES и CORPUS_DIRS = tests/fixtures/forager,
 * maxTicks=50 — детерминированная среда.
 */
class SignalGradientE2ETest extends TestCase
{
    private ?string $oldForager = null;

    private ?string $oldCorpus = null;

    protected function setUp(): void
    {
        parent::setUp();
        $fixtures = __DIR__ . '/fixtures/forager';
        $this->oldForager = getenv('FORAGER_SOURCES') ?: null;
        $this->oldCorpus = getenv('CORPUS_DIRS') ?: null;
        putenv('FORAGER_SOURCES=' . $fixtures);
        putenv('CORPUS_DIRS=' . $fixtures . '/journal');
    }

    protected function tearDown(): void
    {
        if ($this->oldForager === null) {
            putenv('FORAGER_SOURCES');
        } else {
            putenv('FORAGER_SOURCES=' . $this->oldForager);
        }
        if ($this->oldCorpus === null) {
            putenv('CORPUS_DIRS');
        } else {
            putenv('CORPUS_DIRS=' . $this->oldCorpus);
        }
        parent::tearDown();
    }

    public function testForagedMetricsProduceDiscoveries(): void
    {
        \BeeSwarm\Infra\Database::get()->exec('DELETE FROM laws');

        $logFile = tempnam(sys_get_temp_dir(), 'foraged_e2e_');
        $plateau = new PlateauDetector(50, plateauSleepUs: 0);
        $hive = new Hive(plateau: $plateau, maxTicks: 50, logFile: $logFile);
        $hive->run();

        $log = file_get_contents($logFile);
        unlink($logFile);

        // Должны быть открытия из foraged-домена
        $foragedDiscoveries = substr_count($log, '[foraged]');
        $this->assertGreaterThan(0, $foragedDiscoveries,
            "Expected ≥1 foraged discovery. Got {$foragedDiscoveries}. Pipeline broken?");
    }
}
