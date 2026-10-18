<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * VERIF-TICK-WIRING (WU-0b route-transfer): исполнитель V-задач в живом тике.
 *
 * Болезнь (handoff 16.10): executor существует (v0.14), escrow-цепочка
 * работает (EscrowWiringTest), НО вызов живёт только в EXP039-ветке тика
 * (гейт EXP039_DOMAINS) и в тестах напрямую. Прод-путь (seasonScheduler
 * === null) никогда не исполняет V-задачи → escrow замораживается → нет
 * сигнала ценности.
 *
 * Замер-гигиена (probe 17.10): тик с bootstrap-пчёлами НЕ молчит —
 * встроенные задачи открывают законы (discovery-путь пишет 'ESCROW: split'
 * и спавнит свои V-задачи в домашнем домене). Поэтому все ассерты —
 * ТОЛЬКО по специфичным маркерам V-пути ('VTASK'/'VSWEEP') и по домену
 * фиксстуры; общие счётчики pending/ESCROW неинформативны.
 *
 * Критерии handoff §1: RED (пин болезни) → GREEN 1 (wiring) →
 * GREEN 2 (double-exec guard) → GREEN 3 (роутер типов не сломан).
 * Фикстура — канон EscrowWiringTest, тик-драйв — канон Exp039HookTest.
 */
final class VerificationTickWiringTest extends TestCase
{
    private const DOMAIN = 'test_vtw_dom';

    private const LAW = '(K2×x0)';

    private string $logFile;

    private Hive $hive;

    protected function setUp(): void
    {
        Database::reset();
        Database::get();
        // Паттерн EscrowWiringTest: grace выключен — депозит сразу.
        putenv('ESCROW_GRACE_TASKS=0');
        // Паттерн DreamSpawnGuardTest: forager-скан отключён (детерминизм тика).
        putenv('FORAGER_SOURCES=:');
        // Чистый прод-путь: без EXP039-режима.
        putenv('EXP039_MODE');
        putenv('EXP039_DOMAINS');
        $this->logFile = tempnam(sys_get_temp_dir(), 'vtw_');
        $this->hive = new Hive(maxTicks: 0, logFile: $this->logFile);
        $this->hive->run(); // bootstrap only
    }

    protected function tearDown(): void
    {
        // env НЕ из phpunit.xml — снятие безопасно (phpunit-env-unset-pitfall).
        putenv('ESCROW_GRACE_TASKS');
        putenv('FORAGER_SOURCES');
        putenv('EXP039_MODE');
        putenv('EXP039_DOMAINS');
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
        Database::setPath(':memory:');
        Database::reset();
    }

    /**
     * GREEN 1 (RED = пин болезни): живой тик с V-задачей в очереди
     * исполняет её. Прогноз-факт 16.10: executor из тика не вызывается —
     * задачи домена остаются pending, escrow остаётся holding.
     */
    public function testLiveTickExecutesVerificationTasksAndSettlesEscrow(): void
    {
        $this->discoverLaw();
        $this->runTick(1);

        $pending = $this->domainTaskCount('pending');
        self::assertSame(0, $pending, 'живой тик обязан исполнить V-задачи домена из очереди');
        self::assertGreaterThan(0, $this->countLog('VTASK'), 'V-исполнение обязано оставлять след в логе');
        self::assertSame(6, $this->domainTaskCountTerminal(), 'все 6 задач домена получают вердикт');
        self::assertSame('paid', $this->escrowStatus(), 'консенсус ресемплов в тике закрывает escrow выплатой');
    }

