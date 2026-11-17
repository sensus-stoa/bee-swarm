<?php
declare(strict_types=1);

namespace BeeSwarm\Hive;

use PHPUnit\Framework\TestCase;

/**
 * STRATEGY-C2 (owner design 04.11): CLASS_MATCH:<K> condition — the (i) arm,
 * gate WITHOUT the theta barrier. Activates on class match + n_min; UNKNOWN
 * below n_min; no LOW_STATE (state is tracked but does not gate).
 */
final class RouteClassMatchTest extends TestCase
{
    private function genome(): array
    {
        return [
            'id' => 'route_c0ffee12',
            'actions' => [['op' => 'FLIGHT', 'grammar' => 'base', 'beam' => 'default', 'depth' => 2]],
            'condition' => ['kind' => 'CLASS_MATCH:SUM', 'n_min' => 3],
            'budget_fraction' => 0.3,
            'max_steps' => 1,
        ];
    }

    public function testValidSchemaPasses(): void
    {
        $this->assertSame([], RouteGenome::validate($this->genome()));
    }

    public function testClassLetterValidation(): void
    {
        $g = $this->genome();
        $g['condition']['kind'] = 'CLASS_MATCH:sum1';
        $this->assertNotSame([], RouteGenome::validate($g));
        $g['condition']['kind'] = 'CLASS_MATCH:RATIO';
        $this->assertSame([], RouteGenome::validate($g));
    }

    public function testActivateOnClassMatchRegardlessOfState(): void
    {
        // state far below any theta, n_obs enough -> ACTIVATED (no theta gate)
        $r = RouteExecutor::conditionDecision($this->genome(), [
            'task_class' => 'SUM', 'yield_t' => -0.5, 'prev_state' => 0.0, 'n_obs' => 3,
        ]);
        $this->assertTrue($r['activated']);
        $this->assertSame('ACTIVATED', $r['reason']);
    }

    public function testUnknownBelowNMin(): void
    {
        $r = RouteExecutor::conditionDecision($this->genome(), [
            'task_class' => 'SUM', 'yield_t' => 0.5, 'prev_state' => 0.0, 'n_obs' => 2,
        ]);
        $this->assertFalse($r['activated']);
        $this->assertSame('UNKNOWN', $r['reason']);
    }

    public function testClassMismatchBlocks(): void
    {
        $r = RouteExecutor::conditionDecision($this->genome(), [
            'task_class' => 'PRODUCT', 'yield_t' => 0.5, 'prev_state' => 0.0, 'n_obs' => 9,
        ]);
        $this->assertFalse($r['activated']);
        $this->assertSame('CLASS_MISMATCH', $r['reason']);
    }

    public function testDefaultNMinIsOne(): void
    {
        $g = $this->genome();
        unset($g['condition']['n_min']);
        $this->assertSame([], RouteGenome::validate($g));
        $r = RouteExecutor::conditionDecision($g, [
            'task_class' => 'SUM', 'yield_t' => 0.0, 'prev_state' => 0.0, 'n_obs' => 1,
        ]);
        $this->assertTrue($r['activated']);
    }
}
