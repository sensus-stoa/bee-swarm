<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\ExpressionEvaluator;
use BeeSwarm\Core\ExpressionNormalizer;

/**
 * EVALUATOR-OP-NAMES (P0, 08.08): парсер не знает имена операций
 * add/sub/mul/div → heldout отклоняет ВСЕ не-exact композиции (9.99).
 * ЭКСП-020b находил только exact (CV=0, до heldout); с шумом —
 * add-формулы REFUTED. Блокер transfer.
 */
class EvaluatorOpNamesTest extends TestCase
{
    public function testAddNameEvaluates(): void
    {
        $X = [[1.0, 5.0], [2.0, 7.0], [3.0, 9.0]];
        $r = ExpressionEvaluator::evaluateFormula('(x0addx1)', $X);
        $this->assertNotNull($r, '(x0addx1) must parse');
        $this->assertEqualsWithDelta([6.0, 9.0, 12.0], $r, 1e-9);
    }

    public function testMulNameEvaluates(): void
    {
        $X = [[1.0, 5.0], [2.0, 7.0]];
        $r = ExpressionEvaluator::evaluateFormula('(x0mulx1)', $X);
        $this->assertNotNull($r, '(x0mulx1) must parse');
        $this->assertEqualsWithDelta([5.0, 14.0], $r, 1e-9);
    }

    public function testNormalizerKeepsAddStructure(): void
    {
        $n = ExpressionNormalizer::normalize('(x0addx1)');
        $this->assertStringContainsString('add', $n,
            'normalize must keep add: ' . $n);
    }

    public function testMulAddChainPriority(): void
    {
        // CONCERNS deleg_26879775: (x0mulx1addx2) = (x0*x1)+x2
        // (mul выше add), НЕ x0*(x1+x2)
        $X = [[10.0, 4.0, 3.0]];
        $r = ExpressionEvaluator::evaluateFormula('(x0mulx1addx2)', $X);
        $this->assertNotNull($r);
        $this->assertEqualsWithDelta([43.0], $r, 1e-9,
            'mul must bind before add: ' . json_encode($r));
    }

    public function testMixedNotationConsistent(): void
    {
        // CONCERNS deleg_aa12a1ff: − и sub в одном тире →
        // a−b sub c == a sub b−c (слева-направо), не рассинхрон
        $X = [[10.0, 4.0, 2.0]];
        $r1 = ExpressionEvaluator::evaluateFormula('(x0−x1subx2)', $X);
        $r2 = ExpressionEvaluator::evaluateFormula('(x0subx1−x2)', $X);
        $this->assertNotNull($r1);
        $this->assertNotNull($r2);
        // ОБА = 8 = 10−(4−2): правая ассоциация, ГЛАВНОЕ — СОГЛАСОВАННОСТЬ
        // форматов (символ+слово в одном тире, CONCERNS deleg_aa12a1ff)
        $this->assertEqualsWithDelta([8.0], $r1, 1e-9, 'symbol-word: ' . json_encode($r1));
        $this->assertEqualsWithDelta([8.0], $r2, 1e-9, 'word-symbol: ' . json_encode($r2));
    }
}
