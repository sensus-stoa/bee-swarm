<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\EscrowStore;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * V0.14 WU-3 (verification-economy): escrow-экономика.
 *
 * Отложенная награда: 30% сразу / 70% в escrow (env ESCROW_RATIO); консенсус
 * V-задач → выплата носителю; провал → сгорание + штраф носителю + UNSTABLE.
 * Grace-период: первые N подтверждений домена платят 100% (анти-вымирание).
 *
 * Контракты WU-2: anomaly → опровержение с причиной (burn), retry-кап при
 * Throwable, тик-wiring runPendingVerificationTasks.
 */
final class EscrowStoreTest extends TestCase
{
    private string $logFile;

    private \BeeSwarm\Hive\Hive $hive;

    protected function setUp(): void
    {
        Database::reset();
        Database::get();
        $this->logFile = tempnam(sys_get_temp_dir(), 'escrow_');
        $this->hive = new \BeeSwarm\Hive\Hive(maxTicks: 0, logFile: $this->logFile);
        $this->hive->run();
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
        Database::setPath(':memory:');
        Database::reset();
    }

    private function store(): EscrowStore
    {
        return new EscrowStore();
    }

    private function lawId(string $formula = '(K2×x0)', string $domain = 'test_esc_dom'): int
    {
        Database::get()->prepare(
            'INSERT INTO laws (name, formula, cv, domain) VALUES (?, ?, 0.001, ?)'
        )->execute(['t', $formula, $domain]);

        return (int) Database::get()->lastInsertId();
    }

    /**
     * RED: deposit создаёт запись escrow с amount, carrier, status=holding.
     */
    public function testDepositCreatesHoldingRecord(): void
    {
        $this->store()
            ->deposit('(K2×x0)', 'test_esc_dom', 1.4, 'bee#3');

        $row = Database::get()->query(
            "SELECT law_formula, domain, amount, carrier, status FROM law_escrow
             WHERE law_formula = '(K2×x0)' AND domain = 'test_esc_dom'"
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertNotFalse($row, 'escrow-запись существует');
        self::assertSame(1.4, (float) $row['amount']);
        self::assertSame('bee#3', $row['carrier']);
        self::assertSame('holding', $row['status']);
    }

    /**
     * RED: повторный deposit того же закона ДОБАВЛЯЕТ к amount (не новый ряд).
     */
    public function testDepositAccumulates(): void
    {
        $this->store()
            ->deposit('(K2×x0)', 'test_esc_dom', 0.7, 'bee#3');
        $this->store()
            ->deposit('(K2×x0)', 'test_esc_dom', 0.7, 'bee#3');

        $n = (int) Database::get()->query(
            "SELECT COUNT(*) FROM law_escrow WHERE law_formula = '(K2×x0)'"
        )->fetchColumn();
        self::assertSame(1, $n, 'одна запись на (formula, domain)');

        $amount = (float) Database::get()->query(
            "SELECT amount FROM law_escrow WHERE law_formula = '(K2×x0)'"
        )->fetchColumn();
        self::assertSame(1.4, $amount);
    }

    /**
     * RED: settle выплачивает носителю amount и закрывает escrow (status=paid).
     */
    public function testSettlePaysCarrier(): void
    {
        $this->store()
            ->deposit('(K2×x0)', 'test_esc_dom', 1.4, 'bee#3');
        $payout = $this->store()
            ->settle('(K2×x0)', 'test_esc_dom', 'VCONFIRMED');

        self::assertSame(1.4, $payout);
        $status = (string) Database::get()->query(
            "SELECT status FROM law_escrow WHERE law_formula = '(K2×x0)'"
        )->fetchColumn();
        self::assertSame('paid', $status);
    }

    /**
     * RED: settle на holding-записи повторно платит 0 (одноразовость).
     */
    public function testSettleIsOneShot(): void
    {
        $this->store()
            ->deposit('(K2×x0)', 'test_esc_dom', 1.4, 'bee#3');
        $first = $this->store()
            ->settle('(K2×x0)', 'test_esc_dom', 'VCONFIRMED');
        $second = $this->store()
            ->settle('(K2×x0)', 'test_esc_dom', 'VCONFIRMED');

        self::assertSame(1.4, $first);
        self::assertSame(0.0, $second, 'повторный settle ничего не платит');
    }

    /**
     * RED: burn сжигает escrow (status=burned), возвращает сгоревшую сумму.
     */
    public function testBurnDestroysEscrow(): void
    {
        $this->store()
            ->deposit('(K2×x0)', 'test_esc_dom', 1.4, 'bee#3');
        $burned = $this->store()
            ->burn('(K2×x0)', 'test_esc_dom', 'VREFUTED');

        self::assertSame(1.4, $burned);
        $status = (string) Database::get()->query(
            "SELECT status FROM law_escrow WHERE law_formula = '(K2×x0)'"
        )->fetchColumn();
        self::assertSame('burned', $status);
    }

    /**
     * RED: carrier отсутствует в bee_persistence → settle/burn не падают.
     */
    public function testCarrierGracefulWithoutBee(): void
    {
        $this->store()
            ->deposit('(K2×x0)', 'test_esc_dom', 1.4, 'ghost');
        self::assertSame(1.4, $this->store()->settle('(K2×x0)', 'test_esc_dom', 'VCONFIRMED'));
        $this->store()
            ->deposit('(K3×x0)', 'test_esc_dom2', 0.8, 'ghost');
        self::assertSame(0.8, $this->store()->burn('(K3×x0)', 'test_esc_dom2', 'VREFUTED'));
    }
}
