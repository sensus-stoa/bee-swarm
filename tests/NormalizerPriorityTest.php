<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\ExpressionNormalizer;
use BeeSwarm\Core\ExpressionEvaluator;

/**
 * NORMALIZER-PRIORITY-BUG (P0, 08.08): a×b+c искажается в (K2+x1×x0).
 * Парсер не различает приоритеты: сначала +/− верхнего уровня,
 * потом ×/÷. Иначе канонические ключи неверны (дедуп/классы ломаются).
 */
class NormalizerPriorityTest extends TestCase
{
    public function testMulAddStructurePreserved(): void
    {
        $result = ExpressionNormalizer::normalize('(x0×K2+x1)');
        // Семантика должна сохраняться: 2x0+x1
        $this->assertNotSame('(K2+x1×x0)', $result,
            'структура a×b+c искажена: ' . $result);
        $this->assertStringContainsString('+', $result,
            'верхнеуровневый + должен сохраниться: ' . $result);
    }

    public function testNormalizationPreservesSemantics(): void
    {
        $X = [[1.0, 3.0], [2.0, 6.0], [3.0, 9.0], [4.0, 12.0]];
        $formula = '(x0×K2+x1)';

        $orig = ExpressionEvaluator::evaluateFormula($formula, $X);
        $norm = ExpressionNormalizer::normalize($formula);
        $normVal = ExpressionEvaluator::evaluateFormula($norm, $X);

        $this->assertNotNull($orig, 'original must evaluate');
        $this->assertNotNull($normVal, 'normalized must evaluate: ' . $norm);
        for ($i = 0; $i < count($X); $i++) {
            $this->assertEqualsWithDelta($orig[$i], $normVal[$i], 1e-6,
                "semantics broken at row {$i}: {$formula} → {$norm}");
        }
    }

    public function testMulDivBeforeAddSub(): void
    {
        // (x0×K2−x1) тоже: верхнеуровневый −
        $result = ExpressionNormalizer::normalize('(x0×K2−x1)');
        $this->assertStringContainsString('−', $result,
            'верхнеуровневый − должен сохраниться: ' . $result);
    }
}