    /**
     * GREEN 2 (double-exec guard): повторный тик не исполняет ТУ ЖЕ задачу
     * второй раз. Гвард — статус задачи (pending → терминальный; единый
     * сайт записи, урок F6). Счётчик исполнений — по law-маркеру лога:
     * bootstrap-задачи других доменов legitimately исполняются во втором
     * тике (их V-задачи появились в первом), это не реисполнение.
     */
    public function testSecondTickDoesNotReexecuteTasks(): void
    {
        $this->discoverLaw();
        $this->runTick(1);
        $statusesAfterFirst = $this->domainTaskStatuses();
        $lawExecAfterFirst = $this->countLog('law=' . self::LAW . ' ');
        $paidAfterFirst = $this->countLog('ESCROW: paid law=' . self::LAW);

        $this->runTick(2);

        self::assertSame(
            $statusesAfterFirst,
            $this->domainTaskStatuses(),
            'повторный тик не меняет статусы исполненных задач домена'
        );
        self::assertSame(
            $lawExecAfterFirst,
            $this->countLog('law=' . self::LAW . ' '),
            'повторный тик не порождает новых V-исполнений закона (гвард состояния задачи)'
        );
        self::assertSame(1, $paidAfterFirst, 'settle закона однократен (holding→paid)');
        self::assertSame('paid', $this->escrowStatus(), 'escrow не пере-закрывается');
    }

    /**
     * GREEN 3 (изолированность): неизвестный kind не исполняется как
     * V-поиск (whitelist роутер исполнителя жив и через тик), неполный
     * батч ресемплов не закрывает escrow.
     */
    public function testUnknownKindNotExecutedAsVerification(): void
    {
        $this->discoverLaw();
        Database::get()->prepare(
            "UPDATE verification_tasks SET kind = 'search_sd' WHERE kind = 'resample_1'"
        )->execute();

        $this->runTick(1);

        $st = (string) Database::get()->query(
            "SELECT status FROM verification_tasks WHERE kind = 'search_sd'"
        )->fetchColumn();
        self::assertSame('inconclusive', $st, 'неизвестный kind уходит в inconclusive, не в V-поиск');
        self::assertSame('holding', $this->escrowStatus(), 'неполный батч (4/5 resample) не закрывает escrow');
    }

    /**
     * Гигиена: первый тик чистой БД (очередь V-задач пуста) не порождает
     * V-исполнений — sweep не работает «из воздуха».
     */
    public function testProdTickWithoutVTasksStaysSilent(): void
    {
        $this->runTick(1);

        self::assertSame(0, $this->countLog('VTASK'), 'нет V-исполнений без очереди');
        self::assertSame(0, $this->countLog('VSWEEP'), 'sweep не логирует без очереди');
    }

    /**
     * Изоляция эксперимента (аддендум A8): при EXP039_MODE прод-sweep
     * отключён — verify живёт только в roll-ветке с EXP039_DOMAINS,
     * второй сайт исполнения не появляется (урок F6).
     */
    public function testExp039SchedulerModeNotAffectedByProdSweep(): void
    {
        putenv('EXP039_MODE=A'); // все тики explore — verify-ролл не выпадает
        $logFile = tempnam(sys_get_temp_dir(), 'vtw_exp_');
        $hive = new Hive(maxTicks: 0, logFile: $logFile);
        $hive->run();
        $this->discoverLaw($hive);

        $this->runTick(1, $hive);

        self::assertSame(0, $this->countLog('VTASK', $logFile), 'EXP039-режим: прод-sweep не исполняет V-задачи');
        self::assertSame(0, $this->countLog('VSWEEP', $logFile), 'EXP039-режим: VSWEEP-маркер отсутствует');
        unlink($logFile);
    }

