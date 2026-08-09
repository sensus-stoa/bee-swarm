<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Search;
use BeeSwarm\Core\Grammar;

/**
 * SEARCH-L2L1 (09.08, ЭКСП-022d): L3 = L2 op L1 — композиции второго
 * уровня. Без них (x0+x1)×x2 невыразим → transfer-тест был невалиден
 * (целевой закон не находится в принципе).
 */
class SearchL2L1Test extends TestCase
{
    public function testL2TimesFeatureFound(): void
    {
        // Домен B: y = (x0+x1)×x2 — требует L2×L1 (второй уровень)
        $X = [];
        $y = [];
        for ($i = 1; $i <= 30; $i++) {
            $x0 = (float) $i;
            $x1 = (float) (rand(3, 40)); // НЕЗАВИСИМ от x0 (иначе тень 4x0x2)
            $x2 = (float) (rand(2, 7));
            $X[] = [$x0, $x1, $x2];
            $y[] = ($x0 + $x1) * $x2;
        }

        $g = Grammar::fromOps(['add', 'sub', 'mul', 'div']);

        [$found, , $formula, $cvTest] = Search::find($X, $y, $g, 3, null, 0.2, 0.15);

        $this->assertTrue($found, 'two-level law must be found');
        $this->assertLessThan(0.10, $cvTest, "cv_test must pass; got {$cvTest}");
        $this->assertStringContainsString('mul', $formula,
            'formula must combine levels; got: ' . $formula);
    }

    public function testL2PlusFeatureFound(): void
    {
        // y = (x0−x1)+x2 — L2 + L1
        $X = [];
        $y = [];
        for ($i = 1; $i <= 30; $i++) {
            $x0 = (float) ($i + 5);
            $x1 = (float) $i;
            $x2 = (float) rand(1, 20);
            $X[] = [$x0, $x1, $x2];
            $y[] = ($x0 - $x1) + $x2;
        }

        $g = Grammar::fromOps(['add', 'sub', 'mul', 'div']);

        [$found, , , $cvTest] = Search::find($X, $y, $g, 3, null, 0.2, 0.15);

        $this->assertTrue($found, 'L2+L1 law must be found');
        $this->assertLessThan(0.10, $cvTest, "cv_test must pass; got {$cvTest}");
    }
}
