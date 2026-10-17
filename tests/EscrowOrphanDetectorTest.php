<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * ESCROW-ORPHAN-DEPOSITS WU-1 (prereg 17.10): наблюдаемость висячих
 * депозитов. Сирота = law_escrow (holding) без единой V-задачи по
 * (law_formula, domain) — spавн подавлен (NO_DREAM_SPAWN) или VTS persist
 * failure. Детектор обязан видеть сироту и молчать на здоровом депозите.
 *
 * Фикстура past-grace: 5 носителей в domI, dream-открытие = 6-й закон
 * (lawsInDomain 6 > grace 5) → ветка депонирования. Инконгруэнтность
 * минус-квадрата носителям — по формуле (после WU-2 law_class
 * formula-only), носители — (KN×x0).
 */
final class EscrowOrphanDetectorTest extends TestCase
{
    private string $logFile;

    private Hive $hive;

    protected function setUp(): void
    {
        Database::reset();
        Database::get();
        putenv('NO_BASE_TASKS=1');
        putenv('FORAGER_SOURCES=:');
        // Защитная очистка dream-env (симметрично tearDown; урок dual-review 17.10)
        putenv('NO_DREAM_SPAWN');
        putenv('DREAM_SPAWN_MAX_PER_TICK');
        // Пин grace (dual-review 17.10): past-grace фикстура держится на
        // границе 6>grace — явный пин против тихой смены дефолта/env.
        putenv('ESCROW_GRACE_TASKS=5');
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'escroworph_');
        $this->hive = new Hive(maxTicks: 0, logFile: $this->logFile);
        $this->hive->run();
        $this->insertCarriers('domI', 5);
        $pool = new \ReflectionProperty(Hive::class, 'foragedTasksGlobal');
        $pool->setAccessible(true);
        $pool->setValue($this->hive, [
            ['name' => 'dream_minus', 'domain' => 'domI', 'data' => self::rowsMinusSq()],
        ]);
        $this->injectRoutedBee();
    }

    protected function tearDown(): void
    {
        putenv('NO_BASE_TASKS');
        putenv('FORAGER_SOURCES');
        putenv('NO_DREAM_SPAWN');
        putenv('DREAM_SPAWN_MAX_PER_TICK');
        putenv('ESCROW_GRACE_TASKS');
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
        Database::setPath(':memory:');
        Database::reset();
    }

    /**
     * Канара сиротства (WU-2, premortem 17.10): сирота создаётся ПРЯМОЙ
     * ИНЪЕКЦИЕЙ escrow-строки (мимо публичного пути — после фикса A'
     * подавленный спавн депозит больше не создаёт). Канара обязана
     * пережить лечение: будущая регрессия, снова разрешающая депозит
     * без спавна задач, ловится здесь.
     */
    public function testDetectorSeesInjectedOrphan(): void
    {
        // updated_at явный (dual-review 17.10: канары не полагаются на DDL-дефолты)
        Database::get()->prepare(
            'INSERT INTO law_escrow (law_formula, domain, amount, carrier, status, updated_at)
             VALUES (?, ?, ?, ?, ?, datetime(\'now\'))'
        )->execute(['((x0−x1))²', 'domCan', 2.5, 'bee0', 'holding']);

        $detector = new \BeeSwarm\Hive\EscrowOrphanDetector();
        $orphans = $detector->orphans();
        self::assertCount(1, $orphans, 'ровно один висячий депозит');
        self::assertSame('((x0−x1))²', $orphans[0]['law_formula']);
        self::assertSame('domCan', $orphans[0]['domain']);
        self::assertSame(2.5, $orphans[0]['amount']);
        self::assertSame('bee0', $orphans[0]['carrier'], 'carrier атрибутирован');
        self::assertNotSame('', $orphans[0]['updated_at']);
        // Кросс-согласие предикатов (premortem 17.10): детектор-сирота ==
        // probe-false на тех же данных — иначе детектор и фикс разойдутся.
        self::assertFalse(
            $detector->hasVerificationTasks('((x0−x1))²', 'domCan'),
            'probe согласен с детектором на сироте'
        );
    }

    /**
     * Контроль (пин против ложных срабатываний): здоровый депозит —
     * спавн живой (6 задач), депозит holding существует (dual-review 17.10:
     * без ассерта депозита контроль вакуумный), каноны ОБОИХ писателей
     * байт-в-байт равны (канарейка канон-дрейва — premortem 17.10).
     */
    public function testDetectorSilentOnHealthyDeposit(): void
    {
        $this->dreamTick();

        self::assertSame(6, $this->queueCount('domI'), 'спавн живой (default off)');
        $holding = Database::get()->prepare(
            "SELECT law_formula, amount FROM law_escrow WHERE domain = ? AND status = 'holding'"
        );
        $holding->execute(['domI']);
        $rows = $holding->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $rows, 'здоровый депозит создан (иначе контроль вакуумный)');
        self::assertGreaterThan(0.0, (float) $rows[0]['amount']);
        $taskFormula = Database::get()->prepare(
            'SELECT DISTINCT law_formula FROM verification_tasks WHERE domain = ?'
        );
        $taskFormula->execute(['domI']);
        self::assertSame(
            [$rows[0]['law_formula']],
            $taskFormula->fetchAll(\PDO::FETCH_COLUMN),
            'канон-канарейка: escrow и tasks пишут байт-в-байт одну формулу'
        );
        $orphans = (new \BeeSwarm\Hive\EscrowOrphanDetector())->orphans();
        self::assertCount(0, $orphans, 'здоровый депозит — не сирота');
    }

    /**
     * Anti-маскировка (dual-review 17.10, medium): research-задача
     * (inverted_research, law_id=0) пишет тот же law_formula — детектор
     * обязан продолжать видеть сироту. Сирота = нет VERIFICATION-задач.
     * Инъекция escrow (канара переживает фикс A', premortem 17.10).
     */
    public function testResearchTasksDoNotMaskOrphan(): void
    {
        Database::get()->prepare(
            'INSERT INTO law_escrow (law_formula, domain, amount, carrier, status, updated_at)
             VALUES (?, ?, ?, ?, ?, datetime(\'now\'))'
        )->execute(['((x0−x1))²', 'domCan', 1.5, 'bee0', 'holding']);
        Database::get()->prepare(
            'INSERT INTO verification_tasks
             (law_id, law_formula, law_shape, kind, resample_seed, target_sign, fingerprint, domain)
             VALUES (0, ?, ?, ?, 0, -1, ?, ?)'
        )->execute(['((x0−x1))²', '*', 'inverted_research', '', 'domCan']);

        $orphans = (new \BeeSwarm\Hive\EscrowOrphanDetector())->orphans();
        self::assertCount(1, $orphans, 'research-задача НЕ маскирует сироту');
        self::assertSame('domCan', $orphans[0]['domain']);
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

    /**
     * N носителей (KN×x0) — past-grace фикстура; каждое с своим
     * class_id/law_class (formula-only канон после WU-2).
     */
    private function insertCarriers(string $domain, int $n): void
    {
        for ($k = 1; $k <= $n; ++$k) {
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
    }

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
     * Живой dream-тик (ReflectionMethod, прецедент DreamCongruenceTest).
     */
    private function dreamTick(): void
    {
        $m = new \ReflectionMethod(Hive::class, 'idleDreamTick');
        $m->setAccessible(true);
        $m->invokeArgs($this->hive, []);
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
}
