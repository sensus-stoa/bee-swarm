<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Search;
use BeeSwarm\Core\Grammar;

/**
 * AFFINE-LAWS (ЭКСП-012, рецензия протокола): CV = std(pred/y)/mean(pred/y)
 * не определён при переходе цели через ноль (ratio → INF, знакопеременный).
 * y=x−5, y=2x−10, y=sin(x) НЕ находились. Фикс: сдвиг min(y)−1 при
 * знакопеременной цели → ratio на (pred−shift)/(y−shift) > 0.
 */
class AffineLawsTest extends TestCase
{
    public function testLinearLawWithZeroCrossing(): void
    {
        // y = x − 2: выразимый сдвиг (K2=2), переход через ноль при x=2.
        // (y=x−5 был НЕВЫРАЗИМ: константа 5 вне {1,2} → система находила
        // «пропорциональную тень» k·x с CV≈0 на хвосте — проверка качества!)
        $X = [];
        $y = [];
        for ($i = 1; $i <= 20; $i++) {
            $X[] = [(float) $i];
            $y[] = $i - 2.0;
        }
        $g = Grammar::fromOps(Grammar::baseOpNames());

        [$found, $cv, $formula, $cvTest] = Search::find($X, $y, $g, 2, null, 0.2, 0.15);

        $this->assertTrue($found, 'affine law y=x−2 must be found (ЭКСП-012: y=x−5 было no)');
        $this->assertLessThan(0.10, $cvTest, "cv_test must pass; got {$cvTest} formula={$formula}");
        // КАЧЕСТВО: формула должна быть аффинно-эквивалентна y (не тень k·x)
        $this->assertStringContainsString('K2', $formula, 'formula must contain the expressible shift K2');
    }

    public function testAffineLawWithOffset(): void
    {
        // y = 2x − 2: выразимый аффинный ((x0×K2)−K2), переход через ноль при x=1
        $X = [];
        $y = [];
        for ($i = 1; $i <= 20; $i++) {
            $X[] = [(float) $i];
            $y[] = 2 * $i - 2.0;
        }
        $g = Grammar::fromOps(Grammar::baseOpNames());

        [$found, $cv, $formula, $cvTest] = Search::find($X, $y, $g, 2, null, 0.2, 0.15);

        $this->assertTrue($found, 'affine law y=2x−10 must be found');
        $this->assertLessThan(0.10, $cvTest, "cv_test must pass; got {$cvTest}");
    }

    public function testProportionalLawsNotBroken(): void
    {
        // Регрессия: пропорциональные законы продолжают находиться
        $X = [];
        $y = [];
        for ($i = 1; $i <= 20; $i++) {
            $X[] = [(float) $i];
            $y[] = 2.0 * $i;
        }
        $g = Grammar::fromOps(Grammar::baseOpNames());
        [$found, , , $cvTest] = Search::find($X, $y, $g, 2, null, 0.2, 0.15);
        $this->assertTrue($found, 'proportional law y=2x must still be found');
        $this->assertLessThan(0.10, $cvTest);
    }

    public function testZeroCrossingInHeldout(): void
    {
        // CONCERNS (deleg_9cf56711): testCv БЕЗ shift давал ложный отказ, когда
        // знакопеременность попадала в held-out. Выразимый закон y=x−2,
        // testRatio=0.5: train x∈[1,10] (знакопеременная, shift=−2), тест x∈[11,20].
        $X = [];
        $y = [];
        for ($i = 1; $i <= 20; $i++) {
            $X[] = [(float) $i];
            $y[] = $i - 2.0;
        }
        $g = Grammar::fromOps(Grammar::baseOpNames());
        [$found, , , $cvTest] = Search::find($X, $y, $g, 2, null, 0.5, 0.15);
        $this->assertTrue($found, 'law must be found with testRatio=0.5 (было: ложный отказ)');
        $this->assertLessThan(0.10, $cvTest, 'cv_test must pass with affine shift in testCv');
    }
}
