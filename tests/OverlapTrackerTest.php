<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\OverlapTracker;

/**
 * Story S0-OVERLAP: Overlap Awareness (§1.8)
 */
class OverlapTrackerTest extends TestCase
{
    private OverlapTracker $tracker;
    private int $testRun = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tracker = new OverlapTracker();
        $this->testRun++;
    }

    /** Уникальные имена пчёл для изоляции тестов */
    private function bees(string $suffix = ''): array
    {
        $id = $this->testRun . '_' . $suffix;
        return ["bee_a_{$id}", "bee_b_{$id}"];
    }

    /**
     * §1.8: При переназначении задачи система логирует pairwise сравнение.
     */
    public function testRecordPairwiseOverlap(): void
    {
        [$a, $b] = $this->bees('r');
        $this->tracker->record($a, $b, 'task_add', '+(x0,x1)', '+(x0,x1)');
        $this->tracker->record($a, $b, 'task_mul', '×(x0,x1)', '+(x0,x1)');

        $stats = $this->tracker->pairStats($a, $b);
        $this->assertSame(2, $stats['shared_tasks']);
        $this->assertSame(1, $stats['matched']);
    }

    /**
     * §1.8: При shared_tasks ≥ 10 пара считается «измеренной».
     */
    public function testPairBecomesMeasuredAtTenSharedTasks(): void
    {
        [$a, $b] = $this->bees('m');
        for ($i = 0; $i < 10; $i++) {
            $this->tracker->record($a, $b, "task_{$i}", '+(x0,x1)', '+(x0,x1)');
        }
        $stats = $this->tracker->pairStats($a, $b);
        $this->assertSame(10, $stats['shared_tasks']);
        $this->assertTrue($stats['measured'], 'Pair must be measured at ≥10 shared tasks');
    }

    /**
     * Ответы совпадают если expression tree идентично после алгебраической редукции.
     */
    public function testAnswersMatchAfterAlgebraicReduction(): void
    {
        [$a, $b] = $this->bees('re');
        // add(x,0) → x после редукции (§1.4). Оба ответа сворачиваются в x0.
        $this->tracker->record($a, $b, 'task_id', 'add(x0,0)', 'x0');

        $stats = $this->tracker->pairStats($a, $b);
        $this->assertSame(1, $stats['matched'],
            'add(x,0) and x0 must match after algebraic reduction');
    }

    /**
     * §1.8: Формат лога OVERLAP i j.
     */
    public function testOverlapLogFormat(): void
    {
        [$a, $b] = $this->bees('l');
        $this->tracker->record($a, $b, 'task_1', '+(x0,x1)', '+(x0,x1)');
        $this->tracker->record($a, $b, 'task_2', '+(x0,x1)', '×(x0,x1)');

        $log = $this->tracker->getLog();
        $this->assertStringContainsString("OVERLAP {$a} {$b}", $log);
    }

    /**
     * §1.8: При shared_tasks ≥ 10 лог включает (MEASURED) и matched/n.
     */
    public function testMeasuredLogFormat(): void
    {
        [$a, $b] = $this->bees('ml');
        for ($i = 0; $i < 10; $i++) {
            $match = $i < 5 ? '+(x0,x1)' : '×(x0,x1)';
            $this->tracker->record($a, $b, "task_{$i}", '+(x0,x1)', $match);
        }

        $log = $this->tracker->getMeasuredLog();
        $this->assertStringContainsString("OVERLAP {$a} {$b} 5/10 (MEASURED)", $log);
    }
}
