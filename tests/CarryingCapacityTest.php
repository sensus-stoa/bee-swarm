<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\EnvPressureVerify;
use BeeSwarm\Infra\CarryingCapacity;
use PHPUnit\Framework\TestCase;

/**
 * §2.6 Environmental Pressure — WU-4: carrying capacity + verify_1_6.
 *
 * Спека: N_max / N_median ≤ 3 за период наблюдения; экстинкции (N=0) и
 * SEED_SPAWN-восстановления исключаются из расчёта. >3 → FAIL (шумовая
 * флуктуация или взрывной рост).
 *
 * verify_1_6 (Measurement): (a) ≥1 задача выброшена TIMEOUT
 * (MISSED_OPPORTUNITY), (b) сложность менялась ≥1 раз (ENV_DIFF),
 * (c) N_max/N_median ≤ 3. Pass: все три.
 */
final class CarryingCapacityTest extends TestCase
{
    /**
     * Стабильная популяция: ratio < 3 → pass.
     */
    public function testStablePopulationPasses(): void
    {
        // sorted: [9,10,10,10,10,11,11,12,15] — median 10, max 15 → 1.5
        $r = CarryingCapacity::analyze([10, 10, 11, 12, 15, 10, 9, 11, 10]);

        self::assertTrue($r['pass']);
        self::assertSame(15, $r['max']);
        self::assertSame(10.0, $r['median']);
        self::assertSame(1.5, $r['ratio']);
    }

    /**
     * Взрывной рост: ratio > 3 → FAIL.
     */
    public function testExplosiveGrowthFails(): void
    {
        // median 10, max 40 → 4.0 > 3
        $r = CarryingCapacity::analyze([10, 10, 10, 40, 10]);

        self::assertFalse($r['pass']);
        self::assertSame(40, $r['max']);
        self::assertSame(4.0, $r['ratio']);
    }

    /**
     * Экстинкции (N=0) исключаются из выборки.
     */
    public function testExtinctionsExcluded(): void
    {
        // Без экстинкций: [4,5,6] → median 5, max 6, ratio 1.2 → pass.
        // Если бы нули участвовали в медиане — результат был бы другим.
        $r = CarryingCapacity::analyze([4, 5, 0, 6, 0]);

        self::assertTrue($r['pass']);
        self::assertSame(6, $r['max']);
        self::assertSame(5.0, $r['median']);
        self::assertSame(2, $r['excluded']);
    }

    /**
     * Недостаточно наблюдений после фильтрации → inconclusive (pass=false).
     */
    public function testInsufficientObservations(): void
    {
        $r = CarryingCapacity::analyze([10, 0, 0]);

        self::assertFalse($r['pass']);
        self::assertTrue($r['inconclusive']);
    }

    /**
     * Медиана чётной выборки — среднее двух центральных.
     */
    public function testMedianEvenSample(): void
    {
        $r = CarryingCapacity::analyze([10, 20]);

        self::assertSame(15.0, $r['median']);
        self::assertSame(20, $r['max']);
    }

    /**
     * verify_1_6 на синтетическом логе: все три условия выполнены → PASS.
     */
    public function testVerifyFullLogPasses(): void
    {
        $log = $this->syntheticLog();
        $log .= "MISSED_OPPORTUNITY: task_a age=200 K=200\n";
        $log .= "ENV_DIFF: rise level=2\n";

        $r = EnvPressureVerify::run($log);

        self::assertTrue($r['pass'], json_encode($r));
        self::assertSame(1, $r['missed']);
        self::assertSame(1, $r['diffChanges']);
        self::assertSame(1.5, $r['cc']['ratio']);
    }

    /**
     * verify_1_6: нет MISSED_OPPORTUNITY → FAIL (a не выполнено).
     */
    public function testVerifyFailsWithoutTimeout(): void
    {
        $log = $this->syntheticLog();
        $log .= "ENV_DIFF: rise level=2\n";

        $r = EnvPressureVerify::run($log);

        self::assertFalse($r['pass']);
        self::assertSame(0, $r['missed']);
    }

    /**
     * verify_1_6: сложность не менялась → FAIL (b не выполнено).
     */
    public function testVerifyFailsWithoutDiffChange(): void
    {
        $log = $this->syntheticLog();
        $log .= "MISSED_OPPORTUNITY: task_a age=200 K=200\n";

        $r = EnvPressureVerify::run($log);

        self::assertFalse($r['pass']);
        self::assertSame(0, $r['diffChanges']);
    }

    /**
     * verify_1_6: carrying capacity > 3 → FAIL (c не выполнено).
     */
    public function testVerifyFailsOnExplosivePopulation(): void
    {
        $log = $this->syntheticLog([10, 10, 10, 50]);
        $log .= "MISSED_OPPORTUNITY: task_a age=200 K=200\n";
        $log .= "ENV_DIFF: rise level=2\n";

        $r = EnvPressureVerify::run($log);

        self::assertFalse($r['pass']);
        self::assertSame(5.0, $r['cc']['ratio']);
    }

    /**
     * Синтетический лог с GEN-событиями (pop=N); экстинкция (pop=0) внутри.
     *
     * @param list<int> $pops
     */
    private function syntheticLog(array $pops = [10, 0, 12, 10, 15, 10]): string
    {
        $log = '';
        $i = 0;
        foreach ($pops as $pop) {
            $log .= "[2026-09-13 10:0{$i}:00] GEN: {$i} pop={$pop} unique=3 diversity=0.5 avg|G|=8\n";
            $i++;
        }

        return $log;
    }
}
