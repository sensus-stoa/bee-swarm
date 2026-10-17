<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * DREAM-HARDENING WU-1 (prereg 29.09, premortem H2 — V-шторм страховка):
 * отдельный тормоз dream-спавна V-задач. NO_VERIFY_SPAWN глушит search+dream
 * вместе; NO_DREAM_SPAWN глушит ТОЛЬКО dream-ветку: открытия записываются,
 * законы живут, V-задачи не спавнятся (лог DREAM_SPAWN_SKIP). Default off.
 *
 * Cap DREAM_SPAWN_MAX_PER_TICK: не более K dream-спавнов за тик (открытие
 * живёт, спавн гасится, лог DREAM_SPAWN_CAP). Открытый вопрос prereg
 * (per-tick vs скользящее окно) решён по живому коду: dream-путь ≤2 вызова
 * за тик (foundAny-fallback + exp039-фазовый ролл) → per-tick счётчик,
 * окно (прецедент R-admission) избыточно. '0'/unset = off
 * (прецедент PREFLIGHT_GATE_FACTOR 0=off).
 *
 * Фикстуры — язык роя, паттерн DreamCongruenceTest (инконгруэнтный
 * dream-кейс: носитель (K2×x0), dream находит (x0−x1)²).
 */
final class DreamSpawnGuardTest extends TestCase
{
    private string $logFile;

    private Hive $hive;

