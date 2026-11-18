<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\RouteExecutor;
use PHPUnit\Framework\TestCase;

/**
 * EXP-066 B3.3: L2-BEAM stair-квота (rescue промежуточных форм).
 *
 * ПРЕДСКАЗАНИЕ RED (до фикса): закон I_18_4-класса
 * y = (x0·x1 + x2·x3)/(x0+x2) — глубина-3. Промежуточная
 * ((x0×x1)+(x2×x3)) рождается pair-loop'ом, но замораживается
 * L2-BEAM'ом (cv 0.33 против порога 0.036 на вырожденных min/max) —
 * глубина-3 не собирается, серта нет (certs=0).
 *
 * GREEN: SEARCH_L2_STAIR_K>0 — stair-промежуточные спасаются
 * класс-квотой, доживают до re-entry, глубина-3 собирается, серт есть.
 */
final class L2StairQuotaTest extends TestCase
{
    private function buildData(): array
    {
        $X = []; $y = [];
        for ($i = 0; $i < 200; $i++) {
            $x0 = 1.0 + ($i % 17) * 0.11;
            $x1 = 0.7 + ($i % 13) * 0.13;
            $x2 = 1.3 + ($i % 11) * 0.17;
            $x3 = 0.9 + ($i % 19) * 0.07;
            $X[] = [$x0, $x1, $x2, $x3];
            $y[] = ($x0 * $x1 + $x2 * $x3) / ($x0 + $x2);
        }
        return [$X, $y];
    }

    public function testStairIntermediateSurvivesAndLawCertifies(): void
    {
        putenv('SEARCH_DEBUG=0');
        putenv('TRAIL_MARKS=0');
        putenv('TRAIL_BUDGET_MODE=steps');   // канал: steps, не секунды (0.001-сек канал истощается мгновенно)
        putenv('TRAIL_BUDGET_STEPS=1500');
        putenv('SEARCH_L2_LOOKAHEAD=1'); // EXP-066 B3.3: assembly-potential scoring
        putenv('BEE_TEST_HOOKS=1');
        [$X, $y] = $this->buildData();
        $genome = ['id' => 't_stair', 'parent_id' => '', 'condition' => ['kind' => 'ALWAYS'],
            'budget_fraction' => 0.30, 'max_steps' => 5,
            'actions' => [
                ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'beam10nl', 'depth' => 3],
            ]];
        $res = RouteExecutor::run($genome, $X, $y, ['x0', 'x1', 'x2', 'x3'], 0.001, 0.10, [], 1);
        $this->assertSame(1, (int) (! empty($res['found'])),
            'L2-BEAM stair-квота: глубина-3 закон должен сертифицироваться'
            . ' (промежуточная ((x0×x1)+(x2×x3)) обязана дожить)');
    }
}
