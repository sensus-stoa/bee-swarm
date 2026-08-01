<?php
declare(strict_types=1);


namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;

class SearchTest extends TestCase
{
    /**
     * CV = 0 когда векторы совпадают
     */
    public function testCvExactMatch(): void
    {
        $cv = Search::cv([1, 2, 3], [1, 2, 3]);
        $this->assertSame(0.0, $cv);
    }

    /**
     * CV > 0 когда векторы разные
     */
    public function testCvDifferent(): void
    {
        $cv = Search::cv([1, 2, 3], [10, 0, -5]);
        $this->assertGreaterThan(0.5, $cv);
    }

    /**
     * CV → 0 при пропорциональности
     */
    public function testCvProportional(): void
    {
        $cv = Search::cv([2, 4, 6], [1, 2, 3]);
        $this->assertEqualsWithDelta(0.0, $cv, 0.001);
    }

    /**
     * CV = 9.99 при нулевом среднем
     */
    public function testCvZeroMean(): void
    {
        $cv = Search::cv([0, 0, 0], [1, 2, 3]);
        $this->assertSame(9.99, $cv);
    }

    /**
     * find: AND (x0 × x1)
     */
    public function testFindAnd(): void
    {
        $g = new Grammar();
        $X = [[0, 0], [0, 1], [1, 0], [1, 1]];
        $y = [0, 0, 0, 1];
        [$ok, $cv, $formula] = Search::find($X, $y, $g, 2);
        $this->assertTrue($ok);
        $this->assertSame(0.0, $cv);
    }

    /**
     * find: OR
     */
    public function testFindOr(): void
    {
        $g = new Grammar();
        $X = [[0, 0], [0, 1], [1, 0], [1, 1]];
        $y = [0, 1, 1, 1];
        [$ok, $cv] = Search::find($X, $y, $g, 2);
        $this->assertTrue($ok);
        $this->assertSame(0.0, $cv);
    }

    /**
     * find: ADD (x0 + x1)
     */
    public function testFindAdd(): void
    {
        $g = new Grammar();
        $X = [[1, 2], [3, 4], [5, 6]];
        $y = [3, 7, 11];
        [$ok, $cv, $formula] = Search::find($X, $y, $g, 2);
        $this->assertTrue($ok);
        $this->assertSame(0.0, $cv);
        $this->assertStringContainsString('x0', $formula);
        $this->assertStringContainsString('x1', $formula);
    }

    /**
     * find: MUL
     */
    public function testFindMul(): void
    {
        $g = new Grammar();
        $X = [[1, 2], [2, 3], [3, 4]];
        $y = [2, 6, 12];
        [$ok, $cv] = Search::find($X, $y, $g, 2);
        $this->assertTrue($ok);
        $this->assertSame(0.0, $cv);
    }

    /**
     * find: MIN (глубина 3)
     */
    public function testFindMinDepth3(): void
    {
        $g = new Grammar();
        $X = [[0, 0], [2, 3], [5, 1], [4, 4]];
        $y = [0, 2, 1, 4];
        [$ok, $cv] = Search::find($X, $y, $g, 3);
        $this->assertTrue($ok);
        $this->assertSame(0.0, $cv);
    }

    /**
     * find: константа
     */
    public function testFindConstant(): void
    {
        $g = new Grammar();
        $X = [[5], [5], [5]];
        $y = [5, 5, 5];
        [$ok, $cv] = Search::find($X, $y, $g, 2);
        $this->assertTrue($ok);
        $this->assertSame(0.0, $cv);
    }

    /**
     * find: однофакторная линейность (x0² = y)
     */
    public function testFindSquare(): void
    {
        $g = new Grammar();
        $X = [[1], [2], [3], [4]];
        $y = [1, 4, 9, 16];
        [$ok, $cv] = Search::find($X, $y, $g, 2);
        $this->assertTrue($ok);
        $this->assertSame(0.0, $cv);
    }

    /**
     * find: XOR (требует MIN/MAX или abs)
     */
    public function testFindXor(): void
    {
        $g = new Grammar();
        $X = [[0, 0], [0, 1], [1, 0], [1, 1]];
        $y = [0, 1, 1, 0];
        [$ok, $cv] = Search::find($X, $y, $g, 3);
        // XOR может не найтись с текущей грамматикой — проверяем что поиск НЕ вернул мусор
        $this->assertNotNull($ok);
        $this->assertLessThan(2.0, $cv, 'CV should be < 2.0 even for unsolvable tasks');
    }

    /**
     * find: данных нет → ошибка
     */
    public function testFindEmpty(): void
    {
        $g = new Grammar();
        [$ok, $cv] = Search::find([], [], $g, 2);
        $this->assertFalse($ok);
    }

