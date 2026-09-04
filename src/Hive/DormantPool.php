<?php
declare(strict_types=1);

namespace BeeSwarm\Hive;

/**
 * DORMANT OFFSPRING POOL — genotype/phenotype separation.
 *
 * Рецепты (genotype): дешёвые, хранятся как JSON.
 * Материализация (phenotype): дорогая, только при выделении ресурсов.
 *
 * Принцип: «не уничтожить правильную мысль до того, как она проявилась».
 * Каждая пчела гарантированно порождает m детей-рецептов.
 * Только часть детей получает phenotype evaluation.
 */
class DormantPool
{
    /** @var array<int, array{recipe: array, sector: string, novelty: float, age: int, lineage_id: string, awakened?: true, awakened_at?: int}> */
    private array $pool = [];

    private int $nextId = 1;

    /**
     * Положить рецепт в пул (дёшево — не вычисляет phenotype).
     */
    private int $awakenedTimeout;

    public function __construct(int $awakenedTimeout = 300)
    {
        $this->awakenedTimeout = $awakenedTimeout; // 5 мин по умолчанию
    }

    public function deposit(array $recipe, string $sector, float $novelty, string $lineageId = ''): int
    {
        $id = $this->nextId++;
        $this->pool[$id] = [
            'recipe' => $recipe,
            'sector' => $sector,
            'novelty' => $novelty,
            'age' => 0,
            'lineage_id' => $lineageId,
        ];
        return $id;
    }

    /**
     * Извлечь top-K рецептов для материализации по квотам секторов.
     * Не удаляет из пула — помечает как 'awakened'.
     */
    public function awaken(int $k, array $sectorQuotas): array
    {
        // Группировка по секторам
        $bySector = [];
        foreach ($this->pool as $id => $entry) {
            if (isset($entry['awakened'])) {
                continue;
            }
            $sec = $entry['sector'];
            $bySector[$sec][] = ['id' => $id] + $entry;
        }

        $awakened = [];
        $deficit = 0; // сгоревшие квоты для redistribution
        foreach ($sectorQuotas as $sector => $quota) {
            if (!isset($bySector[$sector])) {
                $deficit += $quota;
                continue;
            }
            // Сортировка: novelty desc, потом age asc (моложе = лучше)
            usort($bySector[$sector], function ($a, $b) {
                $cmp = $b['novelty'] <=> $a['novelty'];
                return $cmp !== 0 ? $cmp : $a['age'] <=> $b['age'];
            });
            $take = min($quota, count($bySector[$sector]));
            $deficit += $quota - $take;
            for ($i = 0; $i < $take; $i++) {
                $entry = $bySector[$sector][$i];
                $this->pool[$entry['id']]['awakened'] = true;
                $this->pool[$entry['id']]['awakened_at'] = time();
                $awakened[] = $entry;
            }
        }

        // Redistribution: сгоревшие квоты → лучшие из оставшихся
        $used = count($awakened);
        $remaining = min($k - $used, $deficit);
        if ($remaining > 0) {
            $others = [];
            foreach ($this->pool as $id => $entry) {
                if (!isset($entry['awakened'])) {
                    $others[] = ['id' => $id] + $entry;
                }
            }
            usort($others, fn ($a, $b) => $b['novelty'] <=> $a['novelty']);
            $take = min($remaining, count($others));
            for ($i = 0; $i < $take; $i++) {
                $entry = $others[$i];
                $this->pool[$entry['id']]['awakened'] = true;
                $this->pool[$entry['id']]['awakened_at'] = time();
                $awakened[] = $entry;
            }
        }

        return $awakened;
    }

    /**
     * Старение: увеличить age всех не-awakened. Удалить старше maxAge.
     */
    public function age(int $maxAge = 10): int
    {
        $removed = 0;
        $now = time();
        foreach ($this->pool as $id => $entry) {
            if (isset($entry['awakened'])) {
                // REVIEW deleg_109dc6b6: awakened timeout — утечка в daemon
                if (isset($entry['awakened_at']) && $now - $entry['awakened_at'] > $this->awakenedTimeout) {
                    unset($this->pool[$id]);
                    $removed++;
                }
                continue;
            }
            $this->pool[$id]['age']++;
            if ($this->pool[$id]['age'] > $maxAge) {
                unset($this->pool[$id]);
                $removed++;
            }
        }
        return $removed;
    }

    /**
     * Удалить конкретный рецепт (после materialization).
     */
    public function remove(int $id): void
    {
        unset($this->pool[$id]);
    }

    public function size(): int
    {
        return count($this->pool);
    }

    public function sectorCounts(): array
    {
        $counts = [];
        foreach ($this->pool as $entry) {
            $sec = $entry['sector'];
            $counts[$sec] = ($counts[$sec] ?? 0) + 1;
        }
        return $counts;
    }
}
