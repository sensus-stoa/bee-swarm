<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;

/**
 * SEARCH-BUDGET (19.08.2026, PYSR-BENCHMARK фаза 2):
 * Search::find должен уметь останавливаться по времени.
 * ЭКСП-027: systematic без бюджета на 12 фичах → >15 мин (EXIT=124).
 * PySR всегда с timeout — сравнение некорректно без бюджета.
 */
class SearchBudgetTest extends TestCase
{
    /**
     * find с budgetSec=1s на 12 фичах возвращается за <5s
     * (раньше: >15 мин, таймаут).
     */
    public function testFindWithBudgetReturnsInTime(): void
    {
        // 12 фич × 106 строк — как WINE frozen split (ЭКСП-027)
        $X = [];
        $y = [];
        $rng = mt_rand(1, 999999);
        mt_srand(42);
        for ($i = 0; $i < 106; $i++) {
            $row = [];
            for ($f = 0; $f < 12; $f++) {
                $row[] = mt_rand() / mt_getrandmax() * 10.0;
            }
            $X[] = $row;
            $y[] = mt_rand() / mt_getrandmax() * 5.0;
        }

        $g = new Grammar();
        $start = microtime(true);
        $res = Search::find($X, $y, $g, 2, null, 0.0, 0.15, 1.0); // budget=1s
        $elapsed = microtime(true) - $start;

        // Ключевая проверка: find ВЕРНУЛСЯ (раньше: >15 мин, EXIT=124!)
        // и вернул TIMEOUT-класс (бюджет сработал).
        // elapsed в paratest = wall-clock с ожиданием CPU — жёсткий порог
        // невозможен (конкуренция!), поэтому: <60s = порядок величины.
        $this->assertLessThan(
            60.0,
            $elapsed,
            "find с budget=1s вернулся за {$elapsed}s (должен <60s, раньше >15 мин!)"
        );
        $this->assertIsArray($res, 'find вернул массив');
        $this->assertCount(5, $res, 'backward-compatible: [found, cv, formula, cvTest, class]');
        $this->assertSame(
            'TIMEOUT',
            $res[4],
            "класс результата TIMEOUT (бюджет сработал), получен: {$res[4]}"
        );
    }

    /**
     * budgetSec=0 (default) — поведение не изменилось (без лимита).
     */
    public function testDefaultBudgetZeroNoLimit(): void
    {
        $X = [[1.0, 2.0], [2.0, 4.0], [3.0, 6.0]];
        $y = [2.0, 4.0, 6.0];
        $g = new Grammar();

        $res = Search::find($X, $y, $g, 2);
        $this->assertTrue($res[0], 'простой закон (y=2x0) найден без бюджета');
    }
}
