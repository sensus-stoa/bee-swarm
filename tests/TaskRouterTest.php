<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Bee;
use BeeSwarm\Hive\TaskRouter;

/**
 * Story S1.4: Online density-based task routing with emergent domains
 *
 * No hardcoded domain names. Bees specialize through outcome history.
 * Structural task fingerprint (columns, size, type) replaces domain labels.
 */
class TaskRouterTest extends TestCase
{
    /**
     * Dead bees are excluded from routing
     */
    public function testExcludesDeadBees(): void
    {
        $deadBee = new Bee(['add', 'mul'], 0.0);
        $aliveBee = new Bee(['sub', 'div'], 10.0);
        $router = new TaskRouter([$deadBee, $aliveBee], explorationTicks: 0);

        $bee = $router->route($this->numericTask(3, 20));
        $this->assertSame($aliveBee, $bee, 'Dead bee must be excluded');
    }

    /**
     * After recording success, router sends similar tasks to same bee
     */
    public function testHistoryBasedRouting(): void
    {
        $bee1 = new Bee(['add', 'mul', 'sub'], 10.0);
        $bee2 = new Bee(['lag', 'shift'], 10.0);
        $router = new TaskRouter([$bee1, $bee2], explorationTicks: 0);

        // Bee1 succeeds on 3-column numeric task
        $task3col = $this->numericTask(3, 20);
        $router->recordOutcome($task3col, $bee1, true);

        // Route similar task 10 times — should go to bee1
        $bee1Count = 0;
        foreach (range(1, 10) as $i) {
            if ($router->route($this->numericTask(3, 25)) === $bee1) {
                $bee1Count++;
            }
        }

        $this->assertGreaterThan(
            5,
            $bee1Count,
            'After bee1 success on 3-col task, similar tasks should route to bee1'
        );
    }

    /**
     * Exploration phase (first N ticks) routes randomly
     */
    public function testExplorationPhaseIsRandom(): void
    {
        $bee1 = new Bee(['add'], 10.0);
        $bee2 = new Bee(['sub'], 10.0);
        $router = new TaskRouter([$bee1, $bee2], explorationTicks: 100);

        // Record success for bee1 — but exploration still active
        $router->recordOutcome($this->numericTask(3, 20), $bee1, true);

        // Even with history, exploration forces random for 100 ticks
        $bee1Count = 0;
        $bee2Count = 0;
        foreach (range(1, 30) as $i) {
            $bee = $router->route($this->numericTask(3, 20));
            if ($bee === $bee1) {
                $bee1Count++;
            }
            if ($bee === $bee2) {
                $bee2Count++;
            }
        }

        // Both bees should get tasks during exploration
        $this->assertGreaterThan(0, $bee1Count, 'Bee1 should get some tasks');
        $this->assertGreaterThan(0, $bee2Count, 'Bee2 should get some tasks');
    }

    /**
     * Structural fingerprint groups similar tasks without domain names
     */
    public function testFingerprintGroupsSimilarTasks(): void
    {
        $router = new TaskRouter([new Bee(['add'], 10.0)]);

        $fp1 = $this->getFingerprint($router, $this->numericTask(2, 30));
        $fp2 = $this->getFingerprint($router, $this->numericTask(2, 40));
        $fp3 = $this->getFingerprint($router, $this->numericTask(5, 30));

        // Same columns + size bucket = same fingerprint
        $this->assertSame($fp1, $fp2, '2-col M-size tasks must have identical fingerprint');
        // Different columns = different fingerprint
        $this->assertNotEquals($fp2, $fp3, '2-col vs 5-col must differ');
    }

    private function numericTask(int $cols, int $rows): array
    {
        $data = [];
        foreach (range(1, $rows) as $i) {
            $data[] = array_fill(0, $cols, (float) $i);
        }
        return [
            'data' => $data,
        ];
    }

    private function getFingerprint(TaskRouter $router, array $task): string
    {
        $ref = new \ReflectionMethod($router, 'fingerprint');
        return $ref->invoke($router, $task);
    }
}
