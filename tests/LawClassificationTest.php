<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;

/**
 * V0.8.5: Law Classification Gate.
 */
class LawClassificationTest extends TestCase
{
    /**
     * ADD-данные: закон найден, testRatio=0 → EMPIRICAL.
     */
    public function testAddLawIsEmpiricalWithoutSplit(): void
    {
        $X = [[1, 2], [3, 4], [5, 6], [7, 8], [9, 10],
              [2, 5], [4, 1], [6, 3], [8, 7], [10, 0]];
        $y = [3, 7, 11, 15, 19, 7, 5, 9, 15, 10];

        $g = Grammar::fromOps(array_merge(Grammar::baseOpNames(), ['add']));
        $result = Search::find($X, $y, $g, 2, ['x0', 'x1']);

        $this->assertCount(5, $result);
        $this->assertTrue($result[0], 'Should find ADD law');
        $this->assertLessThan(0.01, $result[1], 'cv_train ≈ 0');
        $this->assertStringContainsString('x0', $result[2], 'formula contains x0');
        $this->assertSame('EMPIRICAL', $result[4], 'class=EMPIRICAL');
    }

    /**
     * С testRatio > 0: cv_test может отличаться от cv_train.
     */
    public function testSplitProducesDifferentCv(): void
    {
        // Данные с известным законом ADD (x0+x1=y) + шум
        $X = []; $y = [];
        for ($i = 0; $i < 20; $i++) {
            $x0 = (float) ($i + 1);
            $x1 = (float) ($i * 2 + 3);
            $noise = 1.0 + sin($i * 0.5) * 0.1;
            $X[] = [$x0, $x1];
            $y[] = ($x0 + $x1) * $noise;
        }

        $g = Grammar::fromOps(array_merge(Grammar::baseOpNames(), ['add']));
        $result = Search::find($X, $y, $g, 2, ['x0', 'x1'], testRatio: 0.2);

        $this->assertCount(5, $result);
        if ($result[0]) {
            // cv_test вычислен (даже если неудачно — важно что split не упал)
            $this->assertIsFloat($result[3]);
            $this->assertNotEmpty($result[4]);
            // cv_train должно быть разумным
            $this->assertLessThan(1.0, $result[1]);
        }
    }

    /**
     * Без testRatio: cv_test = cv_train (no split).
     */
    public function testNoSplitReturnsSameCv(): void
    {
        $X = [[1, 2], [3, 4], [5, 6], [7, 8], [9, 10], [2, 5], [4, 1], [6, 3], [8, 7], [10, 0]];
        $y = [3, 7, 11, 15, 19, 7, 5, 9, 15, 10];

        $g = Grammar::fromOps(array_merge(Grammar::baseOpNames(), ['add']));
        $result = Search::find($X, $y, $g, 2, ['x0', 'x1']);

        $this->assertCount(5, $result);
        if ($result[0]) {
            $this->assertEqualsWithDelta($result[1], $result[3], 0.001,
                'Without split, cv_train == cv_test');
        }
    }
}
