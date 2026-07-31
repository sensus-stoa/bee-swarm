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

    /**
     * Spawn child with mutated grammar. Protocol §2.2.
     *
     * @param string[] $available all possible grammar operations
     * @return self|null child Bee or null if spawn conditions not met
     */
    public function spawn(array $available): ?self
    {
        if (! $this->isAlive() || $this->energy < 15.0) {
            return null;
        }

        $this->energy -= 7.0;

        $childGrammar = $this->grammar;
        if (! empty($available)) {
            $childGrammar = GrammarMutator::mutate($this->grammar, $available);
        }

        return new self($childGrammar, 7.0);
    }

    /**
     * §S1.5-HUNGER: при E<5 — мутировать грамматику за счёт энергии.
     * Стоимость: ΔE = −0.5. Не вызывает spawn (только при E≥15).
     */
    public function hungerMutate(array $available): void
    {
        if ($this->energy >= 5.0 || ! $this->isAlive()) {
            return;
        }

        $mutator = new GrammarMutator();
        $this->grammar = $mutator->mutate($this->grammar, $available);
        $this->energy = max(0.0, $this->energy - 0.5);
    }
}
