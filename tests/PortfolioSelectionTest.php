<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Trading\TradingHive;
use PHPUnit\Framework\TestCase;

/**
 * FIN-005 v12: ПОРТФЕЛЬНЫЙ ОТБОР — жадный отбор пчёл по НЕЗАВИСИМОСТИ:
 * берём лучшую по PnL, следующую — только если её сделки слабо коррелируют
 * с уже выбранными (максимум диверсификации, не суммы PnL).
 */
class PortfolioSelectionTest extends TestCase
{
    public function testIdenticalSeriesCollapse(): void
    {
        // три одинаковые серии — портфель схлопывается до 1
        $series = [
            0 => [0, 1, 0, 1, 0],
            1 => [0, 1, 0, 1, 0],
            2 => [0, 1, 0, 1, 0],
        ];
        $pnls = [0 => 0.5, 1 => 0.4, 2 => 0.3];
        $sel = TradingHive::selectPortfolio($series, $pnls, 10, 0.5);
        $this->assertCount(1, $sel);
    }

    public function testIndependentSeriesKept(): void
    {
        $series = [
            0 => [0, 1, 0, 1, 0],   // дни 1,3
            1 => [0, 1, 0, 1, 0],   // копия 0 — отбросится
            2 => [1, 0, 0, 0, 1],   // другие дни — независима
        ];
        $pnls = [0 => 0.5, 1 => 0.4, 2 => 0.3];
        $sel = TradingHive::selectPortfolio($series, $pnls, 10, 0.5);
        $this->assertCount(2, $sel);
        $this->assertContains(0, $sel); // лучшая из пары
        $this->assertContains(2, $sel); // независимая
        $this->assertNotContains(1, $sel);
    }

    public function testBestFirst(): void
    {
        $series = [
            0 => [0, 0, 1, 0, 0],
            1 => [1, 0, 0, 0, 0],
            2 => [0, 1, 0, 0, 0],
        ];
        $pnls = [0 => 0.1, 1 => 0.9, 2 => 0.5];
        $sel = TradingHive::selectPortfolio($series, $pnls, 10, 0.5);
        $this->assertSame(1, $sel[0]); // лучшая по PnL — первая
    }
}
