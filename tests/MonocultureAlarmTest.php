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
        // TEST-HYGIENE (fast2min): forager-фолбэк тянул 500MB корпуса из
        // домашнего dirs ноута, пустой пул спал 1с/тик → 7:37. Оба env
        // читаются в конструкторе Hive; снятие в finally (M3: утечка env
        // при раннем фейле = порядокозависимый suite).
        putenv('FORAGER_SOURCES=:');
        putenv('EMPTY_POOL_SLEEP_US=0');
        // Agent-review M2: NO_BASE_TASKS — тики без поисков (мс), что
        // позволяет покрыть ОБЕ ветки каденса: tick===1 И %100===0 (тик 100;
        // 105 тиков старого прогона покрывали только tick===1: 101%100≠0).
        putenv('NO_BASE_TASKS=1');
        // Все пчёлы получают ОДНУ грамматику → diversity = 1/N → alarm
        putenv('MONOCULTURE_ALARM_DIVERSITY=0.5');
        try {
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
            // Agent-review M2: 101 тик — покрывает tick===1 И %100===0.
            $prop->setValue($hive, 101);
            $hive->run();
        } finally {
            putenv('FORAGER_SOURCES');
            putenv('EMPTY_POOL_SLEEP_US');
            putenv('NO_BASE_TASKS');
            putenv('MONOCULTURE_ALARM_DIVERSITY');
        }
        $log = (string) file_get_contents($logFile);
        unlink($logFile);

        $this->assertTrue(
            str_contains($log, 'MONOCULTURE'),
            'alarm при низком diversity'
        );
    }
}
