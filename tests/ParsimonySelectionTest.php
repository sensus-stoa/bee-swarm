<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Search;
use BeeSwarm\Core\Grammar;

/**
 * PARSIMONY-SELECTION (P0, из gplearn): штраф за сложность при выборе
 * из plausible. Тень ((x0−(x0/R+x0))×((x0+R+x0))²) с CV≈0 проигрывает
 * простому закону (x0×K2) — раньше был coin flip.
 */
class ParsimonySelectionTest extends TestCase
{
    public function testSimpleLawBeatsComplexPhantomOnNoisyData(): void
    {
        // Данные: y = 2x + N(0,0.1) (ЭКСП-012: R-тень 0.00796 vs закон 0.00801)
        mt_srand(42);
        $X = [];
        $y = [];
        for ($i = 0; $i < 20; $i++) {
            $x = 0.1 + 4.9 * $i / 19;
            $u1 = mt_rand(1, 999999) / 1000000.0;
            $u2 = mt_rand(1, 999999) / 1000000.0;
            $noise = sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
            $X[] = [$x];
            $y[] = 2 * $x + 0.1 * $noise;
        }
        $g = Grammar::fromOps(Grammar::baseOpNames());

        [$found, , $formula, $cvTest] = Search::find($X, $y, $g, 2, null, 0.2, 0.15);

        $this->assertTrue($found, 'law must be found on noisy data');
        $this->assertLessThan(0.10, $cvTest, 'cv_test must pass');

        // PARSIMONY: формула должна быть простой (длина < 15 после
        // ExpressionNormalizer) и НЕ содержать R-атомы (подгонку).
        // x0, (x0×K2), (x0+x0) — все валидны (y∝x), главное — нет R-блута.
        $len = strlen($formula);
        $hasR = str_contains($formula, 'R+') || str_contains($formula, 'R×');
        $this->assertLessThan(15, $len, "formula must be simple (len<15), got len={$len}: {$formula}");
        $this->assertFalse($hasR, "formula must NOT contain R-atoms (phantom), got: {$formula}");
        $this->assertNotSame('none', $formula);
    }

    public function testProportionalLawsNotBrokenByParsimony(): void
    {
        // Регрессия: чистые законы по-прежнему находятся
        $X = [];
        $y = [];
        for ($i = 1; $i <= 20; $i++) { $X[] = [(float) $i]; $y[] = 2.0 * $i; }
        $g = Grammar::fromOps(Grammar::baseOpNames());
        [$found, , , $cvTest] = Search::find($X, $y, $g, 2, null, 0.2, 0.15);
        $this->assertTrue($found);
        $this->assertLessThan(0.10, $cvTest);
    }
}
