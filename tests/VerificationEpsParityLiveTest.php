<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * V0.16 WU-2 (verifier-eps-parity): живой путь доставки epsilon.
 *
 * Контракт спеки: recordDiscovery → spawnForLaw пишет epsilon калибровки
 * открывателя (Hive::getEpsilon(fp), тот же кэш) в колонку
 * verification_tasks.epsilon; исполнитель (WU-1 резолвер) поднимает им
 * порог поиска. Ghost-задача (fingerprint='') → epsilon NULL → fallback
 * константы.
 *
 * Главный критерий стори: partial-закон cv≈0.06 (eps домена 0.0865) —
 * VCONFIRMED БЕЗ env-костыля VVERIFY_CV_TRAIN_MAX (WU-5 V0.14: зона
 * cv∈[0.05, eps] была архитектурно неподтверждаема).
 *
 * Фикстура (проверена php-пробами до ассертов, diag6-9): x = 2^0..2^11,
 * y=(2x+1)(1±6%) → cv закона 0.05999999979. Закон — форма, которую
 * discovery РЕАЛЬНО порождает на этих данных `((x0+(x0/Rminx0))+K1)`
 * (аффинное тождество 2x при min(x)=1; прецедент WU-5 Concrete: закон
 * `((x1/R+x1)−K2)` — тоже R-форма). На чисто пропорциональном законе
 * y=a·x тень x0 связывает закон по cv (масштаб-инвариантность) и
 * parsimony выбирает её — shape-гейт VREFUTED; фикстура с интерсептом
 * делает тень строго хуже (cv 0.06 vs 0.12), что и требовалось.
 *
 * Глубина: phpunit.xml форсирует SEARCH_DEPTH_MAX=2 (скорость suite),
 * но partial-форма по природе depth-3 (именно поэтому она partial —
 * непростые формы). Прод-демон эскалирует до 4; тест ставит 3
 * (prod-faithful параметр среды, восстанавливается в tearDown — diag9:
 * MAX=2 → no_form, MAX=3/4 → VCONFIRMED).
 */
final class VerificationEpsParityLiveTest extends TestCase
{
    /**
     * Форма закона, порождаемая discovery на partial-фикстуре (diag6).
     */
    private const LAW_ATOM = '((x0+(x0/Rminx0))+K1)';

    /**
     * Калибровка домена (инъекция): cv закона 0.06 ∈ [0.05, 0.0865].
     */
    private const EPS = 0.0865;

    private string $logFile;

    private Hive $hive;

    private string $prevDepthMax;

    protected function setUp(): void
    {
        putenv('VVERIFY_CV_TRAIN_MAX'); // чистый env: порог = epsilon задачи
        // Prod-глубина эскалации (phpunit.xml изолирует MAX=2 для скорости
        // suite; partial-формы по природе depth-3 — diag9). Восстановление.
        $this->prevDepthMax = (string) (getenv('SEARCH_DEPTH_MAX') ?: '2');
        putenv('SEARCH_DEPTH_MAX=3');
        Database::reset();
        Database::get();
        $this->logFile = tempnam(sys_get_temp_dir(), 'vex_eps_');
        $this->hive = new Hive(maxTicks: 0, logFile: $this->logFile);
        $this->hive->run();
    }

    protected function tearDown(): void
    {
        putenv('VVERIFY_CV_TRAIN_MAX');
        putenv('SEARCH_DEPTH_MAX=' . $this->prevDepthMax);
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
        Database::setPath(':memory:');
        Database::reset();
    }

    /**
     * Инъекция калибровки открывателя: ReflectionProperty в приватный кэш
     * (прецедент Hive::$foragedTasksGlobal). calibrateEpsilon не звать —
     * NullCalibrator недетерминирован, контракт теста = фиксированный eps.
     */
    private function injectEpsilon(string $fp, float $eps): void
    {
        $p = new \ReflectionProperty(Hive::class, 'epsilonCache');
        $p->setAccessible(true);
        $cache = $p->getValue($this->hive);
        $cache[$fp] = $eps;
        $p->setValue($this->hive, $cache);
    }

