<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** ENV-снапшот (12.10): утечки putenv между тестами одного воркера
     * paratest — класс бага (214 skip + 4 fail инцидентов: LanguageVerify28
     * SWARM_DB_PATH, Ensemble-флаги). tearDown восстанавливает весь env. */
    private array $envSnapshot = [];

    /** WORKER-BASELINE (12.10, фикс ядовитого снапшота): снапшот per-test
     * наследовал яд от FATAL-теста (tearDown не бежит) и рестор
     * распространял отраву на хвост воркера (60 skip в junit9). Базлайн
     * снимается ОДИН раз на процесс — чистое bootstrap-состояние. */
    private static ?array $workerEnv = null;

    /**
     * FULL STATE ISOLATION opt-out (13.10, release-finalization finding):
     * per-test DELETE of laws/grammar_ops (below) wipes shared-state classes.
     * FullPipelineRegressionTest runs ONE Hive per CLASS (setUpBeforeClass)
     * and asserts on that shared run from its test methods - per-test delete
     * erased its discoveries (0 laws), breaking the slow pass since 12.10
     * (introduced by e5c8138 suite hygiene; slow pass was not re-run until
     * 13.10). Shared-Hive classes opt out explicitly; their own
     * setUpBeforeClass does the isolation (it deletes laws + ALL grammar_ops
     * before reseeding), and leakage OUT is contained by other tests' setUp.
     */
    protected bool $skipFullStateIsolation = false;

    protected function setUp(): void
    {
        // ENV-СНАПШОТ: до guard-а, чтобы утечки предыдущего теста не влияли
        // на сам guard (SWARM_DB_PATH-течь блокировала 214 тестов).
        // FORENSICS f18 (12.10): PHPUnit global-state restore обнуляет статические
        // свойства классов — включая $workerEnv (он в снапшоте как null до
        // первого ??=). ??= перезахватывает env; если в этот момент среда
        // стёрта wipe-циклом прошлого теста, пустой базлайн ФИКСИРУЕТСЯ и
        // tearDown стирает ВСЮ среду воркера (f17: 324 skip, f18: 2 skip,
        // [trip] dbPath=false). Гейт: базлайн без SWARM_DB_PATH невалиден —
        // переснимаем; если невалиден и текущий env — пустой базлайн,
        // деструктивные циклы tearDown отключаются (см. гейт ниже).
        self::$workerEnv ??= getenv();
        if (! isset(self::$workerEnv['SWARM_DB_PATH'])) {
            $fresh = getenv();
            self::$workerEnv = isset($fresh['SWARM_DB_PATH']) ? $fresh : [];
        }
        $this->envSnapshot = self::$workerEnv;
        // GUARD: prevent test leakage into production DB
        // :memory: — in-memory test DB (S1.10); test-пути — файловые тестовые БД
        $dbPath = getenv('SWARM_DB_PATH');
        if ($dbPath !== ':memory:' && (! $dbPath || ! str_contains($dbPath, 'test'))) {
            // FORENSICS 12.10 (f17=324 skip, junit-logger не пишет message):
            // логируем жертву и значение guard-переменной в момент скипа.
            error_log(
                sprintf(
                    "[trip] %s %s::%s dbPath=%s\n",
                    date('H:i:s'),
                    static::class,
                    $this->name(),
                    var_export($dbPath, true)
                ),
                3,
                '/tmp/guard_trips.log'
            );
            $this->markTestSkipped(
                'SWARM_DB_PATH must point to a test database (:memory: or test path). ' .
                'Run with phpunit.xml or set SWARM_DB_PATH=:memory:'
            );
        }
        Database::get();

        // ИЗОЛЯЦИЯ (15.08, CONCERNS deleg_2eb385c7): DELETE и в setUp —
        // защита от фатала ПРЕДЫДУЩЕГО теста (tearDown мог не выполниться!)
        // FULL STATE ISOLATION (12.10, f9/f10 premortem): search-состояние
        // (laws, grammar_ops=B-атомы, marks, prereg) живёт в :memory: ОДНОГО
        // воркера и течёт между тестами — flaky-класс (SearchRawPairPool,
        // L3L1 HeatConduction: standalone OK, paratest FAIL). bee_persistence
        // чистился, остальное — нет. Порядок: потомки после родителей.
        if (! $this->skipFullStateIsolation) {
            foreach (['trail_marks', 'preregistrations', 'laws', 'grammar_ops', 'knowledge_graph', 'bee_persistence'] as $tbl) {
                try {
                    Database::get()->exec("DELETE FROM {$tbl}");
                } catch (\Throwable $e) {
                    // нет таблицы — легитимно; остальное — видно
                    if (! str_contains($e->getMessage(), 'no such table')) {
                        error_log("[test] {$tbl} cleanup: " . $e->getMessage());
                    }
                }
            }
        }

        // Use test fixtures for forager instead of scanning home directory
        if (! getenv('FORAGER_SOURCES')) {
            $fixturesDir = __DIR__ . '/fixtures/forager';
            if (is_dir($fixturesDir)) {
                putenv('FORAGER_SOURCES=' . $fixturesDir);
            }
        }
    }

    /**
     * ARCH-V2: Assert no RNG poisoning leaked from this test.
     *
     * §0.5: srand(42) in one test poisons array_rand() for ALL subsequent
     * tests in the same process. This tearDown catches the leak immediately
     * on the guilty test — so you know exactly which test caused it.
     *
     * If this assertion fires:
     *   1. The test whose tearDown() fails is the LEAKER.
     *   2. Look for srand() without restore() in the code that test exercises.
     *   3. Use RngIsolation::deterministicSeed(N) + ->restore() pattern.
     *
     * Tests that intentionally manage RNG state (like RngIsolationTest itself)
     * should clean up in their own tearDown before this assertion runs.
     */
    protected function tearDown(): void
    {
        // FORENSICS 12.10: ловим отравителя — env ≠ baseline на входе в tearDown.
        // FORAGER_SOURCES исключён: его же ставит setUp (fixtures).
        // Пустой envSnapshot (невалидный базлайн) — сравнивать не с чем, шум.
        $nowEnv = getenv();
        if ($this->envSnapshot !== [] && $nowEnv != $this->envSnapshot) {
            $diff = [];
            foreach ($this->envSnapshot as $k => $v) {
                if ($k === 'FORAGER_SOURCES') {
                    continue;
                }
                $cur = $nowEnv[$k] ?? null;
                if ($cur !== $v) {
                    $diff[] = $k . ': ' . var_export($v, true) . ' -> ' . var_export($cur, true);
                }
            }
            foreach (array_diff_key($nowEnv, $this->envSnapshot) as $k => $v) {
                if ($k === 'FORAGER_SOURCES') {
                    continue;
                }
                $diff[] = $k . ': (none) -> ' . var_export($v, true);
            }
            if ($diff !== []) {
                error_log(
                    sprintf(
                        "[poison] %s %s::%s %s\n",
                        date('H:i:s'),
                        static::class,
                        $this->name(),
                        implode('; ', $diff)
                    ),
                    3,
                    '/tmp/guard_trips.log'
                );
            }
        }
        // ENV-РЕСТОР: вернуть снапшот, снять переменные, которых не было.
        // ГЕЙТ f18: пустой envSnapshot (невалидный базлайн) + wipe-цикл =
        // ПОЛНОЕ СТИРАНИЕ среды воркера (array_diff_key(getenv(), []) = всё).
        // Невалидный базлайн → restore/wipe отключены, среда не трогается.
        if ($this->envSnapshot !== []) {
            foreach ($this->envSnapshot as $k => $v) {
                putenv($k . '=' . $v);
            }
            foreach (array_diff_key(getenv(), $this->envSnapshot) as $k => $v) {
                putenv($k);
            }
        }
        // MEMORY-ДЕТЕКТОР (12.10, dmesg OOM 15.4GB): воркер-утечка видна здесь,
        // а не по тихой смерти от ядра. Порог — только сигнал в error_log.
        $mem = memory_get_usage(true);
        if ($mem > 1_000_000_000) {
            error_log(sprintf('[test][MEM] %.2f GB before restore — утечка в этом тесте/воркере', $mem / 1e9));
        }
        \BeeSwarm\Infra\RngIsolation::assertClean();
        // ИЗОЛЯЦИЯ (15.08): periodic-save (doTick каждые 100 тиков) пишет
        // bee_persistence — без очистки следующий тест процесса видит
        // RESTORE чужих пчёл (флаки BehavioralDiversity/HiveZeroTicks/
        // LifetimeMetric в paratest!).
        try {
            Database::get()->exec('DELETE FROM bee_persistence');
        } catch (\Throwable $e) {
            // нет таблицы — легитимно; остальное — видно
            if (! str_contains($e->getMessage(), 'no such table')) {
                error_log('[test] bee_persistence cleanup: ' . $e->getMessage());
            }
        }
        parent::tearDown();
    }
}