    /**
     * Живой путь recordDiscovery: закон + 6 V-задач + escrow holding
     * (канон EscrowWiringTest::discoverLaw).
     */
    private function discoverLaw(?Hive $hive = null): void
    {
        $target = $hive ?? $this->hive;
        $this->injectRoutedBee($target);
        [$X, $y] = $this->linearFixture();
        $foundAny = false;
        $m = new \ReflectionMethod(Hive::class, 'recordDiscovery');
        $m->setAccessible(true);
        // ReflectionMethod::invoke не передаёт by-ref — только invokeArgs
        $m->invokeArgs($target, [
            [
                'atom' => self::LAW,
                'cv' => 0.001,
                'class' => 'EMPIRICAL',
            ],
            [
                'name' => 'vtw_w1',
                'domain' => self::DOMAIN,
                'fingerprint' => 'fp_' . md5(self::DOMAIN),
            ],
            self::DOMAIN,
            &$foundAny,
            $X,
            $y,
        ]);
    }

    /**
     * Точные линейные данные y = 2x (канон EscrowWiringTest::discoverLaw).
     *
     * @return array{0: list<list<float>>, 1: list<float>}
     */
    private function linearFixture(): array
    {
        $rows = [];
        for ($x = 1; $x <= 12; $x++) {
            $rows[] = [(float) $x, 2.0 * $x];
        }
        $X = array_map(static fn (array $r): array => [$r[0]], $rows);

        return [$X, array_column($rows, 1)];
    }

    /**
     * В проде routedBee ставит тик-роутинг; инжекция bootstrap-пчелы
     * (канон EscrowWiringTest::injectRoutedBee).
     */
    private function injectRoutedBee(Hive $hive): void
    {
        $bees = new \ReflectionProperty(Hive::class, 'bees');
        $bees->setAccessible(true);
        $all = $bees->getValue($hive);
        $first = array_values(array_filter($all, fn ($b) => $b->isAlive()))[0] ?? null;
        self::assertNotNull($first, 'bootstrap дал живую пчелу');
        $rb = new \ReflectionProperty(Hive::class, 'routedBee');
        $rb->setAccessible(true);
        $rb->setValue($hive, $first);
    }

    /**
     * Живой тик без run(): паттерн Exp039HookTest (tick + doTick reflection).
     */
    private function runTick(int $tick, ?Hive $hive = null): void
    {
        $target = $hive ?? $this->hive;
        $p = new \ReflectionProperty(Hive::class, 'tick');
        $p->setAccessible(true);
        $p->setValue($target, $tick);
        $m = new \ReflectionMethod(Hive::class, 'doTick');
        $m->setAccessible(true);
        $m->invoke($target);
    }

    private function domainTaskCount(string $status): int
    {
        $stmt = Database::get()->prepare(
            'SELECT COUNT(*) FROM verification_tasks WHERE domain = ? AND status = ?'
        );
        $stmt->execute([self::DOMAIN, $status]);

        return (int) $stmt->fetchColumn();
    }

    private function domainTaskCountTerminal(): int
    {
        $stmt = Database::get()->prepare(
            "SELECT COUNT(*) FROM verification_tasks
             WHERE domain = ? AND status IN ('confirmed','refuted','anomaly','inconclusive')"
        );
        $stmt->execute([self::DOMAIN]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<int, string> id => status (только домен фиксстуры)
     */
    private function domainTaskStatuses(): array
    {
        $stmt = Database::get()->prepare(
            'SELECT id, status FROM verification_tasks WHERE domain = ? ORDER BY id'
        );
        $stmt->execute([self::DOMAIN]);

        return array_map(
            static fn (array $r): string => (string) $r['status'],
            $stmt->fetchAll(\PDO::FETCH_ASSOC)
        );
    }

    private function escrowStatus(): string
    {
        $stmt = Database::get()->prepare(
            'SELECT status FROM law_escrow WHERE domain = ?'
        );
        $stmt->execute([self::DOMAIN]);

        return (string) $stmt->fetchColumn();
    }

    private function countLog(string $needle, ?string $file = null): int
    {
        // Hive::log пишет в файл (echo + file_put_contents), не в массив —
        // свойство 'log' мёртвое (латентный дефект Exp039HookTest::logOf).
        $content = (string) file_get_contents($file ?? $this->logFile);

        return substr_count($content, $needle);
    }
}