    private function invokeDiscovery(string $fp, string $domain, array $X, array $y): void
    {
        $foundAny = false;
        $m = new \ReflectionMethod(Hive::class, 'recordDiscovery');
        $m->setAccessible(true);
        $m->invokeArgs($this->hive, [
            [
                'atom' => self::LAW_ATOM,
                'cv' => 0.0600,
                'class' => 'EMPIRICAL',
            ],
            [
                'name' => 'vex_eps',
                'domain' => $domain,
                'fingerprint' => $fp,
            ],
            $domain,
            &$foundAny,
            $X,
            $y,
        ]);
        self::assertTrue($foundAny, 'запись закона не должна пострадать');
    }

    private function queueColumn(string $col): array
    {
        $rows = Database::get()->query(
            "SELECT kind, {$col} AS v FROM verification_tasks ORDER BY id"
        )->fetchAll(\PDO::FETCH_ASSOC);

        return array_column($rows, 'v', 'kind');
    }

    /**
     * Partial-фикстура (diag6): x=2^0..2^11, y=(2x+1)(1±6%).
     * cv закона = 0.05999999979 (пробой), тень x0+K1 = 0.1217 (строго хуже).
     */
    private static function partialData(): array
    {
        $rows = [];
        $factors = [0.94, 1.06];
        for ($i = 0; $i < 12; $i++) {
            $x = (float) (2 ** $i);
            $rows[] = [$x, (2.0 * $x + 1.0) * $factors[$i % 2]];
        }
        $X = array_map(static fn (array $r): array => [$r[0]], $rows);

        return [$X, array_column($rows, 1)];
    }

    /**
     * RED: живой путь пишет epsilon открывателя во ВСЕ 6 V-задач.
     */
    public function testLiveSpawnWritesEpsilonColumn(): void
    {
        $this->injectEpsilon('fp_eps_live', self::EPS);
        [$X, $y] = self::partialData();
        $this->invokeDiscovery('fp_eps_live', 'test_eps_live', $X, $y);

        $eps = $this->queueColumn('epsilon');
        self::assertCount(6, $eps, 'пререквизит: живой путь заспавнил 6 задач');
        foreach ($eps as $kind => $v) {
            self::assertSame(self::EPS, (float) $v, "задача {$kind} несёт eps открывателя");
        }
    }

    /**
     * RED: ghost-задача (fingerprint='') → epsilon NULL (fallback константы
     * на исполнителе; кэш открывателя для '' не читается).
     */
    public function testGhostSpawnLeavesEpsilonNull(): void
    {
        [$X, $y] = self::partialData();
        $this->invokeDiscovery('', 'test_eps_ghost', $X, $y);

        $eps = $this->queueColumn('epsilon');
        self::assertCount(6, $eps, 'пререквизит: 6 задач заспавнено');
        foreach ($eps as $kind => $v) {
            self::assertNull($v, "ghost-задача {$kind} без калибровки — epsilon NULL");
        }
    }

