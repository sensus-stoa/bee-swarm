<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\AtomRegistry;
use BeeSwarm\Core\ComposeBridge;
use BeeSwarm\Core\ExpressionEvaluator;
use BeeSwarm\Core\ExpressionNormalizer;

/**
 * DREAM-HARDENING WU-3b (prereg 29.09): round-trip матрица по ВСЕМ унарным
 * суффиксам моста. Каждый мост-выход проходит parse+normalize+eval;
 * инвариант — ни один не падает и не возвращает null.
 *
 * До расширения SUFFIXED_UNARY мост ведёт только в 'sq' (²) — остальные 6
 * суффиксов fail-closed (null) → RED.
 *
 * Питфолл prereg: матрица гоняется на чистом процессе (мемоизация normalize
 * 4096 + def-cache evaluator'а) — resetCaches в setUp.
 */
class ComposeBridgeUnaryMatrixTest extends TestCase
{
    private const SUFFIXES = ['sq', 'sqrt', 'parity', 'log2', 'abs', 'neg', 'inv'];

    protected function setUp(): void
    {
        ExpressionEvaluator::resetCaches();
        AtomRegistry::clearDefCache();
    }

    /**
     * Строго положительные x0, x1: sqrt определён, inv без нуля,
     * log2 без нуля, parity детерминирован.
     */
    private function rows(): array
    {
        return [[2.0, 3.0], [4.0, 1.0], [5.0, 2.0]];
    }

    public function testBridgeCoversAllUnarySuffixes(): void
    {
        foreach (self::SUFFIXES as $suffix) {
            $infix = ComposeBridge::toInfix($suffix . '(+)');
            $this->assertNotNull(
                $infix,
                "Мост обязан переводить {$suffix}(+) (prereg WU-3b), получен null"
            );
            $expected = '((x0+x1))' . ($suffix === 'sq' ? '²' : $suffix);
            $this->assertSame($expected, $infix, "Мост {$suffix}(+) даёт неверную форму");
        }
    }

    public function testRoundTripMatrixParseNormalizeEval(): void
    {
        foreach (self::SUFFIXES as $suffix) {
            $infix = ComposeBridge::toInfix($suffix . '(+)');
            $this->assertNotNull($infix, "{$suffix}: мост вернул null");

            $canonical = ExpressionNormalizer::normalize($infix);
            $this->assertIsString($canonical, "{$suffix}: normalize вернул не-строку");
            $this->assertNotSame('', $canonical, "{$suffix}: пустой канон");
            $this->assertSame(
                $canonical,
                ExpressionNormalizer::normalize($canonical),
                "{$suffix}: канон не идемпотентен"
            );

            $pred = ExpressionEvaluator::evaluateFormula($canonical, $this->rows(), []);
            $this->assertNotNull($pred, "{$suffix}: eval вернул null на '{$canonical}'");
            $this->assertCount(3, $pred, "{$suffix}: неверное число предсказаний");
            foreach ($pred as $i => $v) {
                $this->assertNotNull($v, "{$suffix}: pred[{$i}] = null");
                $this->assertIsFloat($v, "{$suffix}: pred[{$i}] не float");
            }
        }
    }

    public function testBinaryOuterStaysFailClosed(): void
    {
        // F4/nFeat-семантика: бинарный outer не мостится — расширение унарных
        // НЕ должно случайно открыть бинарные формы.
        $this->assertNull(ComposeBridge::toInfix('×(+)'));
        $this->assertNull(ComposeBridge::toInfix('max(+)'));
        $this->assertNull(ComposeBridge::toInfix('sq(x0(+'));
    }
}
