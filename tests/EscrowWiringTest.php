<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * V0.14 WU-3 (verification-economy): escrow-wiring.
 *
 * Split 30/70: награда за закон — 30% сразу носителю, 70% (env ESCROW_RATIO)
 * в law_escrow. Консенсус V-задач (confirmed >= q) → settle (выплата),
 * провал (refuted/anomaly) → burn + закон UNSTABLE. Grace-период: первые
 * ESCROW_GRACE_TASKS законов домена платят 100% (анти-вымирание, спека).
 */
final class EscrowWiringTest extends TestCase
{
    private string $logFile;

    private Hive $hive;

    protected function setUp(): void
    {
        Database::reset();
        Database::get();
        // Тестируем split-механику: grace-период выключен (grace-путь —
        // отдельное поведение, Default 5 в проде).
        putenv('ESCROW_GRACE_TASKS=0');
        $this->logFile = tempnam(sys_get_temp_dir(), 'escw_');
        $this->hive = new Hive(maxTicks: 0, logFile: $this->logFile);
        $this->hive->run();
    }

    protected function tearDown(): void
    {
        putenv('ESCROW_GRACE_TASKS');
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
        Database::setPath(':memory:');
        Database::reset();
    }

    /**
     * Живой путь recordDiscovery: закон + V-задачи с data_json + escrow split.
     */
    private function discoverLaw(string $formula = '(K2×x0)', string $domain = 'test_esc_dom'): void
    {
        $this->injectRoutedBee();
        $rows = [];
        for ($x = 1; $x <= 12; $x++) {
            $rows[] = [(float) $x, 2.0 * $x];
        }
        $X = array_map(static fn (array $r): array => [$r[0]], $rows);
        $y = array_column($rows, 1);
        $foundAny = false;
        $m = new \ReflectionMethod(Hive::class, 'recordDiscovery');
        $m->setAccessible(true);
        $m->invokeArgs($this->hive, [
            [
                'atom' => $formula,
                'cv' => 0.001,
                'class' => 'EMPIRICAL',
            ],
            [
                'name' => 'esc_w3',
                'domain' => $domain,
                'fingerprint' => 'fp_' . md5($domain),
            ],
            $domain,
            &$foundAny,
            $X,
            $y,
        ]);
    }

    /**
     * В проде routedBee ставит тик-роутинг; здесь инжектирую bootstrap-пчелу
     * (ветка награды под routedBee-гвардом — контракт живого пути).
     */
    private function injectRoutedBee(): void
    {
        $bees = new \ReflectionProperty(Hive::class, 'bees');
        $bees->setAccessible(true);
        $all = $bees->getValue($this->hive);
        $first = array_values(array_filter($all, fn ($b) => $b->isAlive()))[0] ?? null;
        self::assertNotNull($first, 'bootstrap дал живую пчелу');
        $rb = new \ReflectionProperty(Hive::class, 'routedBee');
        $rb->setAccessible(true);
        $rb->setValue($this->hive, $first);
    }

    /**
     * RED: discovery → escrow-запись holding с carrier.
     */
    public function testDiscoveryDepositsEscrowShare(): void
    {
        $this->discoverLaw();

        $row = Database::get()->query(
            "SELECT amount, carrier, status FROM law_escrow WHERE domain = 'test_esc_dom'"
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertNotFalse($row, 'escrow создан при discovery');
        self::assertSame('holding', $row['status']);
        self::assertGreaterThan(0.0, (float) $row['amount'], 'эскроу-доля > 0');
        self::assertNotSame('', $row['carrier'], 'носитель зафиксирован');
    }

    /**
     * RED: подтверждение V-задач → settle, escrow paid.
     */
    public function testConsensusSettlesEscrow(): void
    {
        $this->discoverLaw();
        $this->hive->runPendingVerificationTasks('test_esc_dom', 10);

        $status = (string) Database::get()->query(
            "SELECT status FROM law_escrow WHERE domain = 'test_esc_dom'"
        )->fetchColumn();
        self::assertSame('paid', $status, 'консенсус закрыл escrow выплатой');
    }

    /**
     * RED: опровержение V-задачи → burn + закон UNSTABLE.
     */
    public function testRefutationBurnsEscrow(): void
    {
        $this->discoverLaw();

        Database::get()->prepare(
            "UPDATE verification_tasks SET law_shape = '(C+C)' WHERE kind = 'resample_1'"
        )->execute();
        $this->hive->runPendingVerificationTasks('test_esc_dom', 1);

        $status = (string) Database::get()->query(
            "SELECT status FROM law_escrow WHERE domain = 'test_esc_dom'"
        )->fetchColumn();
        self::assertSame('burned', $status, 'опровержение сжигает эскроу');

        $esc = (string) Database::get()->query(
            "SELECT escrow_status FROM laws WHERE domain = 'test_esc_dom'"
        )->fetchColumn();
        self::assertSame('UNSTABLE', $esc, 'закон помечен UNSTABLE');
    }

    /**
     * RED: неизвестный kind (контракт WU-2) → inconclusive, escrow не тронут.
     */
    public function testUnknownKindLeavesEscrowHolding(): void
    {
        $this->discoverLaw();

        Database::get()->prepare(
            "UPDATE verification_tasks SET kind = 'weird' WHERE kind = 'resample_1'"
        )->execute();
        $this->hive->runPendingVerificationTasks('test_esc_dom', 1);

        $st = (string) Database::get()->query(
            "SELECT status FROM verification_tasks WHERE kind = 'weird'"
        )->fetchColumn();
        self::assertSame('inconclusive', $st, 'неизвестный kind → inconclusive');

        $esc = (string) Database::get()->query(
            "SELECT status FROM law_escrow WHERE domain = 'test_esc_dom'"
        )->fetchColumn();
        self::assertSame('holding', $esc, 'inconclusive не закрывает эскроу');
    }
}
