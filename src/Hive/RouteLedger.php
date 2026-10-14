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
    /** Неудачных запусков до отзыва ресурса. */
    public const REVOKE_AFTER_FAILS = 2;

    /** Порог значимого улучшения L_valid. */
    public const WIN_DELTA = 0.05;

    /** @var array<string,array<string,mixed>> id => genome */
    private array $routes = [];

    /** @var array<string,bool> */
    private array $revoked = [];

    /** @var array<string,list<array{task_fp:string, delta:float, certified:bool, wall:float, unspent:float}>> */
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
    ): void {
        if (! isset($this->routes[$id])) {
            throw new \InvalidArgumentException("unknown route {$id}");
        }
        $win = $delta > self::WIN_DELTA || $routeCertified;
        $g = &$this->routes[$id];
        $g['record']['tasks_seen']++;
        $g['record']['delta_L_valid_sum'] += $delta;
        if ($win) {
            $g['record']['wins']++;
        } else {
            $g['record']['fails']++;
        }
        $g['record']['wall_sum'] += $wall;
        $g['record']['unspent_quota'] += $unspent;
        $this->outcomes[$id][] = ['task_fp' => $taskFp, 'delta' => $delta,
            'certified' => $routeCertified, 'wall' => $wall, 'unspent' => $unspent];
        // Неудачный маршрут теряет право на дальнейшие ресурсы (спека §5).
        if ($g['record']['fails'] >= self::REVOKE_AFTER_FAILS) {
            $this->revoke($id);
        }
    }

    public function revoke(string $id): void
    {
        $this->revoked[$id] = true;
    }

    public function isRevoked(string $id): bool
    {
        return isset($this->revoked[$id]);
    }

    /** @return list<array<string,mixed>> */
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

    /** @return list<array<string,mixed>> */
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
        $complexity = is_countable($genome['actions'] ?? null) ? count($genome['actions']) : 0;

        return $mean - RouteMutator::COMPLEXITY_PENALTY * $complexity;
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

    /** @return array{routes:list, revoked:list<string>, outcomes:array} */
    public function export(): array
    {
        return ['routes' => array_values($this->routes),
            'revoked' => array_keys($this->revoked), 'outcomes' => $this->outcomes];
    }
}
