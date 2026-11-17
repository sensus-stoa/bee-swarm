<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

/**
 * EXP-045 Route Heredity — реестр маршрутов прогона: учёт результатов,
 * отзыв ресурса у неудачников, скоринг для размножения.
 *
 * Селекция по СПЕКЕ: улучшение относительно baseline (delta L_valid),
 * стоимость (wall), штраф за сложность. Успех на одном датасете не
 * размножает маршрут — учёт мульти-задачный (tasks_seen).
 *
 * Fitness ЗАКОНА (энергия пчелы, A19) и fitness МАРШРУТА (этот класс)
 * принципиально не смешиваются: маршрут не получает энергию.
 */
final class RouteLedger
{
    /**
     * Неудачных запусков до отзыва ресурса.
     */
    public const REVOKE_AFTER_FAILS = 2;

    /**
     * Порог значимого улучшения L_valid.
     */
    public const WIN_DELTA = 0.05;

    /**
     * Stage A fitness fix (PREREG_STAGE_A_EVOLUTION §2.2-в): вес novel-cert
     * win'а в score. 1.0 = novel-cert доминирует над маржинальной дельтой
     * (дельты в driver-канале ≡ 0 — структурно; см. AUDIT).
     */
    public const WIN_WEIGHT = 1.0;

    /**
     * MATERIAL QUALITY (negative selection): штраф за junk в fitness.
     * Junk (min/max композиции) должен стоить дороже, чем полезное,
     * чтобы поиск не генерировал его на уровне источника.
     */
    public const JUNK_PENALTY = 2.0;

    /**
     * WU ROAD-YIELD (E1-lite, prereg PREREG_E1LITE_ROADYIELD): вес
     * эффективности (mean unspent quota, [0..1]). Дорожный маршрут
     * решает быстрее — переиспользует материал — оставляет бюджет.
     * Непрерывный член: работает там, где cert=0 (жёсткие задачи).
     */
    public const EFFICIENCY_WEIGHT = 0.5;

    /**
     * WU ROAD-YIELD: вес прямой дорожной пользы (mean topk_useful —
     * сколько прочитанных меток реально помогли поиску). Evolvednm
     * физически = 0 (дорога выключена) — селекционный канал пары
     * evolved/evolvednm. Вклад 0..2 при yield 0..10 (сопоставим с
     * WIN_WEIGHT=1.0); junk-штраф 2.0 по-прежнему доминирует.
     */
    public const ROAD_YIELD_WEIGHT = 0.2;

    /**
     * @var array<string,array<string,mixed>> id => genome
     */
    private array $routes = [];

    /**
     * @var array<string,bool>
     */
    private array $revoked = [];

    /**
     * @var array<string,list<array{task_fp:string, delta:float, certified:bool, wall:float, unspent:float}>>
     */
    private array $outcomes = [];

    public function register(array $genome): void
    {
        $this->routes[$genome['id']] = $genome;
    }

    public function get(string $id): ?array
    {
        return $this->routes[$id] ?? null;
    }

    /**
     * delta = L_valid(baseline best partial) − L_valid(route best);
     * положительный = маршрут улучшил частичную гипотезу на valid-хвосте.
     */
    public function recordOutcome(
        string $id,
        string $taskFp,
        float $delta,
        bool $routeCertified,
        float $wall,
        float $unspent,
        float $roadYield = 0.0,
    ): void {
        if (! isset($this->routes[$id])) {
            throw new \InvalidArgumentException("unknown route {$id}");
        }
        $win = $delta > self::WIN_DELTA || $routeCertified;
        // psalm-фикс (18.10): &$this->routes[$id] (property reference) не
        // анализируется psalm'ом (321). Семантика та же: мутация локальной
        // копии + write-back ДО revoke (revoke читает уже обновлённый record).
        $g = $this->routes[$id];
        $g['record']['tasks_seen']++;
        $g['record']['delta_L_valid_sum'] += $delta;
        if ($win) {
            $g['record']['wins']++;
        } else {
            $g['record']['fails']++;
        }
        $g['record']['wall_sum'] += $wall;
        $g['record']['unspent_quota'] += $unspent;
        $g['record']['road_yield_sum'] = ($g['record']['road_yield_sum'] ?? 0.0) + $roadYield;
        $this->routes[$id] = $g;
        $this->outcomes[$id][] = [
            'task_fp' => $taskFp,
            'delta' => $delta,
            'certified' => $routeCertified,
            'wall' => $wall,
            'unspent' => $unspent,
        ];
        // Неудачный маршрут теряет право на дальнейшие ресурсы (спека §5).
        if ($g['record']['fails'] >= self::REVOKE_AFTER_FAILS) {
            $this->revoke($id);
        }
    }

