<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

use BeeSwarm\Infra\Database;

/**
 * V0.14 WU-3 (verification-economy): эскроу отложенной награды.
 *
 * Спека: «закон получает энергию НЕ сразу, а после подтверждения
 * верификационными задачами». 70% награды (env ESCROW_RATIO) держится здесь:
 * консенсус V-задач → settle (выплата носителю), провал → burn (сгорание
 * в dissip-фонд + штраф носителю через AtomPenalty-инфраструктуру WU-2-лога).
 *
 * Ключ (law_formula, domain) — канон-формула, единая нормализация с laws
 * (fake-LOSS инвариант). Одноразовость settle/burn: transition holding→paid/burned.
 */
final class EscrowStore
{
    public const STATUS_HOLDING = 'holding';

    public const STATUS_PAID = 'paid';

    public const STATUS_BURNED = 'burned';

    /**
     * Положить сумму в эскроу закона. Повторный deposit ДОБАВЛЯЕТ к amount
     * (re-discovery закона наращивает ставку), carrier — последний носитель.
     */
    public function deposit(string $lawFormula, string $domain, float $amount, string $carrier): void
    {
        if ($amount <= 0.0) {
            return;
        }
        // Agent-review (b): guard по статусу — re-deposit на paid/burned
        // записи молча наращивал бы невыплачиваемую сумму (утечка 70%).
        // Не-holding статус = депозит игнорируется (последний носитель уже
        // определён, цикл закрыт; re-open цикла — решение WU-5).
        Database::get()->prepare(
            'INSERT INTO law_escrow (law_formula, domain, amount, carrier, status)
             VALUES (?,?,?,?,?)
             ON CONFLICT(law_formula, domain) DO UPDATE SET
               amount = amount + excluded.amount,
               carrier = excluded.carrier,
               updated_at = datetime(\'now\')
             WHERE law_escrow.status = ?'
        )->execute([
            $lawFormula, $domain, $amount, $carrier, self::STATUS_HOLDING,
            self::STATUS_HOLDING,
        ]);
    }

    /**
     * Консенсус достигнут: выплатить носителю. Возвращает выплаченную сумму
     * (0 = эскроу уже закрыт или пуст).
     */
    public function settle(string $lawFormula, string $domain, string $reason): float
    {
        return $this->close($lawFormula, $domain, self::STATUS_PAID, $reason);
    }

    /**
     * Провал верификации: сжечь эскроу. Возвращает сгоревшую сумму.
     */
    public function burn(string $lawFormula, string $domain, string $reason): float
    {
        return $this->close($lawFormula, $domain, self::STATUS_BURNED, $reason);
    }

    /**
     * Сумма, находящаяся в holding по закону (для лога/наблюдаемости WU-6).
     */
    public function holdingAmount(string $lawFormula, string $domain): float
    {
        $stmt = Database::get()->prepare(
            "SELECT amount FROM law_escrow
             WHERE law_formula = ? AND domain = ? AND status = 'holding'"
        );
        $stmt->execute([$lawFormula, $domain]);
        $v = $stmt->fetchColumn();

        return $v === false ? 0.0 : (float) $v;
    }

    /**
     * Одноразовое закрытие: UPDATE...WHERE status='holding' — гонки
     * last-writer-wins невозможны, второй close вернёт 0.
     */
    private function close(string $lawFormula, string $domain, string $status, string $reason): float
    {
        $stmt = Database::get()->prepare(
            'UPDATE law_escrow
             SET status = ?, reason = ?, updated_at = datetime(\'now\')
             WHERE law_formula = ? AND domain = ? AND status = ?'
        );
        $stmt->execute([$status, $reason, $lawFormula, $domain, self::STATUS_HOLDING]);
        if ($stmt->rowCount() === 0) {
            return 0.0;
        }
        $sel = Database::get()->prepare(
            'SELECT amount FROM law_escrow WHERE law_formula = ? AND domain = ?'
        );
        $sel->execute([$lawFormula, $domain]);

        return (float) $sel->fetchColumn();
    }
}
