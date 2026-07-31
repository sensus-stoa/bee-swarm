<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\YellowBallFilter;

/**
 * S0-YELLOW: Yellow Ball Pre-screen
 *
 * Фильтр детектит кандидатов с низким CV на train но провалом на held-out.
 * Экономит ресурсы, отбрасывая шум до candidate_pool.
 */
class YellowBallFilterTest extends TestCase
{
    /** train CV < 0.01 но held-out CV > 0.05 → YELLOW */
    public function testSuspiciousCVDifferenceDetected(): void
    {
        $filter = new YellowBallFilter('test_task');
        $filter->addCandidate(0.008, 0.12);  // train CV=0.008, held-out CV=0.12
        $filter->addCandidate(0.004, 0.006);  // оба низкие — valid
        $filter->addCandidate(0.007, 0.009);  // тоже valid

        $result = $filter->evaluate(heldOutRequired: 3);
        $this->assertCount(1, $result['yellow'], 'Big gap must be flagged');
        $this->assertCount(2, $result['valid']);
    }

    /** train и held-out CV оба низкие → VALID */
    public function testHonestLowCVPasses(): void
    {
        $filter = new YellowBallFilter('test');
        $filter->addCandidate(0.005, 0.008);  // оба < 0.01
        $filter->addCandidate(0.002, 0.004);
        $filter->addCandidate(0.006, 0.009);

        $result = $filter->evaluate(heldOutRequired: 3);
        $this->assertCount(0, $result['yellow']);
        $this->assertCount(3, $result['valid'], 'Consistent low CV must pass');
    }

    /** <3 held-out проверок → недостаточно данных */
    public function testInsufficientHeldOutBlocksEvaluation(): void
    {
        $filter = new YellowBallFilter('test');
        $filter->addCandidate(0.008, 0.12);
        $filter->addCandidate(0.005, 0.006);

        $result = $filter->evaluate(heldOutRequired: 3);
        $this->assertFalse($result['ready'], 'Need ≥3 held-out checks');
        $this->assertEmpty($result['valid']);
    }
}
