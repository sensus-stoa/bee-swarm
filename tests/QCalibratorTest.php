<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;
use BeeSwarm\Hive\QCalibrator;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * V0.14 WU-4 (verification-economy): q-калибровка консенсуса.
 *
 * Спека: «q = ceil(θ · median(подтверждений на нуллах) + 1)», θ из env;
 * RED-критерий: noise-домен (подтверждений нет ни у одного закона) → q
 * недостижим/минимален; exact-закон 5/5 → q ≤ 5. Экономика: q поднимает
 * порог settle выше уровня шума домена (ложные законы не покупают PAID).
 *
 * Нулл-статистика домена = подтверждения ложных открытий: законы с
 * confirmed_count == 0 при usage_count > 1 (переоткрывались, не подтвердились).
 */
final class QCalibratorTest extends TestCase
{
    private string $logFile;

    private Hive $hive;

    protected function setUp(): void
    {
        Database::reset();
        Database::get();
        $this->logFile = tempnam(sys_get_temp_dir(), 'qcal_');
        $this->hive = new Hive(maxTicks: 0, logFile: $this->logFile);
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

    /**
     * Посеять закон с заданными счётчиками (usage_count, confirmed_count).
     */
    private function seedLaw(string $domain, int $usage, int $confirmed, string $formula = '(K2×x0)'): void
    {
        Database::get()->prepare(
            'INSERT INTO laws (name, formula, cv, domain, usage_count, confirmed_count)
             VALUES (?, ?, 0.01, ?, ?, ?)'
        )->execute(['n', $formula, $domain, $usage, $confirmed]);
    }

    /**
     * RED: noise-домен (все законы 0 подтверждений) → q = 1 (шум не воспроизводится).
     */
    public function testNoiseDomainGivesMinimalQ(): void
    {
        $this->seedLaw('test_noise_dom', 3, 0, '(A×x0)');
        $this->seedLaw('test_noise_dom', 2, 0, '(B+x1)');
        $this->seedLaw('test_noise_dom', 4, 0, '(C−x2)');

        $q = (new QCalibrator())->qForDomain('test_noise_dom');
        self::assertSame(1, $q, 'медиана подтверждений нуллов 0 → q=1');
    }

    /**
     * RED: пустой домен (нет данных) → q = 1 (консервативный минимум).
     */
    public function testEmptyDomainGivesMinimalQ(): void
    {
        $q = (new QCalibrator())->qForDomain('test_empty_dom');
        self::assertSame(1, $q);
    }

    /**
     * RED: домен с воспроизводимым шумом → q растёт выше медианы нуллов.
     */
    public function testReproducibleNoiseRaisesQ(): void
    {
        // Ложные законы с подтверждениями: шум воспроизводится 2 раза.
        $this->seedLaw('test_rep_dom', 5, 2, '(A×x0)');
        $this->seedLaw('test_rep_dom', 5, 2, '(B+x1)');
        $this->seedLaw('test_rep_dom', 5, 3, '(C−x2)');

        $q = (new QCalibrator())->qForDomain('test_rep_dom');
        self::assertSame(3, $q, 'ceil(1.0 * median(2,2,3) + 1) = ceil(3.0) = 3');
    }

    /** RED: exact-домен — подтверждения точных законов НЕ тянут q вверх
     * (спека: exact 5/5 → q ≤ 5; θ по умолчанию 1.0: q = ceil(median+1)). */
    public function testExactLawQWithinBounds(): void
    {
        // Точный закон 5/5 подтверждений + два ложных с 0: медиана по нуллам
        // (подтверждения ложных) = 0 → q=1; но точный закон сам не «нулл»...
        // Нулл-множество = законы с confirmed < usage (шумовая доля) ИЛИ все
        // законы домена, кроме кандидата. Спека простая: median по ВСЕМ законам.
        $this->seedLaw('test_exact_dom', 6, 5, '(K2×x0)');
        $this->seedLaw('test_exact_dom', 3, 0, '(A×x0)');
        $this->seedLaw('test_exact_dom', 2, 0, '(B+x1)');

        $q = (new QCalibrator())->qForDomain('test_exact_dom');
        self::assertSame(1, $q, 'медиана confirmed_count {0,0,5} = 0 → q=1; exact-закон 5/5 при q=1 settle');
        self::assertLessThanOrEqual(5, $q, 'спека: q ≤ 5');
    }

    /**
     * RED: θ из env масштабирует q (θ=2 → удвоение).
     */
    public function testThetaFromEnv(): void
    {
        $this->seedLaw('test_theta_dom', 5, 2, '(A×x0)');
        $this->seedLaw('test_theta_dom', 5, 2, '(B+x1)');
        putenv('Q_THETA=2.0');
        try {
            $q = (new QCalibrator())->qForDomain('test_theta_dom');
            self::assertSame(5, $q, 'ceil(2.0 * 2 + 1) = 5');
        } finally {
            putenv('Q_THETA');
        }
    }

    /**
     * RED: q capped сверху RESAMPLE_COUNT (порог не выше полного консенсуса).
     */
    public function testQCappedAtResampleCount(): void
    {
        $this->seedLaw('test_cap_dom', 10, 7, '(A×x0)');
        $this->seedLaw('test_cap_dom', 10, 8, '(B+x1)');

        putenv('Q_THETA=10.0');
        try {
            $q = (new QCalibrator())->qForDomain('test_cap_dom');
            self::assertLessThanOrEqual(5, $q, 'cap = RESAMPLE_COUNT (спека: q ≤ 5)');
        } finally {
            putenv('Q_THETA');
        }
    }

    /**
     * Живой путь recordDiscovery с routedBee (ветка награды под гвардом).
     */
    private function discoverQaw(string $domain): void
    {
        $m = new \ReflectionMethod(Hive::class, 'recordDiscovery');
        $m->setAccessible(true);
        $rows = [];
        for ($x = 1; $x <= 12; $x++) {
            $rows[] = [(float) $x, 2.0 * $x];
        }
        $X = array_map(static fn (array $r): array => [$r[0]], $rows);
        $y = array_column($rows, 1);

        $bees = new \ReflectionProperty(Hive::class, 'bees');
        $bees->setAccessible(true);
        $first = array_values(array_filter($bees->getValue($this->hive), fn ($b) => $b->isAlive()))[0];
        $rb = new \ReflectionProperty(Hive::class, 'routedBee');
        $rb->setAccessible(true);
        $rb->setValue($this->hive, $first);

        $foundAny = false;
        $m->invokeArgs($this->hive, [
            [
                'atom' => '(K2×x0)',
                'cv' => 0.001,
                'class' => 'EMPIRICAL',
            ],
            [
                'name' => 'qw3',
                'domain' => $domain,
                'fingerprint' => 'fp_q',
            ],
            $domain,
            &$foundAny,
            $X,
            $y,
        ]);
    }

    private function escrowStatus(string $domain): string
    {
        $stmt = Database::get()->prepare(
            'SELECT status FROM law_escrow WHERE domain = ?'
        );
        $stmt->execute([$domain]);

        return (string) $stmt->fetchColumn();
    }

    /**
     * RED: wiring — 3 confirmed < q=4 → settle запрещён, escrow holding.
     */
    public function testPartialBatchBelowQHoldsEscrow(): void
    {
        putenv('ESCROW_GRACE_TASKS=0');
        putenv('Q_THETA=1.5');
        try {
            $this->seedLaw('test_wiring_dom', 5, 2, '(A×x0)');
            $this->seedLaw('test_wiring_dom', 5, 2, '(B+x1)');
            $this->discoverQaw('test_wiring_dom');

            $this->hive->runPendingVerificationTasks('test_wiring_dom', 3);
            self::assertSame(
                'holding',
                $this->escrowStatus('test_wiring_dom'),
                '3 confirmed < q=4: settle запрещён'
            );
        } finally {
            putenv('Q_THETA');
            putenv('ESCROW_GRACE_TASKS');
        }
    }

    /**
     * RED: wiring — 5 confirmed >= q=4 → settle разрешён, escrow paid.
     */
    public function testFullBatchAboveQSettles(): void
    {
        putenv('ESCROW_GRACE_TASKS=0');
        putenv('Q_THETA=1.5');
        try {
            $this->seedLaw('test_wiring2_dom', 5, 2, '(A×x0)');
            $this->seedLaw('test_wiring2_dom', 5, 2, '(B+x1)');
            $this->discoverQaw('test_wiring2_dom');

            $this->hive->runPendingVerificationTasks('test_wiring2_dom', 3);
            $this->hive->runPendingVerificationTasks('test_wiring2_dom', 3);
            self::assertSame(
                'paid',
                $this->escrowStatus('test_wiring2_dom'),
                '5 confirmed >= q=4: settle разрешён'
            );
        } finally {
            putenv('Q_THETA');
            putenv('ESCROW_GRACE_TASKS');
        }
    }
}
