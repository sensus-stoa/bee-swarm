<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

/**
 * Bee — autonomous search agent with energy-based life cycle.
 *
 * Protocol §2.1 + §2.1-эво (Evolvable Energy Params):
 * - Energy costs/rewards are heritable instance properties, NOT hardcoded constants.
 * - Default: tickCost=0.01, searchCost=0.1, discoveryReward=2.0 (protocol baseline).
 * - Spawn mutates energy params by ±20% (bounded).
 * - Natural selection optimises params per environment.
 * - E ≤ 0 → death; dead bees ignore all energy mutations.
 */
class Bee
{
    /** Default tick cost (protocol baseline). */
    public const DEFAULT_TICK_COST = 0.01;

    /** Default search attempt cost (protocol baseline). */
    public const DEFAULT_SEARCH_COST = 0.1;

    /** Default discovery reward (protocol baseline). */
    public const DEFAULT_DISCOVERY_REWARD = 2.0;

    /** Minimum spawn energy threshold. */
    public const SPAWN_THRESHOLD = 15.0;

    /** Energy given to child at spawn. */
    public const SPAWN_CHILD_ENERGY = 7.0;

    /** Energy deducted from parent at spawn. */
    public const SPAWN_PARENT_COST = 7.0;

    /** Mutation range: ±20% of current value. */
    private const MUTATION_RANGE = 0.2;

    /** Param bounds. */
    private const TICK_MIN = 0.001;
    private const TICK_MAX = 0.1;
    private const SEARCH_MIN = 0.01;
    private const SEARCH_MAX = 1.0;
    private const REWARD_MIN = 0.5;
    private const REWARD_MAX = 10.0;
    private const INFO_REWARD_MIN = 0.001;
    private const INFO_REWARD_MAX = 1.0;

    /** Default information reward (intrinsic value of information). */
    public const DEFAULT_INFORMATION_REWARD = 0.0;

    private float $energy;
    private float $tickCost;
    private float $searchCost;
    private float $discoveryReward;
    private float $informationReward;

    /**
     * @var string[] seed grammar operations (inherited from parent)
     */
    private array $grammar;

    /**
     * @var string[] custom ops discovered by this bee (per-bee isolation §2.3)
     */
    private array $customGrammarOps = [];

    /**
     * @param string[] $grammar initial grammar operations
     * @param float $energy starting energy (default 10.0 per protocol)
     * @param float|null $tickCost energy cost per tick (default: DEFAULT_TICK_COST)
     * @param float|null $searchCost energy cost per search attempt (default: DEFAULT_SEARCH_COST)
     * @param float|null $discoveryReward energy reward for discovery (default: DEFAULT_DISCOVERY_REWARD)
     * @param float|null $informationReward energy reward for search attempt itself (default: 0.0)
     * @param string[] $customGrammarOps pre-discovered custom ops (for spawn inheritance)
     */
    public function __construct(
        array $grammar,
        float $energy = 10.0,
        ?float $tickCost = null,
        ?float $searchCost = null,
        ?float $discoveryReward = null,
        ?float $informationReward = null,
        array $customGrammarOps = [],
    ) {
        $this->grammar = array_values($grammar);
        $this->customGrammarOps = array_values($customGrammarOps);
        $this->energy = $energy;
        $this->tickCost = $tickCost ?? self::DEFAULT_TICK_COST;
        $this->searchCost = $searchCost ?? self::DEFAULT_SEARCH_COST;
        $this->discoveryReward = $discoveryReward ?? self::DEFAULT_DISCOVERY_REWARD;
        $this->informationReward = $informationReward ?? self::DEFAULT_INFORMATION_REWARD;
    }

    public function energy(): float
    {
        return $this->energy;
    }

    /** @return float per-instance tick cost */
    public function getTickCost(): float { return $this->tickCost; }
    /** @return float per-instance search cost */
    public function getSearchCost(): float { return $this->searchCost; }
    /** @return float per-instance discovery reward */
    public function getDiscoveryReward(): float { return $this->discoveryReward; }
    /** @return float per-instance information reward */
    public function getInformationReward(): float { return $this->informationReward; }
    /** @return string[] custom grammar ops */
    public function getCustomGrammarOps(): array { return $this->customGrammarOps; }

