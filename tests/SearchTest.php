<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Search;

use BeeSwarm\Core\Grammar;

class SearchTest extends TestCase
{
    /** CV = 0 когда векторы совпадают */
    public function test_cv_exact_match(): void
    {
        $cv = Search::cv([1, 2, 3], [1, 2, 3]);
        $this->assertSame(0.0, $cv);
    }

    /** CV > 0 когда векторы разные */
    public function test_cv_different(): void
    {
        $cv = Search::cv([1, 2, 3], [10, 0, -5]);
        $this->assertGreaterThan(0.5, $cv);
    }

    /** CV → 0 при пропорциональности */
    public function test_cv_proportional(): void
    {
        $cv = Search::cv([2, 4, 6], [1, 2, 3]);
        $this->assertEqualsWithDelta(0.0, $cv, 0.001);
    }

    /** CV = 9.99 при нулевом среднем */
    public function test_cv_zero_mean(): void
    {
        $cv = Search::cv([0, 0, 0], [1, 2, 3]);
        $this->assertSame(9.99, $cv);
    }

    /** find: AND (x0 × x1) */
    public function test_find_and(): void
    {
        $g = new Grammar();
        $X = [[0, 0], [0, 1], [1, 0], [1, 1]];
        $y = [0, 0, 0, 1];
        [$ok, $cv, $formula] = Search::find($X, $y, $g, 2);
        $this->assertTrue($ok);
        $this->assertSame(0.0, $cv);
    }

    /** find: OR */
    public function test_find_or(): void
    {
        $g = new Grammar();
        $X = [[0, 0], [0, 1], [1, 0], [1, 1]];
        $y = [0, 1, 1, 1];
        [$ok, $cv] = Search::find($X, $y, $g, 2);
        $this->assertTrue($ok);
        $this->assertSame(0.0, $cv);
    }

    /** find: ADD (x0 + x1) */
    public function test_find_add(): void
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

    /** find: MUL */
    public function test_find_mul(): void
    {
        $g = new Grammar();
        $X = [[1, 2], [2, 3], [3, 4]];
        $y = [2, 6, 12];
        [$ok, $cv] = Search::find($X, $y, $g, 2);
        $this->assertTrue($ok);
        $this->assertSame(0.0, $cv);
    }

    /** find: MIN (глубина 3) */
    public function test_find_min_depth3(): void
    {
        $g = new Grammar();
        $X = [[0, 0], [2, 3], [5, 1], [4, 4]];
        $y = [0, 2, 1, 4];
        [$ok, $cv] = Search::find($X, $y, $g, 3);
        $this->assertTrue($ok);
        $this->assertSame(0.0, $cv);
    }

    /** find: константа */
    public function test_find_constant(): void
    {
        $g = new Grammar();
        $X = [[5], [5], [5]];
        $y = [5, 5, 5];
        [$ok, $cv] = Search::find($X, $y, $g, 2);
        $this->assertTrue($ok);
        $this->assertSame(0.0, $cv);
    }

    /** find: однофакторная линейность (x0² = y) */
    public function test_find_square(): void
    {
        $g = new Grammar();
        $X = [[1], [2], [3], [4]];
        $y = [1, 4, 9, 16];
        [$ok, $cv] = Search::find($X, $y, $g, 2);
        $this->assertTrue($ok);
        $this->assertSame(0.0, $cv);
    }

    /** find: XOR (требует MIN/MAX или abs) */
    public function test_find_xor(): void
    {
        $g = new Grammar();
        $X = [[0, 0], [0, 1], [1, 0], [1, 1]];
        $y = [0, 1, 1, 0];
        [$ok, $cv] = Search::find($X, $y, $g, 3);
        // XOR может не найтись с CV=0 но должен дать CV < 0.5
        $this->assertLessThan(0.5, $cv);
    }

    /** find: данных нет → ошибка */
    public function test_find_empty(): void
    {
        $g = new Grammar();
        [$ok, $cv] = Search::find([], [], $g, 2);
        $this->assertFalse($ok);
    }
}
