<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

/**
 * Story S1.6: Weighted Task Selection.
 */
class WeightedTaskSelectionTest extends TestCase
{
    /**
     * RED: 1 узкая (nFeat=1) + 9 широких (nFeat=9).
     * array_rand → узкая ~10%. Weighted → узкая ~50%.
     *
     * Predicted: ≤30 узких из 200 (array_rand даст ~20).
     */
    public function testNarrowWeightedHigher(): void
    {
        $tasks = [];
        $tasks[] = ['name' => 'narrow', 'data' => [[1, 2]]];                    // nFeat=1, weight=1.0
        for ($i = 0; $i < 9; $i++) {
            $tasks[] = ['name' => "wide_$i", 'data' => [array_fill(0, 10, 0)]]; // nFeat=9, weight≈0.11
        }

        $narrow = 0;
        $trials = 200;
        for ($i = 0; $i < $trials; $i++) {
            $t = $this->weightedPick($tasks);
            if ($t['name'] === 'narrow') $narrow++;
        }

        // array_rand: ~20. Weighted: ~100. Порог 30 разделяет.
        $this->assertGreaterThan(30, $narrow,
            "Narrow only {$narrow}/{$trials}. Still using array_rand?");
    }

    public function testWeightedPickEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->weightedPick([]);
    }

    public function testWeightedPickSingle(): void
    {
        $tasks = [['name' => 'only', 'data' => [[1]]]];
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame('only', $this->weightedPick($tasks)['name']);
        }
    }

    // ═══ GREEN: через Hive::weightedPick ═══

    private function weightedPick(array $tasks): array
    {
        // Доступ к private методу через reflection
        static $method = null;
        if ($method === null) {
            $method = new \ReflectionMethod(\BeeSwarm\Hive\Hive::class, 'weightedPick');
        }
        // Создаём минимальный hive для доступа к методу
        $hive = new \BeeSwarm\Hive\Hive(plateau: new \BeeSwarm\Infra\PlateauDetector(50, plateauSleepUs: 0), maxTicks: 0);
        return $method->invoke($hive, $tasks);
    }
}
