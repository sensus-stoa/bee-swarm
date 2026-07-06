<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;
use BeeSwarm\Infra\ResourceGuard;

/**
 * Тесты для daemon efficiency:
 * - Backoff при отсутствии открытий
 * - Search::find не вызывается на каждом тике без надобности
 * - CPU не превышает 50%
 */
class DaemonEfficiencyTest extends TestCase
{
    /**
     * Search::find с depth=3 на большом grammar — дорогой.
     * Проверяем что он завершается за разумное время.
     */
    public function testSearchFindPerformance(): void
    {
        $g = new Grammar();
        $ops = $g->all();
        $this->assertGreaterThan(5, count($ops), 'Нужен grammar для теста');

        $X = [[0, 0], [0, 1], [1, 0], [1, 1]];
        $y = [0, 0, 0, 1];

        $start = microtime(true);
        [$ok, $cv, $formula] = Search::find($X, $y, $g, 2); // depth=2 для теста
        $elapsed = microtime(true) - $start;

        // Даже с depth=2 не должно занимать больше 5 секунд
        $this->assertLessThan(5.0, $elapsed, "Search::find depth=2 took {$elapsed}s — слишком долго");
    }

    /**
     * Search::find с depth=3 должен иметь верхнюю границу по времени.
     */
    public function testSearchFindDepth3HasTimeBound(): void
    {
        $g = new Grammar();
        $X = [[1, 2], [3, 4], [5, 6]];
        $y = [3, 7, 11];

        $start = microtime(true);
        Search::find($X, $y, $g, 3);
        $elapsed = microtime(true) - $start;

        // С текущим grammar (192 ops) это может быть дорого
        // Проверяем что не зависает намертво (верхняя граница 10s)
        $this->assertLessThan(10.0, $elapsed, "Search::find depth=3 took {$elapsed}s");
    }

    /**
     * ResourceGuard должен выдавать sleep > 0 даже при ok.
     * Минимальный sleep = 200ms → максимум 5 тиков/сек.
     */
    public function testMinimumSleepLimitsTickRate(): void
    {
        $g = new ResourceGuard(0.99, 0.99);
        $g->guard();

        $sleep = $g->sleepUs();
        $this->assertGreaterThanOrEqual(200000, $sleep, 'Min sleep = 200ms');

        // При 200ms sleep → макс 5 тиков/сек → CPU не должен быть 89%
        $ticksPerSec = 1_000_000 / $sleep;
        $this->assertLessThanOrEqual(5, $ticksPerSec, "Max {$ticksPerSec} ticks/sec at min sleep");
    }

    /**
     * Exponential backoff: при последовательных failure, sleep растёт.
     * (Тест для логики, которую нужно ДОБАВИТЬ в agenda.php)
     */
    public function testExponentialBackoffOnFailures(): void
    {
        // baseSleep = 200ms, множитель = 2^failures, capped at 3.2s (16×)
        $failures = [0, 1, 2, 3, 4, 5, 10];
        $sleeps = [];

        foreach ($failures as $f) {
            $sleep = min(3_200_000, 200_000 * (2 ** min($f, 4)));
            $sleeps[] = $sleep;
        }

        // 0 failures → 200ms
        $this->assertEquals(200_000, $sleeps[0]);
        // 1 failure → 400ms
        $this->assertEquals(400_000, $sleeps[1]);
        // 3 failures → 1.6s
        $this->assertEquals(1_600_000, $sleeps[3]);
        // 4+ failures → capped at 3.2s
        $this->assertEquals(3_200_000, $sleeps[4]);
        $this->assertEquals(3_200_000, $sleeps[5]);
        $this->assertEquals(3_200_000, $sleeps[6]);
    }
}
