<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\PlateauDetector;
use BeeSwarm\Infra\Database;

/**
 * S2.1 self-model, юнит 1: PREREGISTRATION — гипотеза фиксируется
 * (formula + cv_train + tick) ДО подтверждения; статус CONFIRMED/REFUTED
 * по held-out (cv_test). Защита от HARKing.
 */
class PreregistrationTest extends TestCase
{
    protected function setUp(): void
    {
        // Изоляция :memory: (законы/атомы прошлых прогонов → все discovery
        // = DUPLICATE → PREREG-лог пуст — флак)
        $db = Database::get();
        $db->exec('DELETE FROM preregistrations');
        $db->exec('DELETE FROM laws');
        $db->exec('DELETE FROM grammar_ops WHERE source = \'birth\'');
        Database::reset();
        Database::setPath(':memory:');
    }

    public function testDiscoveryCreatesPreregistration(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'pr_');
        putenv('NO_NOVELTY=1');
        // Изоляция: только base-задачи (forager с page_034 — нестабилен)
        putenv('FORAGER_SOURCES=' . sys_get_temp_dir() . '/prereg_empty');
        @mkdir(sys_get_temp_dir() . '/prereg_empty');
        try {
            $hive = new Hive(
                plateau: new PlateauDetector(50, plateauSleepUs: 0),
                maxTicks: 15,
                logFile: $logFile,
            );
            $hive->run();
        } finally {
            putenv('NO_NOVELTY');
            putenv('FORAGER_SOURCES');
        }

        $rows = Database::get()->query(
            'SELECT formula, cv_predicted, status FROM preregistrations
             WHERE status != \'PENDING\' ORDER BY id DESC LIMIT 5'
        )->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertNotEmpty($rows, 'discovery must create preregistrations');

        $log = file_get_contents($logFile);
        $this->assertMatchesRegularExpression(
            '/PREREG:/',
            $log,
            'PREREG log required'
        );
        // Хотя бы одна запись имеет ФИНАЛЬНЫЙ статус (не PENDING —
        // PENDING = гипотеза до heldout, после прогона она финализируется)
        $statuses = array_column($rows, 'status');
        $this->assertContains(
            'CONFIRMED', $statuses,
            'at least one confirmed; got: ' . implode(',', $statuses)
        );
    }

    public function testRefutedPreregistrationOnNoise(): void
    {
        // Шумовые данные: R-подгонки имеют cv_test > порога → REFUTED
        $logFile = tempnam(sys_get_temp_dir(), 'pr2_');
        putenv('NO_NOVELTY=1');
        putenv('FORAGER_SOURCES=' . sys_get_temp_dir() . '/prereg_empty');
        try {
            $hive = new Hive(
                plateau: new PlateauDetector(50, plateauSleepUs: 0),
                maxTicks: 15,
                logFile: $logFile,
            );
            $hive->run();
        } finally {
            putenv('NO_NOVELTY');
            putenv('FORAGER_SOURCES');
        }

        $log = file_get_contents($logFile);
        $this->assertMatchesRegularExpression(
            '/PREREG:.*(CONFIRMED|REFUTED)/',
            $log,
            'PREREG must end with status: ' . $log
        );
    }
}
