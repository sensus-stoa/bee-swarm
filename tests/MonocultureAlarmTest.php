<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\PlateauDetector;

/**
 * S1.5 фаза 2 (11.08): MONOCULTURE ALARM — diversity < порога →
 * предупреждение в лог (раннее обнаружение сжатия грамматики до |G|=1).
 */
class MonocultureAlarmTest extends TestCase
{
    public function testMonocultureAlarmLogged(): void
    {
        \BeeSwarm\Infra\Database::get()->exec('DELETE FROM generation_snapshots');
        $logFile = tempnam(sys_get_temp_dir(), 'mono_');
        // Все пчёлы получают ОДНУ грамматику → diversity = 1/N → alarm
        putenv('MONOCULTURE_ALARM_DIVERSITY=0.5');
        $hive = new Hive(
            plateau: new PlateauDetector(50, plateauSleepUs: 0),
            maxTicks: 0,
            logFile: $logFile
        );
        $hive->run(); // bootstrap
        // УНИФИКАЦИЯ: все пчёлы получают ОДНУ грамматику (монокультура!)
        $ref = new \ReflectionProperty(Hive::class, 'bees');
        $bees = $ref->getValue($hive);
        foreach ($bees as $bee) {
            $g = new \ReflectionProperty($bee, 'grammar');
            $g->setValue($bee, ['add', 'mul']);
        }
        $prop = new \ReflectionProperty(Hive::class, 'maxTicks');
        $prop->setValue($hive, 105);
        $hive->run();
        $log = (string) file_get_contents($logFile);
        unlink($logFile);

        $this->assertTrue(
            str_contains($log, 'MONOCULTURE'),
            'alarm при низком diversity'
        );
    }
}
