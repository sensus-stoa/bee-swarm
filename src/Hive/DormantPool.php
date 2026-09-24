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
    /**
     * @var array<int, array{recipe: array, sector: string, novelty: float, age: int, lineage_id: string, awakened?: true, awakened_at?: int}>
     */
    private array $pool = [];

    private int $nextId = 1;

    /**
     * DORMANT-CONFIG (24.09, premortem И-5): ёмкость пула. Deposit при
     * переполнении вытесняет НЕ-awakened резидента с минимальным novelty
     * (при равенстве — старший age, ближе к естественной чистке age()).
     * Awakened pinned — уходят только по awakenedTimeout. Худший приход
     * вытесняет сам себя. 0 = неограничен (тесты, текущее поведение).
     */
    private int $capacity = 0;

    /**
     * Положить рецепт в пул (дёшево — не вычисляет phenotype).
     */
    private int $awakenedTimeout;

    public function __construct(int $awakenedTimeout = 300, int $capacity = 0)
    {
        $this->awakenedTimeout = $awakenedTimeout; // 5 мин по умолчанию
        $this->capacity = max(0, $capacity);
    }

    public function deposit(array $recipe, string $sector, float $novelty, string $lineageId = ''): int
    {
        // Замечание: при self-eviction (all-pinned переполнение) id всё же
        // возвращается — best-effort OOM-кэш, запись не гарантируется.
        $id = $this->nextId++;
        $this->pool[$id] = [
            'recipe' => $recipe,
            'sector' => $sector,
            'novelty' => $novelty,
            'age' => 0,
            'lineage_id' => $lineageId,
        ];

        if ($this->capacity > 0 && count($this->pool) > $this->capacity) {
            $this->evictOne($id);
        }

        return $id;
    }

    /**
     * Вытеснить одного не-awakened резидента с минимальным merit
     * (novelty asc, age desc). Только что пришедший не защищён — если он
     * худший, уходит сам (пул не держит мусор за счёт резидентов).
     */
    private function evictOne(int $justArrivedId): void
    {
        $victimId = null;
        $victimMerit = null;
        foreach ($this->pool as $id => $entry) {
            if (isset($entry['awakened'])) {
                continue; // pinned: уходят только по awakenedTimeout
            }
            // merit = novelty asc, age desc (старший слабее — ближе к вылету)
            $merit = [$entry['novelty'], -$entry['age']];
            if ($victimMerit === null || $merit < $victimMerit) {
                $victimMerit = $merit;
                $victimId = $id;
            }
        }

        if ($victimId !== null) {
            unset($this->pool[$victimId]);
        }
        // All-pinned возможен (awaken не удаляет резидентов — уходят только
        // по awakenedTimeout): при переполнении без не-awakened жертв
        // новоприбывший вытесняет сам себя (правило худшего прихода,
        // total-capacity контракт).
        if (count($this->pool) > $this->capacity) {
            unset($this->pool[$justArrivedId]);
        }
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
            $bySector[$sec][] = [
                'id' => $id,
            ] + $entry;
        }

        $awakened = [];
        $deficit = 0; // сгоревшие квоты для redistribution
        foreach ($sectorQuotas as $sector => $quota) {
            if (! isset($bySector[$sector])) {
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
                if (! isset($entry['awakened'])) {
                    $others[] = [
                        'id' => $id,
                    ] + $entry;
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

    /**
     * DORMANT-PERSIST (24.09, triage R4): материализовать пул в dormant_pool
     * (полная замена — состояние пула = источник истины на момент save;
     * прецедент savePopulation). Возвращает число записанных строк.
     */
    public function saveToDb(): int
    {
        $db = \BeeSwarm\Infra\Database::get();
        $db->beginTransaction();
        try {
            $db->exec('DELETE FROM dormant_pool');
            $stmt = $db->prepare(
                'INSERT INTO dormant_pool (pool_id, recipe, sector, novelty, age, lineage_id, awakened, awakened_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $n = 0;
            foreach ($this->pool as $id => $entry) {
                $stmt->execute([
                    $id, json_encode($entry['recipe']), $entry['sector'], $entry['novelty'],
                    $entry['age'], $entry['lineage_id'],
                    isset($entry['awakened']) ? 1 : 0, $entry['awakened_at'] ?? null,
                ]);
                $n++;
            }
            $db->commit();

            return $n;
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * DORMANT-PERSIST: восстановить пул из dormant_pool. nextId
     * продолжается за max(pool_id) — id не переиспользуются (remove() чужих
     * записей невозможен). Возвращает число восстановленных записей.
     */
    public function loadFromDb(): int
    {
        $db = \BeeSwarm\Infra\Database::get();
        $rows = $db->query('SELECT pool_id, recipe, sector, novelty, age, lineage_id, awakened, awakened_at FROM dormant_pool ORDER BY pool_id')
            ->fetchAll(\PDO::FETCH_ASSOC);

        $maxId = 0;
        foreach ($rows as $row) {
            $recipe = json_decode($row['recipe'], true);
            if (! is_array($recipe)) {
                continue; // битая строка не валит рестарт (fail-safe, как loadPopulation)
            }
            $entry = [
                'recipe' => $recipe,
                'sector' => $row['sector'],
                'novelty' => (float) $row['novelty'],
                'age' => (int) $row['age'],
                'lineage_id' => $row['lineage_id'],
            ];
            if ((int) $row['awakened'] === 1) {
                $entry['awakened'] = true;
                // NULL-awakened_at при awakened=1 save'ом не создаётся;
                // fallback time() — допустимая аппроксимация (criterion-audit DP#3)
                $entry['awakened_at'] = $row['awakened_at'] !== null ? (int) $row['awakened_at'] : time();
            }
            $this->pool[(int) $row['pool_id']] = $entry;
            $maxId = max($maxId, (int) $row['pool_id']);
        }
        $this->nextId = max($this->nextId, $maxId + 1);

        return count($this->pool);
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
