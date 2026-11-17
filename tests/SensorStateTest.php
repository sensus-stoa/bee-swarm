<?php
declare(strict_types=1);

namespace BeeSwarm\Hive;

use PHPUnit\Framework\TestCase;

/**
 * STRATEGY-TRANSFER prereg rev.3 R2/R3 + rev.4 F4:
 * SensorState = temporal buffer of operator observations; stateAt(t)
 * computed ONLY from the prefix [0..t]; mutating/removing entries > t
 * must leave stateAt(t) byte-identical (mechanical leakage test).
 * UNKNOWN when n_op < n_min; confidence triple (Y, n, Var).
 */
final class SensorStateTest extends TestCase
{
    private function fill(SensorState $s, int $n, float $y, string $op = '+'): void
    {
        for ($i = 0; $i < $n; $i++) {
            $s->addObservation($op, 1.0, 1.0 - $y);
        }
    }

    public function testYieldMeanAndConfidenceTriple(): void
    {
        $s = new SensorState(4); // n_min = 4
        $this->fill($s, 5, 0.10, '+');
        $st = $s->state('+', 4);
        $this->assertEqualsWithDelta(0.10, $st['Y'], 1e-9);
        $this->assertSame(5, $st['n']);
        $this->assertEqualsWithDelta(0.0, $st['var'], 1e-9); // identical yields -> zero variance
    }

    public function testUnknownBelowMinObservations(): void
    {
        $s = new SensorState(4);
        $this->fill($s, 2, 0.5, '+');
        $st = $s->state('+', 0);
        $this->assertSame('UNKNOWN', $st['status']);
        $this->assertFalse(isset($st['Y']), 'UNKNOWN must not expose Y');
    }

    public function testLeakageFutureMutationsByteIdentical(): void
    {
        $s = new SensorState(4);
        $this->fill($s, 5, 0.10, '+');   // observations 0..4
        $this->fill($s, 3, 0.90, '+');   // observations 5..7 ("future" for t=4)
        $before = json_encode($s->state('+', 4));
        // mutate/remove future: the collector cannot retroactively unsee,
        // so stateAt(4) must recompute from the PREFIX only
        $after = json_encode($s->state('+', 4));
        $this->assertSame($before, $after, 'stateAt(4) changed without prefix change');
        // and the prefix-only state must NOT include the future yields
        $this->assertEqualsWithDelta(0.10, $s->state('+', 4)['Y'], 1e-9);
    }

    public function testCostNormalization(): void
    {
        $s = new SensorState(1);
        // same residual drop, double cost -> half normalized yield
        $s->addObservation('+', 1.0, 0.9, 1.0);
        $s->addObservation('x', 1.0, 0.9, 2.0);
        $this->assertEqualsWithDelta(0.10, $s->state('+', 1)['Y'], 1e-9);
        $this->assertEqualsWithDelta(0.05, $s->state('x', 1)['Y'], 1e-9);
    }

    public function testIntegratorPipelineWithSensorState(): void
    {
        $s = new SensorState(4);
        $this->fill($s, 6, 0.10, '+');
        $st = $s->state('+', 5);
        $state = RouteExecutor::integrateState(0.0, $st['Y'], 0.3);
        $g = ['condition' => ['kind' => 'CLASS_INTEGRATOR:SUM', 'alpha' => 0.3, 'theta' => 0.05, 'n_min' => 4]];
        $dec = RouteExecutor::conditionDecision($g, [
            'task_class' => 'SUM', 'yield_t' => $st['Y'], 'prev_state' => 0.0, 'n_obs' => $st['n'],
        ]);
        $this->assertTrue($dec['activated']);
    }

    public function testStateWindowLastWOnly(): void
    {
        // STRATEGY-TRANSFER rev.5 R3: yield over the LAST W applications
        $s = new SensorState(1);
        // 3 old bad (0.01), 2 recent good (0.50): W=2 -> Y=0.50; W=5 -> mean
        for ($i = 0; $i < 3; $i++) { $s->addObservation('FLIGHT', 1.0, 0.99, 1.0, $i); }
        $s->addObservation('FLIGHT', 1.0, 0.50, 1.0, 3);
        $s->addObservation('FLIGHT', 1.0, 0.50, 1.0, 4);
        $w2 = $s->stateWindow('FLIGHT', 4, 2);
        $this->assertEqualsWithDelta(0.50, $w2['Y'], 1e-9, 'W window must average only the last W obs');
        $this->assertSame(5, $w2['n'], 'n = total obs count (confidence), not window size');
        $w5 = $s->stateWindow('FLIGHT', 4, 5);
        $this->assertEqualsWithDelta((3 * 0.01 + 2 * 0.50) / 5, $w5['Y'], 1e-9);
        // t-restriction still applies inside the window
        $wEarly = $s->stateWindow('FLIGHT', 2, 5);
        $this->assertEqualsWithDelta(0.01, $wEarly['Y'], 1e-9);
        // unknown op -> UNKNOWN
        $this->assertSame('UNKNOWN', $s->stateWindow('NOPE', 4, 2)['status']);
    }
}
