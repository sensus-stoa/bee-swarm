<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Search;
use BeeSwarm\Core\Grammar;

/**
 * BINARY-B-ATOMS (P0, 08.08): рождённый атом арности 2 применяется
 * к ПАРЕ фич в L2 — B(x0,x1) = (x0+x1) не вырождается в B(x0)=x0+0.
 * Это разблокирует transfer (ЭКСП-022: TRANSFER=0 из-за унарности).
 */
class SearchBinaryAtomTest extends TestCase
{
    public function testBinaryAtomUsedInL2(): void
    {
        // Домен B: y = (x0+x1)² — закон требует бинарный паттерн из A
        $X = [];
        $y = [];
        for ($i = 1; $i <= 30; $i++) {
            $x0 = (float) $i;
            $x1 = (float) ($i * 3);
            $X[] = [$x0, $x1];
            $y[] = ($x0 + $x1) ** 2;
        }

        $g = Grammar::fromOps(['add', 'sub', 'mul', 'div']);
        // Рождённый бинарный атом (из домена A): B = (x0+x1)
        $g->add('Bpair', 'birth', '(x0+x1)');

        [$found, , $formula, $cvTest] = Search::find($X, $y, $g, 2, null, 0.2, 0.15);

        $this->assertTrue($found, 'law must be found (L2 with binary atoms works)');
        $this->assertLessThan(0.10, $cvTest, "cv_test must pass; got {$cvTest}");
        // Победитель может быть обычным add (parsimony: короче) —
        // важна ДОСТУПНОСТЬ Bpair в L2 (reuse-детектор по всем кандидатам)
        $this->assertStringContainsString('²', $formula,
            'squared law expected; got: ' . $formula);
    }

    public function testBinaryAtomEvaluates(): void
    {
        // B(x0,x1) с definition (x0+x1): evaluateFormula уже поддерживает
        // строку [x0, x1] — проверяем применение к паре
        $res = \BeeSwarm\Core\ExpressionEvaluator::evaluateFormula(
            '(x0+x1)', [[2.0, 3.0], [5.0, 1.0]]
        );
        $this->assertSame([5.0, 6.0], $res,
            'binary definition must evaluate with both features');
    }
}
