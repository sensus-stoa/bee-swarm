<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

use BeeSwarm\Infra\Database;

/**
 * §2.6 Environmental Pressure — адаптация сложности (boundary of capabilities).
 *
 * Окно последних WINDOW исходов (solved/failed):
 * - solve rate >= RISE_THRESHOLD (0.9) → D+1 (next depth, больше фич);
 * - solve rate <= FALL_THRESHOLD (0.1) → D-1 (floor 1);
 * - иначе — держим уровень.
 *
 * После смены уровня окно сбрасывается — свежий замер на новом уровне
 * (анти-осцилляция: старые исходы не «дотягивают» новый уровень).
 *
 * Персистентность: env_state (key=difficulty_level). Уровень меняет
 * ГЕНЕРАЦИЮ задач (filterByDifficulty применяется к пулу при выборке),
 * не исторические данные.
 */
final class DifficultyGovernor
{
    public const ENV_KEY = 'difficulty_level';

    public const WINDOW = 20;

    public const RISE_THRESHOLD = 0.9;

    public const FALL_THRESHOLD = 0.1;

    public const MIN_LEVEL = 1;

    /**
     * Минимальный размер пула после фильтра: меньше — fallback на весь пул.
     */
    public const MIN_POOL = 3;

    /**
     * @var list<bool> окно исходов
     */
    private array $window = [];

    private int $difficulty;

    public function __construct(?int $difficulty = null)
    {
        $this->difficulty = $difficulty ?? self::loadLevel();
    }

    /**
     * Записать исход задачи. Возвращает 'rise'|'fall'|null (null = нет смены).
     */
    public function recordOutcome(bool $solved): ?string
    {
        $this->window[] = $solved;
        if (count($this->window) < self::WINDOW) {
            return null;
        }

        $rate = count(array_filter($this->window)) / self::WINDOW;
        $event = null;
        if ($rate >= self::RISE_THRESHOLD) {
            $event = $this->rise();
        } elseif ($rate <= self::FALL_THRESHOLD) {
            $event = $this->fall();
        }
        if ($event === null) {
            // Без смены — скользящее окно (выкидываем самый старый исход).
            array_shift($this->window);
        }

        return $event;
    }

    public function difficulty(): int
    {
        return $this->difficulty;
    }

    /**
     * Фильтр генерации: D → минимальный nFeat (D=2 → nFeat≥2, D=1 — no-op).
     * Text/semantic задачи (без числового data) не режутся. Fallback: если
     * после фильтра остался пул < MIN_POOL — возвращаем исходный (среда
     * не голодает искусственно из-за уровня, который она сама и создала).
     *
     * @param array<int, array<string, mixed>> $tasks
     * @return array<int, array<string, mixed>>
     */
    public static function filterByDifficulty(array $tasks, int $difficulty): array
    {
        if ($difficulty <= self::MIN_LEVEL) {
            return $tasks;
        }
        $minFeat = $difficulty;
        $kept = [];
        foreach ($tasks as $t) {
            if (! isset($t['data']) || ! is_array($t['data'])) {
                $kept[] = $t;
                continue;
            }
            $row = $t['data'][0] ?? null;
            if (! is_array($row) || count($row) - 1 >= $minFeat) {
                $kept[] = $t;
            }
        }
        if (count($kept) < self::MIN_POOL) {
            return $tasks;
        }

        return $kept;
    }

    private function rise(): string
    {
        $this->difficulty++;
        $this->saveLevel();
        $this->window = [];

        return 'rise';
    }

    private function fall(): ?string
    {
        if ($this->difficulty <= self::MIN_LEVEL) {
            // Флор: смены нет, окно скользит дальше (не «залипаем»).
            array_shift($this->window);

            return null;
        }
        $this->difficulty--;
        $this->saveLevel();
        $this->window = [];

        return 'fall';
    }

    private static function loadLevel(): int
    {
        $stmt = Database::get()->prepare(
            'SELECT value FROM env_state WHERE key = ?'
        );
        $stmt->execute([self::ENV_KEY]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return self::MIN_LEVEL;
        }

        return max(self::MIN_LEVEL, (int) $row['value']);
    }

    private function saveLevel(): void
    {
        Database::get()->prepare(
            'INSERT INTO env_state (key, value) VALUES (?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value,
             updated_at = datetime(\'now\')'
        )->execute([self::ENV_KEY, (string) $this->difficulty]);
    }
}
