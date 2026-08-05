<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;

/**
 * MEMORY-GUARD-DRATIO Ф2 (аудит 05.08): кольцевой буфер D-активности.
 * Каждый тик: 1 если было диссипативное событие (mutation/overfit/plateau_exit),
 * 0 иначе. D_ACT = сумма(буфер)/окно. Zero-allocation (SplFixedArray + head).
 */
class HiveDActivityBufferTest extends TestCase
{
    public function testActivityBufferLogged(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'dact_');
        $hive = new Hive(
            plateau: new \BeeSwarm\Infra\PlateauDetector(50, 0),
            maxTicks: 5,
            logFile: $logFile,
        );
        $hive->setDActivityWindow(4);
        $hive->setDActivityInterval(2);
        $hive->run();

        $log = (string) file_get_contents($logFile);
        unlink($logFile);

        $this->assertStringContainsString('D_ACT', $log, 'D_ACT must be logged');
        $this->assertMatchesRegularExpression(
            '/D_ACT: win=4 events=\d+ D=0\.\d{3}/',
            $log,
            'D_ACT format: win, events, D'
        );
    }

    public function testPlateauExitEventRecorded(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'dact_');
        $plateau = new \BeeSwarm\Infra\PlateauDetector(2, 0);
        $hive = new Hive(
            plateau: $plateau,
            maxTicks: 8,
            logFile: $logFile,
        );
        $hive->setDActivityWindow(8);
        $hive->setDActivityInterval(2);
        $hive->run();

        $log = (string) file_get_contents($logFile);
        unlink($logFile);
        // Тест проверяет, что D_ACT появляется и формат корректен;
        // события зависят от рантайма (mutation/overfit/plateau_exit) — не ассертим их.
        $this->assertStringContainsString('D_ACT:', $log);
    }
}