    protected function setUp(): void
    {
        Database::reset();
        Database::get();
        putenv('NO_BASE_TASKS=1');
        putenv('FORAGER_SOURCES=:');
        // Защитная очистка dream-env (dual-review 17.10, medium): контаминированный
        // родительский shell (экспорт для ручных экспериментов) ломал бы пин
        // default-off и cap-тест первого прогона. Симметрично tearDown.
        putenv('NO_DREAM_SPAWN');
        putenv('DREAM_SPAWN_MAX_PER_TICK');
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'dreamspawn_');
        $this->hive = new Hive(maxTicks: 0, logFile: $this->logFile);
        $this->hive->run();
        $this->setPool([self::taskMinusSq('domI')]);
        $this->insertCarrier('domI', '(K2×x0)', '(K2×x0)', 'cls_carrier_domI');
        $this->injectRoutedBee();
    }

    protected function tearDown(): void
    {
        putenv('NO_BASE_TASKS');
        putenv('FORAGER_SOURCES');
        putenv('NO_DREAM_SPAWN');
        putenv('DREAM_SPAWN_MAX_PER_TICK');
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
        Database::setPath(':memory:');
        Database::reset();
    }

    /**
     * WU-1 RED (prereg): NO_DREAM_SPAWN=1 — dream-открытие записывается
     * (закон живёт), V-задачи НЕ спавнятся, лог DREAM_SPAWN_SKIP.
     * Прогноз до реализации: «Failed asserting that 6 is identical to 0»
     * (сейчас dream-спавн работает как в search-пути).
     */
    public function testNoDreamSpawnSilencesVSpawnLawLives(): void
    {
        putenv('NO_DREAM_SPAWN=1');
        $this->dreamTick();

        self::assertSame(2, $this->lawCount('domI'), 'dream-открытие записано, закон живёт');
        self::assertSame(0, $this->queueCount(), 'dream-спавн V-задач заглушен');
        $log = (string) file_get_contents($this->logFile);
        self::assertStringContainsString('DREAM_SPAWN_SKIP', $log, 'skip-лог grep-уем');
    }

    /**
     * Default off (пин): без env dream-спавн работает как в search-пути
     * (6 задач на новый закон). Исключает обходный путь «гвард глушит всё».
     */
    public function testDefaultOffKeepsDreamSpawning(): void
    {
        $this->dreamTick();

        self::assertSame(2, $this->lawCount('domI'), 'закон записан');
        self::assertSame(6, $this->queueCount(), 'default off — спавн как раньше');
    }

    /**
     * Cap: DREAM_SPAWN_MAX_PER_TICK=1 — второй dream-спавн за тик гасится,
     * открытие записывается. Счётчик per-tick; reflection-вызовы dreamTick
     * обходят doTick (ресет счётчика), каждый вызов = тик-эквивалент.
     * Второй кейс в свежем домене domC — нулевой риск конгруэнтного
     * поглощения, чистая проверка кэпа.
     */
    public function testDreamSpawnCapPerTick(): void
    {
        putenv('DREAM_SPAWN_MAX_PER_TICK=1');
        $this->dreamTick();

        self::assertSame(2, $this->lawCount('domI'), 'первый кейс записан');
        self::assertSame(6, $this->queueCount(), 'первый спавн в пределах кэпа');

        $this->setPool([self::taskPlusSq('domC')]);
        $this->dreamTick();

        self::assertSame(1, $this->lawCount('domC'), 'кэп гасит спавн, не открытие');
        self::assertSame(6, $this->queueCount(), 'второй dream-спавн за тик погашен кэпом');
        $log = (string) file_get_contents($this->logFile);
        self::assertStringContainsString('DREAM_SPAWN_CAP', $log, 'cap-лог grep-уем');
    }

    /**
     * Детерминированный домен: y = (x0−x1)² — инконгруэнтен носителю.
     *
     * @return list<list<float>>
     */
    private static function rowsMinusSq(float $shift): array
    {
        return self::mappedRows($shift, static fn (float $x0, float $x1): float => ($x0 - $x1) * ($x0 - $x1));
    }

    /**
     * Детерминированный домен: y = (x0+x1)².
     *
     * @return list<list<float>>
     */
    private static function rowsPlusSq(float $shift): array
    {
        return self::mappedRows($shift, static fn (float $x0, float $x1): float => ($x0 + $x1) * ($x0 + $x1));
    }

    /**
     * @param callable(float,float): float $f
     *
     * @return list<list<float>>
     */
    private static function mappedRows(float $shift, callable $f): array
    {
        $rows = [];
        for ($i = 0; $i < 12; ++$i) {
            $x0 = fmod($i * 0.41 + $shift, 40.0);
            $x1 = fmod($i * 1.37 + $shift, 40.0);
            $rows[] = [$x0, $x1, $f($x0, $x1)];
        }

        return $rows;
    }

    private static function taskMinusSq(string $domain): array
    {
        return [
            'name' => 'dream_minus',
            'domain' => $domain,
            'data' => self::rowsMinusSq(0.0),
        ];
    }

    private static function taskPlusSq(string $domain): array
    {
        return [
            'name' => 'dream_plus',
            'domain' => $domain,
            'data' => self::rowsPlusSq(0.0),
        ];
    }

    private function setPool(array $tasks): void
    {
        $prop = new \ReflectionProperty(Hive::class, 'foragedTasksGlobal');
        $prop->setAccessible(true);
        $prop->setValue($this->hive, $tasks);
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

    private function insertCarrier(string $domain, string $formula, ?string $classId, ?string $lawClass): void
    {
        Database::get()->prepare(
            'INSERT INTO laws (name, formula, cv, domain, class_id, law_class, class_domain_json, usage_count)
             VALUES (?, ?, 0.01, ?, ?, ?, ?, 1)'
        )->execute([
            'carrier_' . $domain,
            $formula,
            $domain,
            $classId,
            $lawClass,
            json_encode([
                'members' => [$classId ?? $formula],
                'split_at' => null,
                'domain' => [
                    '0' => [0.0, 40.0],
                    '1' => [0.0, 40.0],
                ],
            ]),
        ]);
    }

    private function lawCount(string $domain): int
    {
        $stmt = Database::get()->prepare('SELECT COUNT(*) FROM laws WHERE domain = ?');
        $stmt->execute([$domain]);

        return (int) $stmt->fetchColumn();
    }

    private function queueCount(): int
    {
        $stmt = Database::get()->prepare('SELECT COUNT(*) FROM verification_tasks');
        // prepare()→execute() раздельно (AGENTS.md правило №5): fetchColumn на
        // НЕисполненном statement = false → 0 — ложный ноль очереди (поймано
        // probe-бисекцией 17.10: prepare-без-execute читался как 0 при реальных 6).
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }
}