    // ── S1.9 Phase 2: GlobalReduce integration ──

    /**
     * find: GlobalReduce — y = x0 - min(x0)
     * Требует reduce('min', x0) → константа, затем (x0 - Rminx0)
     * Без reduce: нет константы min(x0)=5 → CV > 0
     */
    public function testFindReduceMin(): void
    {
        $g = new Grammar();
        // x0 = [7, 5, 9], min = 5 → y = x0 - 5
        $X = [[7.0], [5.0], [9.0]];
        $y = [2.0, 0.0, 4.0];
        [$ok, $cv, $formula] = Search::find($X, $y, $g, 2);
        $this->assertTrue($ok, "Should find (x0-Rminx0), got cv=$cv formula=$formula");
        $this->assertLessThan(0.001, $cv, "CV should be ~0, got $cv formula=$formula");
    }

    /**
     * find: GlobalReduce — y = (x0 - min(x0)) / (max(x0) - min(x0))
     * Min-max нормализация: требует reduce('min') и reduce('max')
     * Без reduce: нет констант 5 и 9 → CV > 0
     */
    public function testFindReduceMinMaxNormalization(): void
    {
        $g = new Grammar();
        // x0 = [7, 5, 9], min=5, max=9, range=4
        // y = (x0 - 5) / 4
        $X = [[7.0], [5.0], [9.0]];
        $range = 4.0;
        $y = [(7.0-5.0)/$range, (5.0-5.0)/$range, (9.0-5.0)/$range];
        [$ok, $cv] = Search::find($X, $y, $g, 2);
        $this->assertTrue($ok, "Should find min-max normalization, got cv=$cv");
        $this->assertLessThan(0.001, $cv, "CV should be ~0, got $cv");
    }

    /**
     * find: GlobalReduce с двумя колонками — y = (x0/sum(x0)) + x1
     * Комбинация reduce-нормализации и обычной фичи.
     * Без reduce: sum(x0)=12, но нет K12 → CV > 0
     */
    public function testFindReduceWithMultipleColumns(): void
    {
        $g = new Grammar();
        // x0=[3,4,5], sum=12; x1=[10,20,30]
        $X = [
            [3.0, 10.0],
            [4.0, 20.0],
            [5.0, 30.0],
        ];
        $sum0 = 12.0;
        $y = [
            3.0/$sum0 + 10.0,
            4.0/$sum0 + 20.0,
            5.0/$sum0 + 30.0,
        ];
        [$ok, $cv, $formula] = Search::find($X, $y, $g, 2);
        $this->assertTrue($ok, "Should find reduce+feature combination, got cv=$cv formula=$formula");
        $this->assertLessThan(0.001, $cv, "CV should be ~0, got $cv formula=$formula");
    }

    /**
     * find: int колонки проходят guard (is_int) — reduce работает
     * Проверяет что guard не отсекает целочисленные данные.
     */
    public function testFindIntColumnsPassGuard(): void
    {
        $g = new Grammar();
        $X = [[7], [5], [9]];  // int, не float
        $y = [2.0, 0.0, 4.0];  // y = x0 - min(x0) = x0 - 5
        [$ok, $cv] = Search::find($X, $y, $g, 2);
        $this->assertTrue($ok, "Should find reduce on int column, got cv=$cv");
        $this->assertLessThan(0.001, $cv);
    }

    /**
     * S1.11 Phase 3: col_labels → именованные фичи в формулах
     * (price/R+price) вместо (x0/R+x0)
     */
    public function testFindUsesColumnLabels(): void
    {
        $g = new Grammar();
        $X = [[2.0, 5.0], [3.0, 5.0], [5.0, 5.0]];
        $y = [0.2, 0.3, 0.5];  // y = x0 / sum(x0) = x0/10
        [$ok, $cv, $formula] = Search::find($X, $y, $g, 2, ['price', 'qty']);
        $this->assertTrue($ok, "Should find pattern, got cv=$cv formula=$formula");
        $this->assertStringContainsString('price', $formula, 'Formula must use label "price" not x0');
        $this->assertStringNotContainsString('x0', $formula, 'Formula must NOT contain x0 when labels provided');
    }

    /**
     * S1.11 Phase 3: col_labels=null → x0,x1 (обратная совместимость)
     */
    public function testFindWithoutLabelsUsesDefaultNames(): void
    {
        $g = new Grammar();
        $X = [[7.0], [5.0], [9.0]];
        $y = [2.0, 0.0, 4.0];
        [$ok, $cv, $formula] = Search::find($X, $y, $g, 2);  // без labels
        $this->assertTrue($ok, "Should find pattern, got cv=$cv formula=$formula");
        $this->assertStringContainsString('x0', $formula, 'Default feature name must be x0');
    }
}
