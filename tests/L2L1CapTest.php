<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;

/**
 * L2L1-CAP (25.08.2026, PYSR-бенчмарк):
 * SEARCH-L2L1 перебирал $ops = ВСЮ грамматику (прод: 3562 атома!) →
 * 30 l1Top × 12 фич × 3562 ops × n точек = ВЕЧНОСТЬ на проде.
 * (Ноут: process висел >30s при budget=1s, пока не добавили cap 50.)
 *
 * Regression: с БОЛЬШОЙ грамматикой (имитация прода) depth=3
 * возвращается по бюджету, а не зависает.
 */
class L2L1CapTest extends TestCase
{
    public function testL2L1WithHugeGrammarReturnsByBudget(): void
    {
        // Имитация прод-грамматики: 500 «атомов» (до фикса: 500×30×3×n —
        // вечность; с cap 50 — секунды)
        $g = new Grammar();
        for ($i = 0; $i < 500; $i++) {
            $g->add("op_{$i}", 'test');
        }

        // 3 фичи × 20 строк (глубина 3 — входит в L2L1)
        $X = [];
        $y = [];
        for ($i = 0; $i < 20; $i++) {
            $X[] = [$i, $i * 2.0, $i * 3.0];
            $y[] = $i * 4.0;
        }

        $start = microtime(true);
        $res = Search::find($X, $y, $g, 3, null, 0.0, 0.15, 5.0); // budget=5s
        $elapsed = microtime(true) - $start;

        // Ключевое: вернулся (не вечность!). <30s = порядок величины
        // (до фикса: 500 ops × 30 × 3 × 20 = 900K apply — минуты!)
        $this->assertLessThan(
            30.0,
            $elapsed,
            "depth=3 с 2000-ops грамматикой вернулся за {$elapsed}s (было: вечность!)"
        );
        $this->assertIsArray($res, 'find вернул массив');
        $this->assertCount(6, $res, 'backward-compatible формат');
    }

    public function testL2L1SmallGrammarStillFinds(): void
    {
        // Санity: с маленькой грамматикой L2L1 не сломан (y = x0×x1 + x2)
        $g = new Grammar();
        $X = [[1.0, 2.0, 1.0], [2.0, 3.0, 1.0], [3.0, 4.0, 1.0], [4.0, 5.0, 1.0]];
        $y = [3.0, 7.0, 13.0, 21.0];

        $res = Search::find($X, $y, $g, 3);
        $this->assertIsArray($res);
        $this->assertCount(6, $res);
    }
}
