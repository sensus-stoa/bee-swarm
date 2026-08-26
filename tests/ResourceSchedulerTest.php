<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\ResourceScheduler;

/**
 * RESOURCE-SCHEDULER (26.08, resource-bounded evolution):
 * Квоты секторов + materialization budget.
 */
class ResourceSchedulerTest extends TestCase
{
    public function testComputeQuotasDistributesAcrossSectors(): void
    {
        $sched = new ResourceScheduler();
        $quotas = $sched->computeQuotas(1000);

        $this->assertArrayHasKey('DIFF', $quotas);
        $this->assertArrayHasKey('unknown', $quotas);
        $this->assertGreaterThan(0, $quotas['DIFF']);
        // Сумма квот ≤ maxMaterialized
        $this->assertLessThanOrEqual($sched->maxMaterialized(), array_sum($quotas));
    }

    public function testMaterializationBudgetReducesUnderLoad(): void
    {
        $sched = new ResourceScheduler([], 50);

        $noLoad = $sched->materializationBudget(1000, 0.0);
        $highLoad = $sched->materializationBudget(1000, 0.8);

        $this->assertGreaterThan($highLoad, $noLoad);
        $this->assertGreaterThanOrEqual(5, $highLoad); // минимум 5
    }

    public function testBaseQuotasSumToOne(): void
    {
        $sched = new ResourceScheduler();
        $sum = array_sum($sched->baseQuotas());
        $this->assertEqualsWithDelta(1.0, $sum, 0.001);
    }
}
