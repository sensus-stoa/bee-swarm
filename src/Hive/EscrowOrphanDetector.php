<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

use BeeSwarm\Infra\Database;

/**
 * ESCROW-ORPHAN-DEPOSITS WU-1 (prereg 17.10): наблюдаемость висячих
 * депозитов. Сирота = law_escrow (status='holding') без ЕДИНОЙ V-задачи
 * по (law_formula, domain).
 *
 * Причины сиротства (все под один симптом):
 *  1. спавн подавлен: NO_DREAM_SPAWN / NO_VERIFY_SPAWN / DREAM_SPAWN_MAX_PER_TICK;
 *  2. VTS persist failure — persist() глушит PDOException (наблюдатель-контракт),
 *     spawn-попытка была, строк нет.
 *
 * Депозит-сирота никогда не закроется: settle/burn достижимы только через
 * исполнителя V-задач. Связка по (law_formula, domain): оба писателя через
 * один канон (fake-LOSS урок); law_id намеренно НЕ используется —
 * research-задачи пишут 0. Задачи не удаляются (только статусуются:
 * pending/cancelled/inconclusive/verdict) — существование строки честно
 * отражает факт спавна. Детектор только читает (SELECT).
 *
 * Опциональный $pdo — для read-only отчётов по чужой БД
 * (scripts/escrow_orphans_report.php: sqlite ?mode=ro).
 *
 * ⚠️ Фоллбэк Database::get() запускает DDL/миграции на open — это ПИШЕТ в
 * БД (dual-review 17.10). Engine-контекст/тесты (:memory:) — легитимно;
 * любой отчёт по прод-БД ОБЯЗАН инжектить read-only PDO, не полагаться
 * на фоллбэк.
 */
final class EscrowOrphanDetector
{
    public function __construct(private readonly ?\PDO $pdo = null)
    {
    }

    /**
     * ESCROW-ORPHAN-DEPOSITS WU-2: есть ли у закона VERIFICATION-задачи
     * (kind <> inverted_research — та же семантика, что у orphans()).
     * Deposit-time probe для payDiscovery: проверка на момент выплаты
     * устойчива к ЛЮБЫМ причинам сиротства (спавн подавлен / VTS persist
     * failure / будущие), в отличие от флага из спавн-блока (open question
     * prereg решён).
     */
    public function hasVerificationTasks(string $lawFormula, string $domain): bool
    {
        $stmt = ($this->pdo ?? Database::get())->prepare(
            'SELECT 1 FROM verification_tasks
             WHERE law_formula = ? AND domain = ? AND (kind IS NULL OR kind <> ?)
             LIMIT 1'
        );
        $stmt->execute([$lawFormula, $domain, VerificationTaskSource::KIND_INVERTED_RESEARCH]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * @return list<array{law_formula: string, domain: string, amount: float, carrier: string, updated_at: string}>
     */
    public function orphans(): array
    {
        $stmt = ($this->pdo ?? Database::get())->prepare(
            'SELECT e.law_formula, e.domain, COALESCE(e.amount, 0) AS amount, e.carrier, e.updated_at
             FROM law_escrow e
             WHERE e.status = ?
               AND NOT EXISTS (
                   SELECT 1 FROM verification_tasks v
                   WHERE v.law_formula = e.law_formula
                     AND v.domain = e.domain
                     AND (v.kind IS NULL OR v.kind <> ?)
               )
             ORDER BY e.domain, e.law_formula'
        );
        // kind-фильтр (dual-review 17.10, medium): research-задача
        // (inverted_research, law_id=0) пишет тот же law_formula — без фильтра
        // она маскирует сироту (ложный негатив ровно на целевой болезни).
        // Сирота = нет ни одной VERIFICATION-задачи (resample/inverted).
        // Нюанс статусов (all-cancelled при живом holding) — семантика WU-4
        // abandoned-gate, не WU-1.
        $stmt->execute([EscrowStore::STATUS_HOLDING, VerificationTaskSource::KIND_INVERTED_RESEARCH]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(static fn (array $r): array => [
            'law_formula' => (string) $r['law_formula'],
            'domain' => (string) $r['domain'],
            'amount' => (float) $r['amount'],
            'carrier' => (string) $r['carrier'],
            'updated_at' => (string) $r['updated_at'],
        ], $rows);
    }
}
