<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Forager\Forager;
use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\PlateauDetector;

/**
 * Проверка: выборка задач колеблется каждый тик (5→7→6→8...).
 * Wakeup на count($tasks) — если срабатывает при ЛЮБОМ росте выборки,
 * плато не наберёт 500 тиков даже после growth-only фикса.
 */
class HivePlateauWakeupOscillationTest extends TestCase
{
    public function testOscillatingTaskCountDoesNotWakePlateau(): void
    {
        $plateau = new PlateauDetector(50, 0);

        // Forager: каждый скан даёт 15 задач. TEST-HYGIENE (fast2min): было
        // 3 ряда — PRE_FILTER отбрасывал (tMin=10) → пул пуст →
        // plateau->tick() не звался (счётчик заморожен, FAIL при выносе
        // base-кормушки). Теперь 12 рядов π-цифр: фильтр проходит, поиск
        // НЕ находит (нелинейный шум) → счётчик растёт без сбросов.
        $forager = new class() extends Forager {
            private int $scan = 0;

            public function __construct()
            {
                parent::__construct();
            }

            public function scanWithAccumulator(array $dirs): array
            {
                // Имена БЕЗ счётчика скана: дедуп Hive держит пул константным
                // (15) — рост-вакейп срабатывает один раз при первом скане,
                // дальше счётчик плато копит no-discovery тики. (Имена с
                // счётчиком = рост каждый тик = wakeup каждый тик = сброс.)
                $tasks = [];
                $digits = [3, 1, 4, 1, 5, 9, 2, 6, 5, 3, 5, 8];
                $rows = [];
                foreach ($digits as $i => $d) {
                    $rows[] = [$i + 1, (float) $d];
                }
                for ($i = 0; $i < 15; $i++) {
                    $tasks[] = [
                        'name' => "foraged_osc_{$i}_c0c1",
                        'data' => $rows,
                        'domain' => 'test',
                        'source_path' => '/tmp/osc.csv',
                        'col_labels' => ['x', 'y'],
                    ];
                }

                return $tasks;
            }

            public function hasNewContent(): bool
            {
                return false;
            }
        };

        $logFile = tempnam(sys_get_temp_dir(), 'osc_');
        // TEST-HYGIENE (fast2min): base-кормушка (поиск ~1-2с/тик × 220) +
        // сон пустого пула → 3:18. Скан каждый тик (30 growth-событий вместо
        // 2) + maxTicks 30: инвариант тот же — рост выборки не сбрасывает
        // счётчик плато. Не из phpunit.xml — снятие ок.
        putenv('EMPTY_POOL_SLEEP_US=0');
        putenv('NO_BASE_TASKS=1');
        $hive = new Hive($plateau, $forager, maxTicks: 0, logFile: $logFile);
        $hive->run();
        $si = new \ReflectionProperty(Hive::class, 'foragerScanInterval');
        $si->setAccessible(true);
        $si->setValue($hive, 1);
        $prop = new \ReflectionProperty(Hive::class, 'maxTicks');
        $prop->setValue($hive, 30);
        $hive->run();
        putenv('EMPTY_POOL_SLEEP_US');
        putenv('NO_BASE_TASKS');

        // 220 тиков: ~2 скана (100, 200), пул рос. Если плато НЕ набралось
        // хотя бы до 20 — wakeup на росте выборки продолжает мешать.
        $consecutive = $plateau->getConsecutiveNoDiscovery();
        echo "consecutiveNoDiscovery after 220 ticks: {$consecutive}\n";
        $this->assertGreaterThan(
            20,
            $consecutive,
            'Oscillating task count must not reset plateau continuously'
        );
        unlink($logFile);
    }
}
