<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Search;
use BeeSwarm\Math\CvCalculator;

/**
 * Y_MAX-AMND (24.09, premortem И-2): относительный eps
 * `0.0001*max(1,|y_i|)` при |y|~1e6 даёт допуск ~100 абсолютных единиц —
 * крупно-магнитудные законы с аддитивным bias нефальсифицируемы
 * exact-check'ом (внутри обучающего диапазона; вне — врёт на малых y).
 *
 * Амондмент (юзер, 24.09): потолок абсолютной компоненты —
 * `0.0001 * max(1.0, min(|y_i|, Y_MAX))`, Y_MAX=1e6. Формула инвариантна
 * ниже потолка и НЕ ДАЁТ допуску расти дальше — закон с остатком 200 при
 * y=1e6 обязан отвергаться обоими источниками.
 */
final class YMaxClampTest extends TestCase
{
    /**
     * Ниже потолка — прежняя EXP-036 семантика (не регрессировать): точный
     * закон 1e4·f(x) с остатком 0.5 = 0.0001·1e4·0.5 = в допуске → exact.
     */
    public function testBelowClampKeepsExp036Semantics(): void
    {
        $y = [];
        $vec = [];
        for ($i = 0; $i < 6; $i++) {
            $y[] = 1.0e4 * (2.0 + $i);
            $vec[] = $y[$i] + 0.5; // относительный остаток 5e-5 < 1e-4
        }

        $this->assertSame(0.0, Search::cv($vec, $y));
        $this->assertSame(0.0, CvCalculator::compute($vec, $y));
    }

    /**
     * Пин-граница: y=1e6 (ровно Y_MAX), остаток РОВНО 0.0001·1e6 = 100 —
     * допускается (строгое неравенство); 100.0001 — отвергается.
     */
    public function testClampBoundaryPinAt1e6(): void
    {
        $y = [];
        $vecIn = [];
        $vecOut = [];
        for ($i = 0; $i < 6; $i++) {
            $y[] = 1.0e6 * (2.0 + $i);
            $vecIn[] = $y[$i] + 100.0;        // ровно граница → exact
            $vecOut[] = $y[$i] + 100.0001;    // за границей → не exact
        }

        $this->assertSame(0.0, Search::cv($vecIn, $y), 'граница допуска = exact (строгое неравенство)');
        $this->assertSame(0.0, CvCalculator::compute($vecIn, $y));
        $this->assertGreaterThan(0.0, Search::cv($vecOut, $y), 'остаток 100.0001 при y~1e6+ = не exact');
        $this->assertGreaterThan(0.0, CvCalculator::compute($vecOut, $y));
    }

    /**
     * ГЛАВНЫЙ кейс амондмента: остаток ВДВОЕ больше допуска потолка при
     * |y| > Y_MAX обязан отвергаться (без клэмпа — прошёл бы как exact:
     * 200 < 0.0001·2e6).
     */
    public function testAdditiveBiasBeyondClampRejected(): void
    {
        $y = [];
        $vec = [];
        for ($i = 0; $i < 6; $i++) {
            $y[] = 2.0e6 * (2.0 + $i); // > Y_MAX
            $vec[] = $y[$i] + 200.0;   // 2× допуска клэмпа
        }

        $this->assertGreaterThan(0.0, Search::cv($vec, $y), 'аддитивный bias 200 при y~2e6 отвергнут (clamp работает)');
        $this->assertGreaterThan(0.0, CvCalculator::compute($vec, $y));
    }
}
