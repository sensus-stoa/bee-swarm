<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;

/**
 * MEMORY-GUARD (аудит 05.08): предохранитель памяти после OOM 04.08
 * (7710MB/8156MB). При memory_get_usage(true) > порога → gc_collect_cycles()
 * + лог MEM_GUARD. Порог инъектируем (тест), default 256MB.
 */
class HiveMemoryGuardTest extends TestCase
{
    public function testLowThresholdTriggersGuardLog(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'memguard_');
        // Порог 1MB — PHPUnit уже ест больше → guard обязан сработать
        $hive = new Hive(
            plateau: new \BeeSwarm\Infra\PlateauDetector(50, 0),
            maxTicks: 1,
            logFile: $logFile,
        );
        $hive->setMemoryGuardMb(1);
        $hive->run();

        $log = (string) file_get_contents($logFile);
        unlink($logFile);
        $this->assertStringContainsString('MEM_GUARD', $log, 'guard must log at low threshold');
    }

    public function testDisabledGuardSilent(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'memguard_');
        // Порог 0 = guard выключен — MEM_GUARD не должен появиться
        $hive = new Hive(
            plateau: new \BeeSwarm\Infra\PlateauDetector(50, 0),
            maxTicks: 1,
            logFile: $logFile,
        );
        $hive->setMemoryGuardMb(0);
        $hive->run();

        $log = (string) file_get_contents($logFile);
        unlink($logFile);
        $this->assertStringNotContainsString('MEM_GUARD', $log, 'disabled guard must stay silent');
    }
}
