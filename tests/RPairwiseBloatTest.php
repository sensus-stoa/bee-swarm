<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;

/**
 * R-PAIRWISE-BLOAT (10.08): R-атомы (Rmaxx0 — константы выборки) попадали
 * в L1-pairwise → квадратичный раздув: 12 фич → 174K форм, 1.6GB на вине.
 * Pairwise ТОЛЬКО по исходным фичам; R-композиции уже в $feats готовыми.
 * Регрессия: find на 12 фичах — лёгкий (быстрый + мало памяти).
 */
class RPairwiseBloatTest extends TestCase
{
    public function testTwelveFeaturesFindIsLight(): void
    {
        // Синтетика 12 фич × 40 строк (как вино по размерности)
        $X = [];
        $y = [];
        for ($i = 0; $i < 40; $i++) {
            $row = [];
            for ($f = 0; $f < 12; $f++) {
                $row[] = ($i * ($f + 1) + $f) % 17;
            }
            $X[] = $row;
            $y[] = 2 * $row[0] + 1;
        }
        $g = Grammar::fromOps(array_merge(Grammar::baseOpNames(), ['add', 'sub', 'mul', 'div']));
        putenv('SEARCH_BEAM_K=10');

        $t0 = microtime(true);
        $memBefore = memory_get_usage(true);
        Search::find($X, $y, $g, 1, null, 0.2, 0.15);
        $elapsed = microtime(true) - $t0;
        // Разница ДО/ПОСЛЕ find — независимо от соседних тестов в воркере
        // (paratest: пик процесса = накоплен другими тестами!).
        $memDelta = (memory_get_usage(true) - $memBefore) / 1048576;

        // FLAKY-FIX (04.09, T4): под -p8 5.07s при пороге 5.0 — запас 0.4% слишком мал.
        // Суть теста — отсутствие R-раздува (разрыв до ~9с), не точное время. Порог 7.0.
        $this->assertLessThan(7.0, $elapsed,
            '12 фич без R-раздува: find ≤ 7с (было ~9с при 1.6GB; 5с локально)');
        $this->assertLessThan(256, $memDelta,
            '12 фич без R-раздува: find аллоцирует ≤ 256MB (было 1.6GB)');
    }
}
