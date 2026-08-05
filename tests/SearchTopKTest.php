<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Search;
use BeeSwarm\Core\Grammar;

/**
 * SEARCH-TOP-K (ЭКСП-009): на зашумлённых данных y=2x+N(0,0.1) Search::find
 * возвращал ОДНУ R-подгонку (лучшую по train, CV=0.0356), held-out её
 * отклонил (9.99), а закон 2x (CV_holdout=0.004) не был попробован.
 * Фикс: top-K кандидатов, выбор лучшего по held-out, не по train.
 */
class SearchTopKTest extends TestCase
{
    private function noisyData(int $seed, float $sigma, int $n = 20): array
    {
        mt_srand($seed);
        $X = [];
        $y = [];
        for ($i = 0; $i < $n; $i++) {
            $x = 0.1 + 4.9 * $i / ($n - 1);
            $u1 = mt_rand(1, 999999) / 1000000.0;
            $u2 = mt_rand(1, 999999) / 1000000.0;
            $noise = sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
            $X[] = [$x];
            $y[] = 2 * $x + $sigma * $noise;
        }

        return [$X, $y];
    }

    public function testLawFoundOnNoisyData(): void
    {
        [$X, $y] = $this->noisyData(42, 0.1);
        $g = Grammar::fromOps(Grammar::baseOpNames());

        [$found, $cv, $formula, $cvTest] = Search::find($X, $y, $g, 2, null, 0.2, 0.15);

        $this->assertTrue($found, 'law must be found on noisy data');
        $this->assertLessThan(0.10, $cvTest,
            "held-out CV must pass (law 2x exists, CV=0.004); got {$cvTest} formula={$formula}");
        $this->assertNotSame(9.99, $cvTest, 'R-fit must not be the only candidate');
    }

    public function testScaleInvariancePreserved(): void
    {
        // Масштабная инвариантность (ЭКСП-009): CV одинаков на обоих масштабах
        [$X1, $y1] = $this->noisyData(42, 0.1);
        $y1000 = array_map(fn (float $v): float => $v * 1000, $y1);
        $g = Grammar::fromOps(Grammar::baseOpNames());

        [, $cv1] = Search::find($X1, $y1, $g, 2, null, 0.0, 0.15);
        [, $cv1000] = Search::find($X1, $y1000, $g, 2, null, 0.0, 0.15);

        $this->assertEqualsWithDelta($cv1, $cv1000, 0.0001,
            'CV must be scale-invariant (unlike gplearn MSE, ×10 degradation)');
    }

    public function testNoLawOnHeavyNoise(): void
    {
        // CONCERNS (deleg_1ebc06b4): шум без закона — все top-K проваливают
        // held-out → честный отказ (found=false), а не R-подгонка с cv_test.
        // sigma=1.0 (шум 50% амплитуды): отказ. При sigma=0.5 случайная формула
        // проходит 4-точечный тест (CV нестабилен на малом тесте) — граница.
        [$X, $y] = $this->noisyData(99, 1.0);
        $g = Grammar::fromOps(Grammar::baseOpNames());

        [$found, , , $cvTest] = Search::find($X, $y, $g, 2, null, 0.2, 0.15);

        $this->assertFalse($found, 'heavy noise must produce honest rejection');
        $this->assertSame(9.99, $cvTest, 'rejected law must have cv_test=9.99');
    }
}
