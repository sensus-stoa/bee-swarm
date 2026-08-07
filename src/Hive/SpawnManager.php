<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

/**
 * SpawnManager — логика спавна и поколений (§2.2, §2.5).
 *
 * Извлечён из Hive::doTick(). D17.
 */
class SpawnManager
{
    private int $spawnCount = 0;
    private int $gapSpawnCount = 0;  // S1.2 Phase 4: separate from regular spawns
    private int $generation = 0;
    private int $generationStartPop = 0;
    private bool $gapSpawnFired = false;  // S1.2 Phase 4: cooldown

    /**
     * S1.2 Phase 4: Gap-Triggered Spawn.
     *
     * Пороги: 5× plateauThreshold (с новыми данными) или 10× (без).
     */
    public function tryGapSpawn(array &$bees, array $allOps, bool $isPlateau, int $plateauTicks, bool $hasNewData, int $plateauThreshold = 50): int
    {
        $thresholdNewData = 5 * $plateauThreshold;
        $thresholdFallback = 10 * $plateauThreshold;

        if (! $isPlateau) {
            $this->gapSpawnFired = false;  // сброс при выходе из plateau
            return 0;
        }

        if ($this->gapSpawnFired) {
            return 0;  // cooldown: один spawn за plateau-период
        }

        $shouldSpawn = ($plateauTicks >= $thresholdNewData && $hasNewData)
            || ($plateauTicks >= $thresholdFallback);

        if (! $shouldSpawn) {
            return 0;
        }

        // GRAMMAR-DEGRADATION (06.08): монокультура (уникальных грамматик < 3)
        // → FORCED seed-разнообразие: рожаем +, ×, min вместо клонов.
        // Иначе |G|=1 самоподдерживается: клоны не находят законов.
        // ТОЛЬКО при живых пчёлах: all-dead обрабатывает SEED_SPAWN (trySpawn).
        $hasAlive = false;
        $uniqueGrammars = [];
        foreach ($bees as $bee) {
            // ТОЛЬКО живые в подсчёте разнообразия: мёртвые маскируют
            // монокультуру (CONCERNS deleg_0a2963d2)
            if ($bee->isAlive()) {
                $uniqueGrammars[implode(',', $bee->grammar())] = true;
                $hasAlive = true;
            }
        }
        if ($hasAlive && count($uniqueGrammars) < 3) {
            // ПАРЫ операторов, не одиночки: |G|=1 не выражает законы —
            // seed'ы умрут так же, как монокультура. |G|=2 выразимо.
            $seedSets = [['+', '×'], ['−', '/'], ['min', 'sq']];
            $added = 0;
            foreach ($seedSets as $seedGrammar) {
                $key = implode(',', $seedGrammar);
                if (! isset($uniqueGrammars[$key])) {
                    $bees[] = new Bee($seedGrammar, 10.0);
                    $uniqueGrammars[$key] = true;
                    $added++;
                }
            }
            $this->gapSpawnFired = true; // cooldown: один spawn за plateau
            return $added;
        }

        $parent = null;
        foreach ($bees as $bee) {
            if ($bee->isAlive() && $bee->energy() > 0.0) {
                $parent = $bee;
                break;
            }
        }

        if ($parent === null) {
            return 0;
        }

        $childEnergy = min(10.0, $parent->energy() * 1.5);
        $childGrammar = $parent->grammar();
        if (! empty($allOps)) {
            $childGrammar = \BeeSwarm\Hive\GrammarMutator::mutate($childGrammar, $allOps);
        }

        $child = new Bee(
            $childGrammar,
            $childEnergy,
            $parent->getTickCost(),
            $parent->getSearchCost(),
            $parent->getDiscoveryReward(),
            $parent->getInformationReward(),
            $parent->getCustomGrammarOps(),
        );

        $bees[] = $child;
        // Gap spawns counted separately — не влияют на generation tracking
        $this->gapSpawnCount++;
        $this->gapSpawnFired = true;

        return 1;
    }

    /**
     * Попытаться spawn: проверка порога → мутация → рождение.
     *
     * @param Bee[] $bees текущая популяция (по ссылке — добавляем потомков)
     * @param string[] $allOps доступные операции грамматики
     * @return int количество новых спавнов
     */
    public function trySpawn(array &$bees, array $allOps): int
    {
        $spawned = 0;
        $aliveCount = 0;
        foreach ($bees as $bee) {
            if ($bee->isAlive()) {
                $aliveCount++;
            }
        }

        // AUDIT 05.08 §2.7 SEED_SPAWN: ALL_DEAD → рой обязан воскреснуть.
        // Раньше trySpawn спавнил только от живых — при всех мёртвых
        // (kill -9 / энергия ≤ 0) рой умирал навсегда.
        if ($aliveCount === 0 && ! empty($bees)) {
            $seedOps = ['+', '×', 'min'];
            foreach ($seedOps as $op) {
                $bees[] = new \BeeSwarm\Hive\Bee([$op]);
                $spawned++;
                $this->spawnCount++;
            }
            return $spawned;
        }

        foreach ($bees as $idx => $bee) {
            if (! $bee->isAlive()) {
                continue;
            }
            $child = $bee->spawn($allOps);
            if ($child !== null) {
                $bees[] = $child;
                $this->spawnCount++;
                $spawned++;
            }
        }

        // Generation tracking (§2.5)
        if (count($bees) > 0) {
            $this->generationStartPop = max($this->generationStartPop, 1);
        }
        if ($this->spawnCount >= $this->generationStartPop && $this->generationStartPop > 0) {
            $this->generation++;
            $this->spawnCount = 0;
            $this->generationStartPop = count($bees);
        }

        return $spawned;
    }

    public function getGeneration(): int
    {
        return $this->generation;
    }

    public function getSpawnCount(): int
    {
        return $this->spawnCount;
    }

    public function getGenerationStartPop(): int
    {
        return $this->generationStartPop;
    }

    /**
     * Вычислить diversity (доля уникальных грамматик).
     *
     * @param Bee[] $bees
     */
    public static function computeDiversity(array $bees): float
    {
        $alive = array_filter($bees, fn (Bee $b) => $b->isAlive());
        if (count($alive) === 0) {
            return 0.0;
        }
        $grammars = array_map(fn (Bee $b) => implode(',', $b->grammar()), $alive);
        $unique = count(array_unique($grammars));
        return round($unique / count($alive), 3);
    }

    /**
     * Средний размер грамматики.
     *
     * @param Bee[] $bees
     */
    public static function avgGrammarSize(array $bees): float
    {
        $alive = array_filter($bees, fn (Bee $b) => $b->isAlive());
        if (count($alive) === 0) {
            return 0.0;
        }
        $sizes = array_map(fn (Bee $b) => count($b->grammar()), $alive);
        return round(array_sum($sizes) / count($sizes), 2);
    }
}
