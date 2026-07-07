<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

/**
 * Bee — autonomous search agent with energy-based life cycle.
 *
 * Protocol §2.1:
 * - E₀ = 10.0
 * - ΔE_tick = −0.01 (метаболизм)
 * - ΔE_search = −0.1 (попытка поиска)
 * - ΔE_discovery = +2.0 (открытие)
 * - E ≤ 0 → death; dead bees ignore all energy mutations
 */
class Bee
{
    private const TICK_COST = 0.01;

    private const SEARCH_COST = 0.1;

    private const DISCOVERY_REWARD = 2.0;

    private float $energy;

    /**
     * @var string[] grammar operations
     */
    private array $grammar;

    /**
     * @param string[] $grammar initial grammar operations
     * @param float $energy starting energy (default 10.0 per protocol)
     */
    public function __construct(array $grammar, float $energy = 10.0)
    {
        $this->grammar = array_values($grammar);
        $this->energy = $energy;
    }

    public function energy(): float
    {
        return $this->energy;
    }

    /**
     * @return string[]
     */
    public function grammar(): array
    {
        return $this->grammar;
    }

    /**
     * Base metabolism — every tick costs energy. Dead bees ignore.
     */
    public function tick(): void
    {
        if (! $this->isAlive()) {
            return;
        }
        $this->energy -= self::TICK_COST;
    }

    /**
     * Search attempt costs energy. Dead bees ignore.
     */
    public function chargeSearch(): void
    {
        if (! $this->isAlive()) {
            return;
        }
        $this->energy -= self::SEARCH_COST;
    }

    /**
     * Successful discovery rewards energy. Dead bees ignore (can't resurrect).
     */
    public function rewardDiscovery(): void
    {
        if (! $this->isAlive()) {
            return;
        }
        $this->energy += self::DISCOVERY_REWARD;
    }

    public function isAlive(): bool
    {
        return $this->energy > 1e-12;
    }
}
