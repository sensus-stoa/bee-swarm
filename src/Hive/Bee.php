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

    /** SPAWN-POOL Фаза C: бонус энергии новой линии (новизна рода). */
    public const EXPLORATION_BONUS = 1.0;

    /** Mutation range: ±20% of current value. */
    private const MUTATION_RANGE = 0.2;

    /** Param bounds. */
    private const TICK_MIN = 0.001;
    private const TICK_MAX = 0.1;
    private const SEARCH_MIN = 0.01;
    private const SEARCH_MAX = 1.0;
    private const REWARD_MIN = 0.5;
    // SHRINK-AND-PERSIST (08.08): голод = замедление, не смерть.
    // Порог 3.0 — ниже порога hunger-мутации (5.0): адаптация до голода,
    // спячка после. Множитель 0.1 — жизнь ×10 на малом расходе.
    // (Е)-параметры модели; эволюция в геноме — следующий шаг.
    private const STARVATION_THRESHOLD = 3.0;
    private const STARVATION_MULTIPLIER = 0.1;
    private const REWARD_MAX = 10.0;
    private const INFO_REWARD_MIN = 0.001;
    private const INFO_REWARD_MAX = 1.0;

    /** Default information reward (intrinsic value of information). */
    public const DEFAULT_INFORMATION_REWARD = 0.0;

    private float $energy;
    private int $birthTick = 0; // LIFETIME-METRIC (07.08)
    private array $satiety = []; // DOMAIN-SATIETY (08.08): классы по доменам
    private const SATIETY_K = 3; // после K классов в домене — ×0.1
    private const SATIETY_FIRST_BOOST = 1.5; // первый класс в домене
    private const SATIETY_DIM = 0.1;
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
     * §2.5.14 анти-осцилляция: атомы, деградированные этой пчелой (lifetime).
     * Повторная деградация того же атома той же пчелой запрещена, даже если
     * атом вернулся в грамматику (re-discovery из пула). Не наследуется при
     * spawn — ребёнок начинает с чистой историей деградации.
     */
    private array $degradedLifetime = [];

    /**
     * S1.6-GRADIENT WU-1/WU-2: последняя signal-форма + тик её получения.
     * Не наследуется при spawn (границы версии: наследование hint —
     * отдельная эволюционная механика).
     *
     * @var array{0: string, 1: int}|null [formula, tick]
     */
    private ?array $signalHint = null;

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

    /** SPAWN-POOL Фаза C: id линии (родословной). */
    private string $lineageId = '';

    /** SPAWN-POOL Фаза C: id родительской линии ('' для seed). */
    private string $parentLineageId = '';

    public function lineageId(): string
    {
        return $this->lineageId;
    }

    public function parentLineageId(): string
    {
        return $this->parentLineageId;
    }

    /** Задать родословную (при материализации из пула или спавне). */
    public function setLineage(string $lineageId, string $parentLineageId = ''): void
    {
        $this->lineageId = $lineageId;
        $this->parentLineageId = $parentLineageId;
    }

    /** Сектор линии (из id: lin_SECTOR_tick_n). */
    public function lineageSector(): string
    {
        if ($this->lineageId === '' || ! preg_match('/^lin_([A-Za-z]+)_/', $this->lineageId, $m)) {
            return 'unknown';
        }
        return $m[1];
    }

    /**
     * SPAWN-POOL (27.08, resource-bounded evolution): порождение m
     * дешёвых рецептов-потомков (genotype). Никакой оценки — только
     * описание операций из грамматики ПЧЕЛЫ.
     *
     * Сектора по ведущей операции рецепта:
     *   −,+ → ADDITIVE/DIFF; × → PRODUCT; / → RATIO; sq,sqrt → POWER
     *
     * @return array<int, array{op: string, operand: string, sector: string}>
     */
    public function emitRecipes(int $m): array
    {
        $recipes = [];
        // Операции пчелы — только те, что реально в её грамматике
        $ops = [];
        foreach ($this->grammar() as $opName) {
            if (! is_string($opName)) {
                continue;
            }
            $ops[] = $opName;
        }
        if ($ops === []) {
            return $recipes;
        }

        $count = count($ops);
        for ($i = 0; $i < $m; $i++) {
            $op = $ops[$i % $count];
            $recipes[] = [
                'op' => $op,
                'operand' => 'x' . (($i * 7) % 8),   // детерминированный разброс фич
                'sector' => self::classifyOp($op),
            ];
        }
        return $recipes;
    }

    /** SPAWN-POOL: сектор операции для квот пула. */
    public static function classifyOp(string $op): string
    {
        return match ($op) {
            '+', 'add' => 'ADDITIVE',
            '−', '-', 'sub' => 'DIFF',
            '×', '*', 'mul' => 'PRODUCT',
            '/', 'div' => 'RATIO',
            'sq', 'sqrt', 'cube', 'inv' => 'POWER',
            default => 'unknown',
        };
    }

    /**
     * Добавить операцию в per-bee грамматику (§2.3 изоляция).
     * Другие пчёлы не видят эту операцию.
     */
    public function getBirthTick(): int
    {
        return $this->birthTick;
    }

    public function registerClass(string $domain): void
    {
        $this->satiety[$domain] = ($this->satiety[$domain] ?? 0) + 1;
    }

    public function discoveryMultiplier(string $domain): float
    {
        $n = $this->satiety[$domain] ?? 0;
        if ($n === 1) {
            return self::SATIETY_FIRST_BOOST; // новый домен — поощрение
        }
        if ($n > self::SATIETY_K) {
            return self::SATIETY_DIM; // насыщение — «я сыт»
        }
        return 1.0;
    }

    public function setBirthTick(int $tick): void
    {
        $this->birthTick = $tick;
    }

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
        // SHRINK-AND-PERSIST (08.08, ECONOMICS п.1): голод ≠ смерть,
        // голод = замедление. При E < порога метаболизм ×0.1 —
        // пчела живёт в 10 раз дольше на малом расходе.
        $cost = $this->energy < self::STARVATION_THRESHOLD
            ? $this->tickCost * self::STARVATION_MULTIPLIER
            : $this->tickCost;
        $this->energy -= $cost;
    }

    /**
     * V0.14 (verification-economy): денежный штраф носителя сгоревшего закона.
     * Зеркало chargePartialReward: мёртвые пчёлы не платят, энергия не уходит
     * ниже нуля (голод сам убивает — штраф не нужен как отдельная причина смерти).
     */
    public function chargePenalty(float $amount): void
    {
        if (! $this->isAlive() || $amount <= 0.0) {
            return;
        }
        $this->energy = max(0.0, $this->energy - $amount);
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
     * V0.14 WU-3 (escrow): прямая выплата части награды (30% split), без
     * reuse-бонусов/фильтров — их применяет rewardDiscovery на grace-пути.
     * Мёртвые пчёлы не воскресают.
     */
    public function chargePartialReward(float $amount): void
    {
        if (! $this->isAlive() || $amount <= 0.0) {
            return;
        }
        $this->energy += $amount;
    }

    /**
     * Successful discovery rewards energy. Dead bees ignore (can't resurrect).
     */
    public function rewardDiscovery(float $multiplier = 1.0, ?string $formula = null, bool $hasFeatures = true): void
    {
        if (! $this->isAlive()) {
            return;
        }
        // REUSE-REWARD (11.08): бонус кооперации — закон с B-атомом
        // (reuse культуры!) кормит ×1.5; TRANSFER-бонус ф2: атом с reuse
        // в ≥2 доменах (перенос! transfer²) — ×2.0.
        if ($formula !== null && preg_match('/B\d+[0-9a-f]*/', $formula, $m) === 1) {
            $mult = 1.5;
            try {
                // BLOCK deleg_8458f590: 'search' — ТЕХНИЧЕСКИЙ домен
                // (find-хит), не знаниевый! transfer = атом применён в
                // ≥2 РЕАЛЬНЫХ доменах (reuse_domains БЕЗ 'search').
                $cur = \BeeSwarm\Infra\Database::get()->prepare(
                    'SELECT reuse_domains FROM grammar_ops WHERE name = ?'
                );
                $cur->execute([$m[0]]);
                $domains = json_decode((string) ($cur->fetchColumn() ?: '[]'), true) ?: [];
                $real = array_values(array_filter(
                    $domains,
                    fn ($d) => is_string($d) && $d !== 'search'
                ));
                if (count($real) >= 2) {
                    $mult = 2.0; // атом ПЕРЕНОСИТСЯ между доменами
                }
            } catch (\Throwable $e) {
                // БД недоступна — базовый reuse-бонус
            }
            $multiplier *= $mult;
        }
        // NO-REWARD-FOR-NONBUILDERS (09.08): не кормят:
        // (а) тени: простые атомы без операторов (abs, floor, x0) — любая
        //     монотонная функция на монотонных данных даёт CV=0;
        // (б) константные композиции БЕЗ фич (×(min), mul(add)) —
        //     структурный мусор, не закон. $hasFeatures вычисляется в Hive
        //     по colLabels (CONCERNS deleg_ceef5093: регекс [xX]\d+ не
        //     покрывал реальные метки pop/deaths_cum).
        if ($formula !== null) {
            $hasOps = (bool) preg_match('/[+×−\/(]/', $formula);
            if (! $hasOps || ! $hasFeatures) {
                return;
            }
        }
        $this->energy += $this->discoveryReward * $multiplier;
    }

    /**
     * Внутренняя ценность информации: бонус за сам акт поиска,
     * независимо от результата. Nature Neuroscience (Bussell et al., 2026).
     * По умолчанию 0.0 — обратная совместимость.
     */
    public function rewardInformation(): void
    {
        if (! $this->isAlive()) return;
        $this->energy += $this->informationReward;
    }

    /**
     * S1.6-GRADIENT: partial reward for signal (ε < CV ≤ null_floor).
     * Не закон, но «здесь что-то есть, копай».
     */
    public function rewardSignal(): void
    {
        if (! $this->isAlive()) return;
        $this->energy += 0.5;
    }

    /**
     * S1.6-GRADIENT WU-1: запомнить последнюю signal-форму.
     * Форма — на языке роя (инфикс, напр. '(x0maxx1)'). Тик — для WU-2 TTL.
     */
    public function signalHint(string $formula, int $tick = 0): void
    {
        if (! $this->isAlive()) return;
        $this->signalHint = [$formula, $tick];
    }

    /**
     * S1.6-GRADIENT WU-2: множители последней signal-формы с ГЛАДКИМ
     * затуханием. Возраст hint = currentTick − hintTick; пока возраст ≤ TTL
     * (env SIGNAL_GRADIENT_TTL, default 10) возвращается карта op ⇒ multiplier,
     * линейно вычитаемый от SIGNAL_GRADIENT_BIAS (возраст 0) до 1.0
     * (возраст == TTL) — «вычитание веса, не обнуление». Несвежий/отсутствующий/
     * без известных ops → null (мутация слепая).
     *
     * @return array<string,float>|null op ⇒ весовой множитель (≥1.0)
     */
    public function signalPreferredOps(int $currentTick = 0, ?int $ttl = null): ?array
    {
        if ($this->signalHint === null) {
            return null;
        }
        [$formula, $hintTick] = $this->signalHint;
        // PHP-falsy гвард (premortem H2): '0' — falsy строка, ?: подставил бы
        // default 10, ломая operational rollback. TTL=0 = hint отключён.
        $raw = getenv('SIGNAL_GRADIENT_TTL');
        $ttl = $ttl ?? ($raw !== false ? (int) $raw : 10);
        if ($ttl <= 0) {
            return null; // TTL=0: сигнал выключен целиком
        }
        $age = $currentTick - $hintTick;
        // Отрицательный возраст = рассинхрон часов (default currentTick=0 /
        // потерянный тик — premortem H4): невалидный вызов → слепая мутация.
        if ($age < 0 || $age > $ttl) {
            return null;
        }
        // Джиттер-гвард: биас 0.0 = выкл (вес 0 = запрет op — недопустимо)
        $bias = max(1.0001, (float) (getenv('SIGNAL_GRADIENT_BIAS') ?: '2.0'));
        // Гладкое затухание: multiplier = 1 + (bias−1)×(1 − age/TTL)
        $multiplier = $bias;
        if ($ttl > 0 && $age > 0) {
            $multiplier = 1.0 + ($bias - 1.0) * (1.0 - $age / $ttl);
        }
        // Известные операторы внутри инфиксной формы: '+' '×' '−' '/' 'min' 'max' 'sq'
        $known = ['+', '×', '−', '/', 'min', 'max', 'sq'];
        $ops = [];
        foreach ($known as $op) {
            if (str_contains($formula, $op)) {
                $ops[$op] = $multiplier;
            }
        }

        return $ops === [] ? null : $ops;
    }

    public function isAlive(): bool
    {
        return $this->energy > 1e-12;
    }

    /**
     * Spawn child with mutated grammar AND mutated energy params. Protocol §2.2 + §2.1-эво.
     *
     * @param string[] $available all possible grammar operations
     * @param int $currentTick S1.6-GRADIENT WU-2: тик роя для TTL signal-hint
     * @return self|null child Bee or null if spawn conditions not met
     */
    public function spawn(array $available, int $currentTick = 0): ?self
    {
        if (! $this->isAlive() || $this->energy < self::SPAWN_THRESHOLD) {
            return null;
        }

        $this->energy -= self::SPAWN_PARENT_COST;

        $childGrammar = $this->grammar;
        if (! empty($available)) {
            // GRAMMAR-PROPAGATION (ЭКСП-012/016): weights + уровень культуры
            $weights = getenv('PROPAGATION') === '0' ? null : \BeeSwarm\Core\Grammar::weightsFromDb();
            $culture = (float) (getenv('CULTURE_LEVEL') ?: '1.0');
            // S1.6-GRADIENT WU-1/WU-2: мутация грамматики ребёнка потребляет
            // signal-hint родителя с TTL по тику роя (это НЕ наследование hint —
            // ребёнок получает только грамматику, сам hint у него null)
            $preferred = $this->signalPreferredOps($currentTick);
            $childGrammar = GrammarMutator::mutate($this->grammar, $available, $weights, $culture, $preferred);
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
     * §2.5.14 AUTOPHAGY (замена §S1.5-HUNGER случайной мутации):
     * селективная деградация грамматики при голодании. При 3≤E<5 пчела
     * уступает наименее ценные атомы в общий пул за ΔE=+0.5/атом.
     * SHRINK (08.08): E<3 — спячка, деградации нет (зомби не раздувает).
     *
     * @param float|null $populationMedian медиана utility кандидатов по рою
     *        (Hive вычисляет один раз на тик; null = fallback на свою)
     * @return string[] деградированные атомы (порядок деградации)
     */
    public function autophagy(?float $populationMedian = null): array
    {
        if ($this->energy >= 5.0 || $this->energy < 3.0 || ! $this->isAlive()) {
            return [];
        }

        $engine = new AutophagyEngine();
        $degraded = [];
        while ($this->energy < AutophagyEngine::STOP_ENERGY) {
            $atom = $engine->degradeOne(
                $this->grammar(),
                array_keys($this->degradedLifetime),
                $populationMedian
            );
            if ($atom === null) {
                break;
            }
            $this->grammar = array_values(array_diff($this->grammar, [$atom]));
            $this->customGrammarOps = array_values(array_diff($this->customGrammarOps, [$atom]));
            $this->degradedLifetime[$atom] = true;
            $this->energy += AutophagyEngine::DEGRADE_ENERGY;
            $degraded[] = $atom;
        }
        return $degraded;
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
