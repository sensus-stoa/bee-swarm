<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Search;

/**
 * Регрессия (05.08): NaN/INF векторы проходили exact-match как «закон» CV=0.
 * Причина: abs(NaN - y) > 0.0001 = false (NaN сравнение всегда false).
 * Артефакт: array_product переполнение (R×x0 = INF) → INF×0 = NaN.
 */
class NanGuardTest extends TestCase
{
    public function testNanVectorIsNotExactLaw(): void
    {
        $X = [[1.0], [2.0], [3.0]];
        $y = [2.0, 4.0, 6.0];  // y = 2*x0

        // Вектор с NaN
        $nanVec = [NAN, NAN, NAN];
        $this->assertNotSame(0.0, Search::cv($nanVec, $y),
            'NaN vector must not be exact match (CV=0)');

        // Вектор с INF
        $infVec = [INF, INF, INF];
        $this->assertNotSame(0.0, Search::cv($infVec, $y),
            'INF vector must not be exact match (CV=0)');
    }

    public function testSearchFindRejectsNanOverflow(): void
    {
        // 392 точки как в auto-mpg: произведение переполняется в INF
        $X = [];
        $y = [];
        for ($i = 0; $i < 392; $i++) {
            $X[] = [(float) (50 + ($i % 400))];  // как displacement
            $y[] = (float) (10 + ($i % 45));     // как mpg
        }

        $g = \BeeSwarm\Core\Grammar::fromOps(['+', '×', '−', '/', 'min', 'max', 'sq']);
        [$found, $cv, $formula] = Search::find($X, $y, $g, 2);

        // Если найден закон — CV должен быть конечным и не NaN-артефактом
        if ($found) {
            $this->assertTrue(is_finite($cv), "Law CV must be finite, got: " . var_export($cv, true));
        }
        $this->assertNotSame([true, 0.0], [$found, $cv],
            'NaN overflow must not produce exact CV=0 law');
    }
}
