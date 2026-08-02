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
    private int $generation = 0;
    private int $generationStartPop = 0;

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