    /**
     * @return string[] per-bee grammar ops: seed + custom (§2.3 изоляция).
     *         BASE_OPS доступны через Grammar::baseOpNames() и добавляются
     *         в Search::find явно (doDiscoverTick).
     */
    public function grammar(): array
    {
        return array_values(array_unique(array_merge(
            $this->grammar,
            $this->customGrammarOps,
        )));
    }

    /**
     * Добавить операцию в per-bee грамматику (§2.3 изоляция).
     * Другие пчёлы не видят эту операцию.
     */
    public function addToGrammar(string $op): void
    {
        if (! in_array($op, $this->customGrammarOps, true)) {
            $this->customGrammarOps[] = $op;
        }
    }

    // ── Energy param accessors (for mutation & testing) ──

    public function tickCost(): float
    {
        return $this->tickCost;
    }

    public function searchCost(): float
    {
        return $this->searchCost;
    }

    public function discoveryReward(): float
    {
        return $this->discoveryReward;
    }

    public function informationReward(): float
    {
        return $this->informationReward;
    }

    // ── Energy lifecycle ──

    /**
     * Base metabolism — every tick costs energy. Dead bees ignore.
     */
    public function tick(): void
    {
        if (! $this->isAlive()) {
            return;
        }
        $this->energy -= $this->tickCost;
    }

    /**
     * Search attempt costs energy. Dead bees ignore.
     */
    public function chargeSearch(): void
    {
        if (! $this->isAlive()) {
            return;
        }
        $this->energy -= $this->searchCost;
    }

    /**
     * Successful discovery rewards energy. Dead bees ignore (can't resurrect).
     */
    public function rewardDiscovery(): void
    {
        if (! $this->isAlive()) {
            return;
        }
        $this->energy += $this->discoveryReward;
    }

    /**
     * Внутренняя ценность информации: бонус за сам акт поиска,
     * независимо от результата. Nature Neuroscience (Bussell et al., 2026).
     * По умолчанию 0.0 — обратная совместимость.
     */
    public function rewardInformation(): void
    {
        if (! $this->isAlive()) {
            return;
        }
        $this->energy += $this->informationReward;
    }

    public function isAlive(): bool
    {
        return $this->energy > 1e-12;
    }

    /**
     * Spawn child with mutated grammar AND mutated energy params. Protocol §2.2 + §2.1-эво.
     *
     * @param string[] $available all possible grammar operations
     * @return self|null child Bee or null if spawn conditions not met
     */
    public function spawn(array $available): ?self
    {
        if (! $this->isAlive() || $this->energy < self::SPAWN_THRESHOLD) {
            return null;
        }

        $this->energy -= self::SPAWN_PARENT_COST;

        $childGrammar = $this->grammar;
        if (! empty($available)) {
            $childGrammar = GrammarMutator::mutate($this->grammar, $available);
        }

        // Mutate energy params (±MUTATION_RANGE within bounds)
        $childTick = $this->mutateParam($this->tickCost, self::TICK_MIN, self::TICK_MAX);
        $childSearch = $this->mutateParam($this->searchCost, self::SEARCH_MIN, self::SEARCH_MAX);
        $childReward = $this->mutateParam($this->discoveryReward, self::REWARD_MIN, self::REWARD_MAX);
        $childInfoReward = $this->mutateParam($this->informationReward, self::INFO_REWARD_MIN, self::INFO_REWARD_MAX);

        return new self(
            $childGrammar,
            self::SPAWN_CHILD_ENERGY,
            $childTick,
            $childSearch,
            $childReward,
            $childInfoReward,
            $this->customGrammarOps,  // inherit parent's discovered ops
        );
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

    /**
     * §S1.7-NOVELTY: +0.5 энергии за exploration новой задачи.
     */
    public function rewardNovelty(): void
    {
        if (! $this->isAlive()) {
            return;
        }
        $this->energy += 0.5;
    }

    // ── Private helpers ──

    /**
     * Mutate an energy parameter by ±range fraction, clamped to [min, max].
     */
    private function mutateParam(float $value, float $min, float $max, ?float $range = null): float
    {
        $range ??= self::MUTATION_RANGE;
        // Random factor in [1-range, 1+range] = [0.8, 1.2] for range=0.2
        $factor = 1.0 + ((mt_rand(-1000, 1000) / 1000.0) * $range);
        return max($min, min($max, round($value * $factor, 6)));
    }
}
