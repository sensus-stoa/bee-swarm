<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;

/**
 * SEARCH-BUDGET (19.08.2026, PYSR-BENCHMARK фаза 2):
 * Search::find должен уметь останавливаться.
 * ЭКСП-027: systematic без бюджета на 12 фичах → >15 мин (EXIT=124).
 * PySR всегда с timeout — сравнение некорректно без бюджета.
 *
 * V0.18 WU-3 (23.09): механика останова — ТИКИ (детерминизм), wall-clock
 * остаётся kill-switch-ом. По букве стори: «T тиков возвращаются за
 * конечное предсказуемое число операций» + ОДИН wall-clock smoke-тест
 * kill-switch-ветки (fast, @group slow не нужен).
 */
final class SearchBudgetTest extends TestCase
{
    /**
     * find с budgetTicks=5 на тяжёлом домене (20 фич шума) возвращается
     * с TICKS_EXHAUSTED за предсказуемо конечное время: тики = детерминизм.
     * Фикстура в окне исчерпания (калибровка WU-2: полные переборы таких
     * доменов > 50 тиков, 5 тиков = ранняя остановка на порционной границе).
     */
    public function testTickBudgetReturnsDeterministically(): void
    {
        $X = [];
        $y = [];
        mt_srand(42);
        for ($i = 0; $i < 30; $i++) {
            $row = [];
            for ($f = 0; $f < 20; $f++) {
                $row[] = mt_rand() / mt_getrandmax() * 2 ** ($f % 12);
            }
            $X[] = $row;
            $y[] = mt_rand() / mt_getrandmax() * 5.0;
        }

        $g = new Grammar();
        $res = Search::find($X, $y, $g, 2, null, 0.0, 0.15, 0.0, null, 5);

        $this->assertIsArray($res, 'find вернул массив');
        $this->assertCount(6, $res, 'backward-compatible shape');
        $this->assertFalse($res[0], '5 тиков на шуме 20 фич не находят закон');
        $this->assertSame('TICKS_EXHAUSTED', $res[5], 'диагноз = тиковое исчерпание, получен: ' . ($res[5] ?? 'null'));
    }

    /**
     * Default-поведение не изменилось: без budgetTicks (null) и budgetSec=0
     * перебор не лимитирован (легитимный found на простом законе).
     */
    public function testDefaultBudgetZeroNoLimit(): void
    {
        $X = [[1.0, 2.0], [2.0, 4.0], [3.0, 6.0]];
        $y = [2.0, 4.0, 6.0];
        $g = new Grammar();

        $res = Search::find($X, $y, $g, 2);
        $this->assertTrue($res[0], 'простой закон (y=2x0) найден без бюджета');
    }

    /**
     * Wall-clock kill-switch smoke: SEARCH_WALLCLOCK_CAP_S отсекает даже
     * без тиков (INC-2: тик ≠ секунда; кап = абсолютный страхующий стоп).
     * fast-тест (smoke), kill-switch срабатывает на первых фазах.
     */
    public function testWallclockCapKillSwitch(): void
    {
        $prev = getenv('SEARCH_WALLCLOCK_CAP_S');
        putenv('SEARCH_WALLCLOCK_CAP_S=2');
        try {
            $X = [];
            $y = [];
            mt_srand(42);
            for ($i = 0; $i < 30; $i++) {
                $row = [];
                for ($f = 0; $f < 20; $f++) {
                    $row[] = mt_rand() / mt_getrandmax() * 2 ** ($f % 12);
                }
                $X[] = $row;
                $y[] = mt_rand() / mt_getrandmax() * 5.0;
            }
            $g = new Grammar();
            $res = Search::find($X, $y, $g, 3, null, 0.0, 0.15, 0.0);
            $this->assertFalse($res[0], 'kill-switch останавливает перебор');
            $this->assertSame('WALLCLOCK_CAP', $res[5], 'диагноз = wall-clock кап, получен: ' . ($res[5] ?? 'null'));
        } finally {
            if ($prev === false) {
                putenv('SEARCH_WALLCLOCK_CAP_S');
            } else {
                putenv('SEARCH_WALLCLOCK_CAP_S=' . $prev);
            }
        }
    }
}