    /**
     * ГЛАВНЫЙ КРИТЕРИЙ СТОРИ (RED): partial-закон cv≈0.06 при eps=0.0865 →
     * resample VCONFIRMED через живой путь, БЕЗ env-костыля.
     * До стори: findBest на константе 0.05 → no_form → inconclusive.
     *
     * limit=1 (не 5): консенсус 5× — критерий escrow-сторей (WU-3/4), здесь
     * достаточно одного подтверждения. Причина: depth-3 прогоны исполнителя —
     * самая тяжёлая нагрузка suite; полный батч 5×5 сдвинул wall-clock-budget
     * тесты EnsembleCertifierTest за тайминг-клифф под -p8 (2 полных прогона
     * FAIL, соло PASS — класс 13.09).
     */
    public function testPartialLawConfirmedOnLivePathWithoutEnvCrutch(): void
    {
        $this->injectEpsilon('fp_eps_live', self::EPS);
        [$X, $y] = self::partialData();
        $this->invokeDiscovery('fp_eps_live', 'test_eps_live', $X, $y);

        $m = new \ReflectionMethod(Hive::class, 'runPendingVerificationTasks');
        $m->setAccessible(true);
        $m->invoke($this->hive, 'test_eps_live', 1);

        $rows = Database::get()->query(
            "SELECT kind, status FROM verification_tasks
             WHERE kind LIKE 'resample%' AND status != 'pending' ORDER BY id"
        )->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $rows, 'пререквизит: resample_1 исполнен');
        self::assertSame(
            'confirmed',
            $rows[0]['status'],
            'partial-закон cv≈0.06 подтверждён калибровкой домена (resample_1)'
        );
        $log = (string) file_get_contents($this->logFile);
        self::assertStringContainsString('VCONFIRMED', $log, 'исполнитель логирует подтверждение');
    }

    /**
     * Контроль (anti-self-deception): та же фикстура БЕЗ калибровки в кэше —
     * executor на константе 0.05 не находит форму → inconclusive.
     * Дельта теста выше обязана идти от epsilon, не от чего-то ещё.
     */
    public function testControlWithoutEpsilonStaysInconclusive(): void
    {
        [$X, $y] = self::partialData();
        $this->invokeDiscovery('fp_eps_ctrl', 'test_eps_ctrl', $X, $y);

        $m = new \ReflectionMethod(Hive::class, 'runPendingVerificationTasks');
        $m->setAccessible(true);
        $m->invoke($this->hive, 'test_eps_ctrl', 1);

        $rows = Database::get()->query(
            "SELECT kind, status FROM verification_tasks
             WHERE kind LIKE 'resample%' AND status != 'pending' ORDER BY id"
        )->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $rows, 'пререквизит: resample_1 исполнен');
        self::assertSame(
            'inconclusive',
            $rows[0]['status'],
            'без epsilon порог 0.05 — зона partial недостижима (resample_1)'
        );
    }

    /**
     * RED: миграция epsilon-колонки fail-loud (agent-review F3 + premortem H3:
     * голый catch(PDOException) глотал lock/busy/IO, не только duplicate-column
     * → тихая смерть миграции → рантайм-фейлы INSERT'ов при зелёном старте).
     * PRAGMA-хелпер вместо try/catch: ошибки ALTER не глотаются.
     * Fault-injection (lock на файловой БД) вне scope — контракт хелпера
     * структурный: PRAGMA вместо catch.
     */
    public function testColumnExistsHelperContract(): void
    {
        $db = Database::get();
        self::assertTrue(
            Database::columnExists($db, 'verification_tasks', 'epsilon'),
            'миграция обеспечивает epsilon-колонку'
        );
        self::assertFalse(
            Database::columnExists($db, 'verification_tasks', 'nope_column'),
            'чужая колонка не выдаётся за есть'
        );
        self::assertFalse(
            Database::columnExists($db, 'table_absent_42', 'x'),
            'несуществующая таблица = false, не взрыв'
        );
    }

    /**
     * RED: inequality-guard спеки — malformed epsilon (отрицательный /
     * не-finite) клампится к ghost-пути (NULL → константа), не автопроход.
     * eps_verifier <= eps_opener структурно: единственный писатель — Hive
     * через getEpsilon того же fp, что порог открывателя.
     */
    public function testSpawnClampsMalformedEpsilonToGhost(): void
    {
        $src = new \BeeSwarm\Hive\VerificationTaskSource();
        $src->spawnForLaw('(K2×x0)', 'test_eps_guard', 'fp_g', [], -0.5);

        $eps = $this->queueColumn('epsilon');
        self::assertCount(6, $eps, 'пререквизит: 6 задач заспавнено');
        foreach ($eps as $kind => $v) {
            self::assertNull($v, "отрицательный epsilon ({$kind}) → ghost-путь, не порог");
        }
    }
}
