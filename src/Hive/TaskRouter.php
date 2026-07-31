<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

/**
 * TaskRouter — online density-based routing with emergent domains.
 *
 * No hardcoded domain names. No phase separation.
 * Routes tasks to bees based on:
 * 1. Structural task fingerprint (columns, size, type)
 * 2. Outcome history: which bees solved similar tasks before
 * 3. Cold start / exploration: random routing until patterns emerge
 *
 * Protocol §2.4: distribution must be non-uniform (χ² test).
 * Implementation: history-based specialization → non-uniform.
 */
class TaskRouter
{
    /**
     * @var Bee[]
     */
    private array $bees;

    /**
     * Outcome history: fingerprint hash → bee index → success count.
     * @var array<string, array<int, int>>
     */
    private array $history = [];

    /**
     * @var int exploration ticks remaining before history activates
     */
    private int $explorationTicks;

    /**
     * @var int total tasks routed
     */
    private int $totalRouted = 0;

    /**
     * @param Bee[] $bees
     */
    public function __construct(array $bees, int $explorationTicks = 10)
    {
        $this->bees = array_values($bees);
        $this->explorationTicks = $explorationTicks;
    }

    /**
     * Route task to best-matching alive bee.
     *
     * @param array{data?: array, content?: string} $task
     */
    public function route(array $task): ?Bee
    {
        $alive = $this->getAliveBees();
        if (empty($alive)) {
            return null;
        }

        $fingerprint = $this->fingerprint($task);

        // Exploration: first N ticks or 20% chance → random
        if ($this->explorationTicks > 0 || ($this->totalRouted > 0 && $this->totalRouted % 5 === 0)) {
            $this->explorationTicks = max(0, $this->explorationTicks - 1);
            $this->totalRouted++;
            return $alive[array_rand($alive)];
        }

        // Exploitation: find bee historically successful on similar tasks
        $nearestFingerprint = $this->findNearestFingerprint($fingerprint);
        if ($nearestFingerprint !== null && isset($this->history[$nearestFingerprint])) {
            // Sort bees by success score (descending), pick first alive
            $scored = $this->history[$nearestFingerprint];
            arsort($scored);
            foreach ($scored as $beeIdx => $score) {
                if ($score > 0 && isset($alive[$beeIdx])) {
                    $this->totalRouted++;
                    return $alive[$beeIdx];
                }
            }
        }

        // Fallback: random alive bee
        $this->totalRouted++;
        return $alive[array_rand($alive)];
    }

    /**
     * Record task outcome — success strengthens bee→fingerprint association.
     */
    public function recordOutcome(array $task, Bee $bee, bool $success): void
    {
        $fp = $this->fingerprint($task);
        $beeIdx = array_search($bee, $this->bees, true);
        if ($beeIdx === false) {
            return;
        }

        if (! isset($this->history[$fp])) {
            $this->history[$fp] = [];
        }
        if (! isset($this->history[$fp][$beeIdx])) {
            $this->history[$fp][$beeIdx] = 0;
        }

        $this->history[$fp][$beeIdx] += $success ? 1 : -1;
        // Don't let negative scores accumulate indefinitely
        if ($this->history[$fp][$beeIdx] < -5) {
            $this->history[$fp][$beeIdx] = -5;
        }
    }

    /**
     * Compute task weight for a bee (base + frontier bonus).
     * §S1.4-FRONTIER: tasks with best_CV ∈ [0.01, 0.10] get +0.3 boost.
     */
    public function computeWeight(array $task, Bee $bee): float
    {
        $beeIdx = array_search($bee, $this->bees, true);
        $fp = $this->fingerprint($task);

        // Base weight = Laplace smoothing
        $wins = $this->history[$fp][$beeIdx] ?? 0;
        $total = array_sum($this->history[$fp] ?? []);
        $baseWeight = ($wins + 1) / max(1, $total + count($this->bees));

        // Frontier bonus: almost-solved tasks get priority
        $bestCv = $task['best_cv'] ?? 1.0;
        $frontierBonus = ($bestCv >= 0.01 && $bestCv <= 0.10) ? 0.3 : 0.0;

        return $baseWeight + $frontierBonus;
    }

    /**
     * Structural fingerprint — no domain names.
     * Only measurable properties of the data itself.
     */
    public function fingerprint(array $task): string
    {
        $data = $task['data'] ?? [];
        $nRows = count($data);
        $nCols = $nRows > 0 && is_array($data[0]) ? count($data[0]) : 0;
        $hasText = isset($task['content']) && $task['content'] !== '' ? 'txt' : 'num';

        // Bucket sizes to group similar tasks
        $rowBucket = $nRows < 10 ? 'S' : ($nRows < 50 ? 'M' : 'L');

        return "{$nCols}c:{$rowBucket}:{$hasText}";
    }

    /**
     * Find the most similar fingerprint in history.
     * Uses column count match as primary similarity signal.
     */
    private function findNearestFingerprint(string $fingerprint): ?string
    {
        if (empty($this->history)) {
            return null;
        }

        // Exact match first
        if (isset($this->history[$fingerprint])) {
            return $fingerprint;
        }

        // Partial match: same column count
        $targetParts = explode(':', $fingerprint);
        $targetCols = $targetParts[0];

        foreach ($this->history as $fp => $_) {
            $parts = explode(':', $fp);
            if ($parts[0] === $targetCols) {
                return $fp;
            }
        }

        // No match — fall through to random
        return null;
    }

    /**
     * @return Bee[] indexed by original position
     */
    private function getAliveBees(): array
    {
        $alive = [];
        foreach ($this->bees as $idx => $bee) {
            if ($bee->isAlive()) {
                $alive[$idx] = $bee;
            }
        }
        return $alive;
    }
}
