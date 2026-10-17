<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Bee;
use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * ESCROW-ORPHAN-DEPOSITS WU-2 (prereg 17.10, фикс A'): deposit-time probe
 * в payDiscovery — депозит без VERIFICATION-задач не создаётся, выплата
 * 100% сразу (grace-семантика), лог reason=no-vtasks; здоровый спавн —
 * поведение байт-в-байт прежнее (пин default-off).
 *
 * Энергия «целиком»: nodeposit-ветка вызывает ТОТ ЖЕ rewardDiscovery, что
 * и grace-ветка → дельта энергии подавленного открытия == дельте
 * grace-открытия при прочих равных (тот же домен, та же первый-класс
 * ×1.5 сатиеция — носители вставлены SQL-ом и классов не регистрируют).
 *
 * Пин-тест болезни из WU-1 переписан на прямую инъекцию escrow
 * (premortem 17.10: канара переживает фикс, не удаляется).
 */
final class EscrowNodepositTest extends TestCase
{
    /** @var list<string> */
    private array $logs = [];

    protected function setUp(): void
    {
        Database::reset();
        Database::get();
        putenv('NO_BASE_TASKS=1');
        putenv('FORAGER_SOURCES=:');
        putenv('NO_DREAM_SPAWN');
        putenv('DREAM_SPAWN_MAX_PER_TICK');
        // Пин grace (урок WU-1 dual-review): фикстура на границе 6>grace.
        putenv('ESCROW_GRACE_TASKS=5');
    }

    protected function tearDown(): void
    {
        putenv('NO_BASE_TASKS');
        putenv('FORAGER_SOURCES');
        putenv('NO_DREAM_SPAWN');
        putenv('DREAM_SPAWN_MAX_PER_TICK');
        putenv('ESCROW_GRACE_TASKS');
        foreach ($this->logs as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        Database::setPath(':memory:');
        Database::reset();
    }

    /**
     * WU-2 RED (prereg): подавленный спавн + past-grace → закон записан,
     * V-задач 0, депозита НЕТ (сейчас: депозит создаётся — прогноз RED
     * «1 is not identical to 0»), энергия выплачена, лог reason=no-vtasks.
     */
    public function testNodepositBlocksDepositOnSuppressedSpawn(): void
    {
        putenv('NO_DREAM_SPAWN=1');
        ['hive' => $hive, 'log' => $log, 'bee' => $bee] = $this->buildHive(5, 'domI');
        $before = $bee->energy();

        $this->dreamTick($hive);

        self::assertSame(6, $this->lawCount('domI'), 'dream-открытие = 6-й закон (past-grace)');
        self::assertSame(0, $this->queueCount('domI'), 'спавн подавлен');
        $holding = Database::get()->prepare(
            "SELECT COUNT(*) FROM law_escrow WHERE domain = ? AND status = 'holding'"
        );
        $holding->execute(['domI']);
        self::assertSame(0, (int) $holding->fetchColumn(), 'депозит НЕ создан (фикс A\'');
        self::assertGreaterThan(0.0, $bee->energy() - $before, 'энергия выплачена');
        $content = (string) file_get_contents($log);
        self::assertStringContainsString('ESCROW: nodeposit', $content);
        self::assertStringContainsString('reason=no-vtasks', $content);
    }

    /**
     * Энергия «целиком»: nodeposit-дельта == grace-дельта (тот же
     * rewardDiscovery). Прогноз до реализации: RED — 6-й закон уходит
     * в split (30% immediate), дельты расходятся.
     */
    public function testNodepositPaysFullLikeGrace(): void
    {
        putenv('NO_DREAM_SPAWN=1');
        // Ячейка grace: 4 носителя → открытие = 5-й закон (<= grace)
        ['hive' => $hiveA, 'bee' => $beeA] = $this->buildHive(4, 'domI');
        $beforeA = $beeA->energy();
        $this->dreamTick($hiveA);
        $graceDelta = $beeA->energy() - $beforeA;
        self::assertGreaterThan(0.0, $graceDelta, 'grace заплатил');

        // Свежая БД для ячейки nodeposit
        Database::setPath(':memory:');
        Database::reset();
        Database::get();
        // Ячейка nodeposit: 5 носителей → открытие = 6-й закон (> grace)
        ['hive' => $hiveB, 'bee' => $beeB] = $this->buildHive(5, 'domI');
        $beforeB = $beeB->energy();
        $this->dreamTick($hiveB);
        $nodepositDelta = $beeB->energy() - $beforeB;

        // Дельта-сравнение, не бит-идентичность (dual-review 17.10: RED показал
        // representation noise 0.9000000000000004 — рефактор порядка операндов
        // в rewardDiscovery не должен ронять тест при сохранении семантики).
        self::assertEqualsWithDelta($graceDelta, $nodepositDelta, 1e-9, 'nodeposit платит ровно как grace (полная база)');
    }

    /**
     * Пин default-off: здоровый спавн — поведение прежнее (депозит + 6
     * задач + split-лог). Против обходного пути «probe гасит всё».
     */
    public function testHealthyDepositUnchanged(): void
    {
        ['hive' => $hive, 'log' => $log, 'bee' => $bee] = $this->buildHive(5, 'domI');
        $before = $bee->energy();

        $this->dreamTick($hive);

        self::assertSame(6, $this->queueCount('domI'), 'спавн живой');
        $holding = Database::get()->prepare(
            "SELECT law_formula, amount FROM law_escrow WHERE domain = ? AND status = 'holding'"
        );
        $holding->execute(['domI']);
        $rows = $holding->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $rows, 'здоровый депозит создан');
        self::assertGreaterThan(0.0, (float) $rows[0]['amount']);
        $content = (string) file_get_contents($log);
        self::assertStringContainsString('ESCROW: split', $content, 'старая split-ветка работает');
        self::assertStringNotContainsString('ESCROW: nodeposit', $content, 'probe не срабатывает на здоровом спавне');
        // Ratio-пин (dual-review 17.10): immediate = base×0.3 (дельта энергии),
        // escrow = base×0.7 → escrow == delta×7/3. Дрейф ratio 0.7→0.9 ловится.
        $delta = $bee->energy() - $before;
        self::assertEqualsWithDelta($delta * 7 / 3, (float) $rows[0]['amount'], 1e-6, 'ratio 70/30 не дрейфует');
        // Кросс-согласие предикатов (premortem 17.10): probe на формуле
        // здорового депозита обязан быть true — иначе детектор и фикс разойдутся.
        self::assertTrue(
            (new \BeeSwarm\Hive\EscrowOrphanDetector())->hasVerificationTasks((string) $rows[0]['law_formula'], 'domI'),
            'probe согласен с детектором на здоровом депозите'
        );
    }

    /**
     * Search-путь (dual-review 17.10, medium): NO_VERIFY_SPAWN глушит спавн
     * search-открытия → probe в payDiscovery даёт nodeposit. Пин закрывает
     * пробел «formula/domain matching pinned только через dream-поток»:
     * search-путь идёт через тот же recordDiscovery и тот же канон.
     */
    public function testSearchPathNodepositOnNoVerifySpawn(): void
    {
        putenv('NO_VERIFY_SPAWN=1');
        try {
            ['hive' => $hive, 'log' => $log, 'bee' => $bee] = $this->buildHive(5, 'domI');
            $before = $bee->energy();
            $foundAny = false;
            $d = ['atom' => '(x0+K2)', 'cv' => 0.001, 'class' => 'EMPIRICAL'];
            $task = ['name' => 'search_nodeposit', 'domain' => 'domI', 'fingerprint' => 'fp_snp'];
            $m = new \ReflectionMethod(Hive::class, 'recordDiscovery');
            $m->setAccessible(true);
            $m->invokeArgs($hive, [$d, $task, 'domI', &$foundAny, [[1.0], [2.0], [3.0]], [2.0, 4.0, 6.0]]);

            self::assertTrue($foundAny, 'закон записан (search-путь)');
            self::assertSame(0, $this->queueCount('domI'), 'спавн подавлен NO_VERIFY_SPAWN');
            $holding = Database::get()->prepare(
                "SELECT COUNT(*) FROM law_escrow WHERE domain = ? AND status = 'holding'"
            );
            $holding->execute(['domI']);
            self::assertSame(0, (int) $holding->fetchColumn(), 'search-путь тоже nodeposit');
            self::assertStringContainsString('reason=no-vtasks', (string) file_get_contents($log));
            self::assertGreaterThan(0.0, $bee->energy() - $before, 'энергия выплачена');
        } finally {
            putenv('NO_VERIFY_SPAWN');
        }
    }

    /**
     * @return array{hive: Hive, log: string, bee: Bee}
     */
    private function buildHive(int $carriers, string $domain): array
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'escrowfix_');
        $this->logs[] = $log;
        $hive = new Hive(maxTicks: 0, logFile: $log);
        $hive->run();
        for ($k = 1; $k <= $carriers; ++$k) {
            $formula = "(K{$k}×x0)";
            Database::get()->prepare(
                'INSERT INTO laws (name, formula, cv, domain, class_id, law_class, class_domain_json, usage_count)
                 VALUES (?, ?, 0.01, ?, ?, ?, ?, 1)'
            )->execute([
                "carrier_{$domain}_K{$k}",
                $formula,
                $domain,
                $formula,
                $formula,
                json_encode([
                    'members' => [$formula],
                    'split_at' => null,
                    'domain' => ['0' => [0.0, 40.0], '1' => [0.0, 40.0]],
                ]),
            ]);
        }
        $pool = new \ReflectionProperty(Hive::class, 'foragedTasksGlobal');
        $pool->setAccessible(true);
        $pool->setValue($hive, [
            ['name' => 'dream_minus', 'domain' => $domain, 'data' => self::rowsMinusSq()],
        ]);
        $bees = new \ReflectionProperty(Hive::class, 'bees');
        $bees->setAccessible(true);
        $all = $bees->getValue($hive);
        $first = array_values(array_filter($all, fn ($b) => $b->isAlive()))[0] ?? null;
        self::assertNotNull($first, 'bootstrap дал живую пчелу');
        $rb = new \ReflectionProperty(Hive::class, 'routedBee');
        $rb->setAccessible(true);
        $rb->setValue($hive, $first);

        return ['hive' => $hive, 'log' => $log, 'bee' => $first];
    }

    private function dreamTick(Hive $hive): void
    {
        $m = new \ReflectionMethod(Hive::class, 'idleDreamTick');
        $m->setAccessible(true);
        $m->invokeArgs($hive, []);
    }

    private function lawCount(string $domain): int
    {
        $stmt = Database::get()->prepare('SELECT COUNT(*) FROM laws WHERE domain = ?');
        $stmt->execute([$domain]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * prepare()→execute() раздельно (AGENTS.md №5; урок ложного нуля 17.10).
     */
    private function queueCount(string $domain): int
    {
        $stmt = Database::get()->prepare(
            'SELECT COUNT(*) FROM verification_tasks WHERE domain = ?'
        );
        $stmt->execute([$domain]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<list<float>>
     */
    private static function rowsMinusSq(): array
    {
        $rows = [];
        for ($i = 0; $i < 12; ++$i) {
            $x0 = fmod($i * 0.41, 40.0);
            $x1 = fmod($i * 1.37, 40.0);
            $d = $x0 - $x1;
            $rows[] = [$x0, $x1, $d * $d];
        }

        return $rows;
    }
}
