<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\PlateauDetector;

/**
 * DEAD-CLEANUP (10.08): мёртвые пчёлы накапливаются в $this->bees
 * (254 за 5ч — утечка памяти/метрик). Удаление каждые 100 тиков.
 */
class DeadCleanupTest extends TestCase
{
    public function testDeadBeesRemovedFromArray(): void
    {
        // TEST-HYGIENE (fast2min): 105 тиков нужны по семантике (cleanup на
        // tick%100===0), но дефолтный forager-фолбэк (500MB) + base-кормушка
        // (поиск ~1-2с/тик) делали файл 2:50. Голодная среда: тики дешёвые,
        // метаболизм/смерть/cleanup живут. Не из phpunit.xml — снятие ок.
        putenv('FORAGER_SOURCES=:');
        putenv('EMPTY_POOL_SLEEP_US=0');
        putenv('NO_BASE_TASKS=1');
        // Улей с убитой пчелой (E=0) — после тика 100 она удаляется
        $logFile = tempnam(sys_get_temp_dir(), 'dead_');
        $hive = new Hive(
            plateau: new PlateauDetector(50, plateauSleepUs: 0),
            maxTicks: 0,
            logFile: $logFile
        );
        $hive->run(); // bootstrap (maxTicks=0: только инициализация!)
        // Убиваем первую пчелу ПОСЛЕ bootstrap
        $ref = new \ReflectionProperty(Hive::class, 'bees');
        $bees = $ref->getValue($hive);
        $this->assertGreaterThan(0, count($bees), 'bootstrap создал пчёл');
        $kill = new \ReflectionProperty($bees[0], 'energy');
        $kill->setValue($bees[0], 0.0);
        // Второй прогон: 105 тиков — удаление мёртвых каждые 100
        $prop = new \ReflectionProperty(Hive::class, 'maxTicks');
        $prop->setValue($hive, 105);
        $hive->run();
        putenv('FORAGER_SOURCES');
        putenv('EMPTY_POOL_SLEEP_US');
        putenv('NO_BASE_TASKS');
        unlink($logFile);

        $bees = $ref->getValue($hive);
        $dead = array_filter($bees, fn ($b) => ! $b->isAlive());
        $this->assertCount(
            0,
            $dead,
            'мёртвые пчёлы удалены из массива после 100 тиков: ' . count($dead) . ' осталось'
        );
        $this->assertGreaterThan(
            0,
            count($bees),
            'живые пчёлы сохранены (cleanup не вычистил всех)'
        );

        // CONCERNS deleg_3fd00053: persistence-путь — save → load:
        // мёртвые не возвращаются (is_alive=0 в таблице, load читает живых)
        $hive->savePopulation();
        $load = new \ReflectionMethod(Hive::class, 'loadPopulation');
        $loaded = $load->invoke($hive) ?? [];
        $deadLoaded = array_filter($loaded, fn ($b) => ! $b->isAlive());
        $this->assertCount(
            0,
            $deadLoaded,
            'мёртвые не восстанавливаются из bee_persistence'
        );
        $this->assertGreaterThan(0, count($loaded), 'живые восстанавливаются');

        // ОЧИСТКА (11.08): savePopulation пишет в :memory: — без DELETE
        // следующие тесты процесса видят RESTORE (загрязнение!)
        \BeeSwarm\Infra\Database::get()->exec('DELETE FROM bee_persistence');
    }
}
