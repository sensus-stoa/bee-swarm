<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

/**
 * §2.6 Environmental Pressure — конечность и порча задач.
 *
 * Задача, не решённая за K тиков, выбрасывается из пула и считается
 * упущенной возможностью (MISSED_OPPORTUNITY). Возраст задачи — возраст
 * в пуле: consume-on-pick (weightedPick → consumeTask) означает, что
 * «не решена» == «висит в пуле дольше K тиков».
 *
 * Параметры: env ENV_TIMEOUT_K (тиков, default 200). Legacy-задачи без
 * штампа env_birth_tick не выбрасываются никогда (fail-safe: включение
 * механики не массово чистит исторический пул).
 */
final class EnvPressure
{
    /**
     * Ключ штампа рождения задачи в тике (внутри task-массива).
     */
    public const BIRTH_KEY = 'env_birth_tick';

    public const ENV_TIMEOUT_K = 'ENV_TIMEOUT_K';

    /**
     * Default K, если env не задан.
     */
    public const DEFAULT_K = 200;

    public const ENV_TASKS_PER_HOUR = 'TASKS_PER_HOUR';

    /**
     * Окно R-адмиссии: скользящий час (секунды).
     */
    public const ADMISSION_WINDOW_S = 3600;

    /**
     * @var list<float> timestamps admitted-задач скользящего окна
     */
    private static array $admissionLog = [];

    /**
     * Штамповать пачку задач одним тиком.
     *
     * @param array<int, array<string, mixed>> $tasks
     * @return array<int, array<string, mixed>>
     */
    public static function stampAll(array $tasks, int $tick): array
    {
        foreach ($tasks as $i => $t) {
            $tasks[$i] = self::stamp($t, $tick);
        }

        return $tasks;
    }

    /**
     * Штамповать задачу тиком рождения. Идемпотентно: существующий штамп
     * не перезаписывается (возраст живёт от первой вставки).
     *
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    public static function stamp(array $task, int $tick): array
    {
        if (isset($task[self::BIRTH_KEY])) {
            return $task;
        }
        $task[self::BIRTH_KEY] = $tick;

        return $task;
    }

    /**
     * K из env; ≥1 (PHP-falsy гвард: '0' → default через !== false).
     */
    public static function timeoutK(): int
    {
        $raw = getenv(self::ENV_TIMEOUT_K);
        $k = (int) ($raw !== false ? $raw : (string) self::DEFAULT_K);

        return max(1, $k);
    }

    /**
     * Выбрать просроченные задачи (age >= K). Пул не мутирует — возвращает
     * [collected, remaining, logLines].
     *
     * @param array<int, array<string, mixed>> $pool
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: list<string>}
     */
    public static function collectExpired(array $pool, int $tick, int $k): array
    {
        $collected = [];
        $remaining = [];
        $logLines = [];
        foreach ($pool as $task) {
            $birth = $task[self::BIRTH_KEY] ?? null;
            if ($birth === null || ! is_int($birth)) {
                // Fail-safe: unstamped (legacy) — бессмертна.
                $remaining[] = $task;
                continue;
            }
            $age = $tick - $birth;
            if ($age >= $k) {
                $collected[] = $task;
                $name = is_string($task['name'] ?? null) ? $task['name'] : '?';
                $logLines[] = "MISSED_OPPORTUNITY: {$name} age={$age} K={$k}";
                continue;
            }
            $remaining[] = $task;
        }

        return [$collected, $remaining, $logLines];
    }

    /**
     * §2.6 WU-3: R-адмиссия — поток задач ограничен R новых задач в час.
     *
     * Скользящее окно 3600с (timestamps хранит static-кэш процесса; для
     * тестов передаётся $now). Выключено (default): env TASKS_PER_HOUR
     * не задан → всё пропускается, R = измеряемая величина verify_1_6,
     * а не механизм. Лимит применяется к ВСТАВКАМ новых задач.
     *
     * @param array<int, array<string, mixed>> $tasks
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: list<string>}
     */
    public static function admitAll(array $tasks, float $now): array
    {
        $raw = getenv(self::ENV_TASKS_PER_HOUR);
        if ($raw === false) {
            return [$tasks, [], []];
        }
        $r = max(1, (int) $raw);

        // Скользящее окно: отсекаем timestamps старше окна.
        $admitted = [];
        $discarded = [];
        $logs = [];
        foreach ($tasks as $t) {
            self::pruneAdmission($now);
            if (count(self::$admissionLog) >= $r) {
                $name = is_string($t['name'] ?? null) ? $t['name'] : '?';
                $discarded[] = $t;
                $logs[] = "R_DISCARD: {$name} R={$r}";
                continue;
            }
            self::$admissionLog[] = $now;
            $admitted[] = $t;
        }

        return [$admitted, $discarded, $logs];
    }

    /**
     * Сброс admission-окна (тесты; процесс демона живёт с непрерывным окном).
     */
    public static function resetAdmission(): void
    {
        self::$admissionLog = [];
    }

    private static function pruneAdmission(float $now): void
    {
        $cutoff = $now - self::ADMISSION_WINDOW_S;
        self::$admissionLog = array_values(array_filter(
            self::$admissionLog,
            fn (float $ts): bool => $ts > $cutoff
        ));
    }
}