    public function revoke(string $id): void
    {
        $this->revoked[$id] = true;
    }

    /** Buffer-1 retention (owner 05.11): cancel a revocation — a genome with
     * partial-credit survival keeps its line alive despite fails. */
    public function retain(string $id): void
    {
        unset($this->revoked[$id]);
    }

    public function isRevoked(string $id): bool
    {
        return isset($this->revoked[$id]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function active(): array
    {
        $out = [];
        foreach ($this->routes as $id => $g) {
            if (! isset($this->revoked[$id])) {
                $out[] = $g;
            }
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        return array_values($this->routes);
    }

    /**
     * Скор отбора: средний delta L_valid по задачам минус штраф за длину.
     * Маршрут без наблюдений несигнален (0.0, но проигрывает любому
     * с положительным средним после сортировки).
     */
    public function score(array $genome): float
    {
        $seen = (int) ($genome['record']['tasks_seen'] ?? 0);
        $sum = (float) ($genome['record']['delta_L_valid_sum'] ?? 0.0);
        $mean = $seen > 0 ? $sum / $seen : 0.0;
        $wins = (int) ($genome['record']['wins'] ?? 0);
        $complexity = is_countable($genome['actions'] ?? null) ? count($genome['actions']) : 0;
        $junkFraction = (float) ($genome['record']['junk_fraction'] ?? 0.0);

        // Stage A fitness fix (PREREG_STAGE_A_EVOLUTION §2.2-в): delta ≡ 0 в
        // driver-канале → без wins-члена score = −complexity (отбор коротких,
        // градиента нет). Novel-cert wins — прямой селекционный сигнал.
        // MATERIAL QUALITY (negative selection): штраф за junk в fitness.
        // Junk (min/max композиции) должен стоить дороже, чем полезное,
        // чтобы поиск не генерировал его на уровне источника.
        $junkPenalty = self::JUNK_PENALTY * $junkFraction;
        // WU ROAD-YIELD: непрерывные дорожные члены (см. консты выше).
        // mean по задачам — те же единицы, что $mean (индивидуальный канал).
        $meanUnspent = $seen > 0 ? (float) ($genome['record']['unspent_quota'] ?? 0.0) / $seen : 0.0;
        $meanRoadYield = $seen > 0 ? (float) ($genome['record']['road_yield_sum'] ?? 0.0) / $seen : 0.0;
        return $mean + self::WIN_WEIGHT * $wins - RouteMutator::COMPLEXITY_PENALTY * $complexity - $junkPenalty
            + self::EFFICIENCY_WEIGHT * $meanUnspent
            + self::ROAD_YIELD_WEIGHT * $meanRoadYield;
    }

    /**
     * Родители для размножения: активные, с наблюдениями, по score DESC.
     *
     * @return list<array<string,mixed>>
     */
    public function topForReproduction(int $k): array
    {
        $cands = array_filter($this->active(), fn (array $g): bool => (int) $g['record']['tasks_seen'] > 0);
        usort($cands, fn (array $a, array $b): int => $this->score($b) <=> $this->score($a));

        return array_slice(array_values($cands), 0, $k);
    }

    /**
     * @return array{routes:list, revoked:list<string>, outcomes:array}
     */
    public function export(): array
    {
        return [
            'routes' => array_values($this->routes),
            'revoked' => array_keys($this->revoked),
            'outcomes' => $this->outcomes,
        ];
    }
}
