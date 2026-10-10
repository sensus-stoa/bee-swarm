<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

use BeeSwarm\Infra\Database;

/**
 * S2.3-IGNORANCE (§3.3): failed_attempts — персистентная память отказов.
 *
 * Append-only audit trail (S1.10 дух): первый отказ каждого task_fingerprint
 * фиксируется (UNIQUE + INSERT OR IGNORE); повторы того же отказа строк не
 * плодят. Retention нет, история не переписывается.
 *
 * НАБЛЮДАТЕЛЬ: вызов стоит в живом doDiscoverTick рядом с FAILED-логом;
 * любая ошибка записи не рвёт discovery-путь (partialBirth после хука не
 * должен теряться из-за DB-сбоя). Дропы (SQLITE_BUSY при совпадении с
 * CLI-записью, verify против живой БД — premortem 10.10) считаются:
 * getDropped() даёт наблюдаемость неполной таблицы.
 *
 * ГРАНИЦА (review 10.10 MED-3, real-accepted): first-write-wins — повторный
 * отказ того же fingerprint (дрейф диагноза GRAMMAR→DEPTH, новый bestCv)
 * НЕ обновляет строку. Это память «первого незнания класса», не текущего;
 * дрейф-семантика (UPSERT/attempts_count) — контракт полной стори
 * S1.10-FAIL, не этот первый слой.
 */
final class FailedAttemptRegistry
{
    private static int $dropped = 0;

    public static function record(
        string $taskFingerprint,
        string $taskName,
        string $domain,
        string $diagnosis,
        ?float $cvBest,
        ?string $evidence,
        ?int $tick
    ): void {
        if ($taskFingerprint === '') {
            return; // без идентификатора dedup не имеет смысла (S1.7-гвард)
        }
        try {
            self::insert($taskFingerprint, $taskName, $domain, $diagnosis, $cvBest, $evidence, $tick);
        } catch (\Throwable) {
            // Наблюдатель: отказ записи не рвёт discovery-путь.
            // busy_timeout=0 (default) → SQLITE_BUSY дропается мгновенно;
            // счётчик — единственная наблюдаемость выборочно неполной таблицы.
            self::$dropped++;
        }
    }

    private static function insert(
        string $fp,
        string $taskName,
        string $domain,
        string $diagnosis,
        ?float $cvBest,
        ?string $evidence,
        ?int $tick
    ): void {
        Database::get()->prepare(
            'INSERT OR IGNORE INTO failed_attempts
             (task_fingerprint, task_name, domain, diagnosis, cv_best, evidence, tick)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$fp, $taskName, $domain, $diagnosis, $cvBest, $evidence, $tick]);
    }

    public static function getDropped(): int
    {
        return self::$dropped;
    }

    public static function resetDropped(): void
    {
        self::$dropped = 0;
    }
}
