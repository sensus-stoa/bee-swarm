<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Trading\LiveExecutor;
use PHPUnit\Framework\TestCase;

/**
 * FIN-005: ТЕСТЫ БОЕВОГО ИСПОЛНИТЕЛЯ (live_portfolio).
 * Чистые функции: сигналы, приоритет плеча, объём, трейлинг, выход.
 */
class LiveExecutorTest extends TestCase
{
    /** r-атом: среднее последних n */
    public function testRAtom(): void
    {
        $ret = [0.01, 0.02, 0.03, 0.04, 0.05, 0.10];
        $this->assertEqualsWithDelta(0.075, LiveExecutor::rAtom($ret, 2), 1e-9); // (0.05+0.10)/2
    }

    /** сигнал ветки: все условия AND по r-атомам */
    public function testBranchSignalAllTrue(): void
    {
        $ret = array_fill(0, 50, 0.02); // стабильный рост
        $branch = [
            'conds' => [
                ['atom' => 'r5', 'op' => '>', 'threshold' => 0.01],
                ['atom' => 'r10', 'op' => '>', 'threshold' => 0.01],
            ],
        ];
        $this->assertTrue(LiveExecutor::branchSignal($branch, $ret));
    }

    /** сигнал: одно условие ложно → false */
    public function testBranchSignalOneFalse(): void
    {
        $ret = array_fill(0, 50, -0.02); // падение
        $branch = [
            'conds' => [
                ['atom' => 'r5', 'op' => '>', 'threshold' => 0.01],
            ],
        ];
        $this->assertFalse(LiveExecutor::branchSignal($branch, $ret));
    }

    /** неизвестный атом → false (консервативно, без ложных входов) */
    public function testBranchSignalUnknownAtom(): void
    {
        $ret = array_fill(0, 50, 0.0);
        $branch = [
            'conds' => [
                ['atom' => 'taker5', 'op' => '>', 'threshold' => 0.0],
            ],
        ];
        $this->assertFalse(LiveExecutor::branchSignal($branch, $ret));
    }

    /** приоритет: порядок стратегий по max-lev DESC */
    public function testMaxLevOrder(): void
    {
        $portfolio = [
            ['genome' => ['branches' => [['lev' => 1], ['lev' => 2]]]],   // max 2
            ['genome' => ['branches' => [['lev' => 50]]]],              // max 50
            ['genome' => ['branches' => [['lev' => 5], ['lev' => 3]]]], // max 5
        ];
        $order = LiveExecutor::maxLevOrder($portfolio);
        $this->assertSame([1, 2, 0], $order);
    }

    /** округление объёма по volScale + минимум */
    public function testRoundVol(): void
    {
        $this->assertSame(6.0, LiveExecutor::roundVol(6.24, 0, 1.0));
        $this->assertSame(1.0, LiveExecutor::roundVol(0.3, 0, 1.0)); // ниже min
        $this->assertSame(6.2, LiveExecutor::roundVol(6.24, 1, 0.1));
    }

    /** трейлинг-параметры: только при trail > 0; close-side по стороне */
    public function testTrailParams(): void
    {
        $sh = LiveExecutor::trailParams(['side' => -1, 'trail' => 0.092, 'hold' => 3, 'lev' => 3]);
        $this->assertSame(2, $sh['closeSide']); // шорт → close short (buy)
        $this->assertEqualsWithDelta(0.092, $sh['backValue'], 1e-9);

        $ln = LiveExecutor::trailParams(['side' => 1, 'trail' => 0.05, 'hold' => 3, 'lev' => 3]);
        $this->assertSame(4, $ln['closeSide']); // лонг → close long (sell)

        $none = LiveExecutor::trailParams(['side' => -1, 'trail' => 0, 'hold' => 3, 'lev' => 1]);
        $this->assertNull($none);
    }

    /** решение о выходе: трейлинг-откат для шорта */
    public function testExitDecisionTrailingShort(): void
    {
        $pos = ['side' => -1, 'entry' => 1.0, 'peak' => 0.70, 'trail' => 0.10, 'close_after' => time() + 99999];
        // цена отскочила на 10% от пика 0.70 → 0.77 → трейлинг
        $this->assertSame('трейлинг 0.1', LiveExecutor::exitDecision($pos, 0.77, time()));
    }

    /** решение о выходе: стоп для шорта (+5% против) */
    public function testExitDecisionStop(): void
    {
        $pos = ['side' => -1, 'entry' => 1.0, 'peak' => 1.0, 'trail' => 0.0, 'close_after' => time() + 99999];
        $this->assertSame('стоп +0.05', LiveExecutor::exitDecision($pos, 1.06, time()));
    }

    /** решение о выходе: hold истёк */
    public function testExitDecisionHold(): void
    {
        $pos = ['side' => -1, 'entry' => 1.0, 'peak' => 1.0, 'trail' => 0.0, 'close_after' => time() - 1];
        $this->assertSame('hold истёк', LiveExecutor::exitDecision($pos, 1.00, time()));
    }

    /** решение о выходе: всё ок → null */
    public function testExitDecisionNone(): void
    {
        $pos = ['side' => -1, 'entry' => 1.0, 'peak' => 1.0, 'trail' => 0.0, 'close_after' => time() + 99999];
        $this->assertNull(LiveExecutor::exitDecision($pos, 0.99, time()));
    }

    /** РЕГРЕССИЯ 18.08: side-коды закрытия (2/4 были перепутаны → 2009) */
    public function testCloseSide(): void
    {
        $this->assertSame(4, LiveExecutor::closeSide(1), 'лонг закрывается side=4 (close long)');
        $this->assertSame(2, LiveExecutor::closeSide(-1), 'шорт закрывается side=2 (close short)');
    }
}
