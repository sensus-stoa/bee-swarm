<?php
declare(strict_types=1);

namespace BeeSwarm\Hive;

use PHPUnit\Framework\TestCase;

/**
 * STRATEGY-TRANSFER prereg rev.5 / R2-R3: CLASS_INTEGRATOR dynamics.
 * state_t = a*state_{t-1} + (1-a)*yield_t; activation at state_t > theta
 * AND n_obs >= n_min AND task class matches; UNKNOWN below n_min (block
 * NOT activated, flagged separately); temporal independence: the function
 * is pure over its inputs - no future data can enter by construction.
 */
final class RouteConditionIntegratorTest extends TestCase
{
    private function genome(): array
    {
        return [
            'id' => 'route_integ1',
            'actions' => [
                ['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'default', 'depth' => 2, 'budget_fraction' => 0.2],
                ['op' => 'USE_MARKS', 'k' => 5, 'budget_fraction' => 0.1],
            ],
            'condition' => ['kind' => 'CLASS_INTEGRATOR:SUM', 'alpha' => 0.3, 'theta' => 0.05, 'n_min' => 3],
            'budget_fraction' => 0.3,
            'max_steps' => 4,
        ];
    }

    public function testIntegratorDynamicsExponentialSmoothing(): void
    {
        // state1 = 0.3*0 + 0.7*0.10 = 0.070 > theta(0.05)
        $s1 = RouteExecutor::integrateState(0.0, 0.10, 0.3);
        $this->assertEqualsWithDelta(0.070, $s1, 1e-9);
        // state2 = 0.3*0.070 + 0.7*0.02 = 0.035
        $s2 = RouteExecutor::integrateState($s1, 0.02, 0.3);
        $this->assertEqualsWithDelta(0.035, $s2, 1e-9);
// decay toward zero with silent yields
        $s3 = RouteExecutor::integrateState($s2, 0.0, 0.3);
        $this->assertEqualsWithDelta(0.0105, $s3, 1e-9);
    }

    public function testUnknownWhenBelowNObservations(): void
    {
        $r = RouteExecutor::conditionDecision($this->genome(), [
            'task_class' => 'SUM', 'yield_t' => 0.5, 'prev_state' => 0.0, 'n_obs' => 2,
        ]);
        $this->assertFalse($r['activated']);
        $this->assertSame('UNKNOWN', $r['reason'], 'n < n_min must be UNKNOWN, not LOW');
    }

    public function testActivateOnMatchingClassAboveTheta(): void
    {
        $r = RouteExecutor::conditionDecision($this->genome(), [
            'task_class' => 'SUM', 'yield_t' => 0.10, 'prev_state' => 0.0, 'n_obs' => 5,
        ]);
        $this->assertTrue($r['activated']);
        $this->assertSame('ACTIVATED', $r['reason']);
        $this->assertEqualsWithDelta(0.070, $r['state'], 1e-9);
    }

    public function testClassMismatchBlocksEvenWithStrongSignal(): void
    {
        $r = RouteExecutor::conditionDecision($this->genome(), [
            'task_class' => 'PRODUCT', 'yield_t' => 0.9, 'prev_state' => 0.4, 'n_obs' => 5,
        ]);
        $this->assertFalse($r['activated']);
        $this->assertSame('CLASS_MISMATCH', $r['reason']);
    }

    public function testTemporalIndependencePureFunction(): void
    {
        // same inputs -> same decision; no hidden state, no future access point
        $in = ['task_class' => 'SUM', 'yield_t' => 0.10, 'prev_state' => 0.0, 'n_obs' => 5];
        $a = RouteExecutor::conditionDecision($this->genome(), $in);
        $b = RouteExecutor::conditionDecision($this->genome(), $in);
        $this->assertSame($a, $b);
        // mutation of unrelated fields must not change the decision
        $in2 = $in; $in2['future_noise'] = 'garbage';
        $this->assertSame($a, RouteExecutor::conditionDecision($this->genome(), $in2));
    }
}
