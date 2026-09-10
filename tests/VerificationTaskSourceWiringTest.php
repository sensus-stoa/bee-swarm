<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;
use BeeSwarm\Hive\VerificationTaskSource;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * V0.14 WU-1 (story verification-economy): VerificationTaskSource wiring.
 *
 * Среда порождает верификационные задачи из сигналов:
 *  - recordDiscovery (новый закон) → 5×resample_1..5 + 1×inverted;
 *  - runContradictionCheck (CONTRADICTION) → 1×inverted_research.
 *
 * Задача несёт {law_id, law_formula, law_shape, kind, resample_seed,
 * target_sign, fingerprint} — контракт исполнителя WU-2.
 *
 * Интеграционный тест живого пути recordDiscovery (fingerprint-gap урок:
 * каждое новое поле task-контракта тестируется через реальный путь записи).
 */
final class VerificationTaskSourceWiringTest extends TestCase
{
    private string $logFile;

    private Hive $hive;

    protected function setUp(): void
    {
        Database::reset();
        Database::get();
        $this->logFile = tempnam(sys_get_temp_dir(), 'vts_wire_');
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

    private function source(): VerificationTaskSource
    {
        return new VerificationTaskSource();
    }

    private function queueCount(string $kind = '%'): int
    {
        $stmt = Database::get()->prepare(
            'SELECT COUNT(*) FROM verification_tasks WHERE kind LIKE ?'
        );
        $stmt->execute([$kind]);

        return (int) $stmt->fetchColumn();
    }

    private function invokeDiscovery(array $d, array $task, string $domain, bool &$foundAny, array $X, array $y): void
    {
        $m = new \ReflectionMethod(Hive::class, 'recordDiscovery');
        $m->setAccessible(true);
        $m->invokeArgs($this->hive, [$d, $task, $domain, &$foundAny, $X, $y]);
    }

    private function runCheck(array $X, array $y, string $taskName): void
    {
        $m = new \ReflectionMethod(Hive::class, 'runContradictionCheck');
        $m->setAccessible(true);
        $m->invoke($this->hive, [
            'name' => $taskName,
            'domain' => 'test_vts',
        ], $X, $y);
    }

    private function lastCandidates(array $formulas): void
    {
        $p = new \ReflectionProperty(Hive::class, 'lastCandidates');
        $p->setAccessible(true);
        $cands = [];
        foreach ($formulas as $i => $f) {
            $cands[] = [
                'atom' => $f,
                'cv' => $i === 0 ? 0.001 : 0.002,
            ];
        }
        $p->setValue($this->hive, $cands);
    }

    /**
     * RED: закон → 6 V-задач: resample_1..5 + inverted, с законом-контрактом.
     */
    public function testSpawnForLawCreatesSixTasks(): void
    {
        $tasks = $this->source()
            ->spawnForLaw('(x0×K2)', 'test_vts_dom', 'fp_alpha');

        self::assertCount(6, $tasks, 'закон порождает 5 resample + 1 inverted');

        $kinds = array_column($tasks, 'kind');
        foreach (['resample_1', 'resample_2', 'resample_3', 'resample_4', 'resample_5', 'inverted'] as $k) {
            self::assertContains($k, $kinds, "ожидал вид задачи {$k}");
        }

        $first = $tasks[0];
        // V-задача несёт КАНОН-формулу (fake-LOSS урок: единый ключ с laws),
        // не сырую форму открытия.
        self::assertSame(
            \BeeSwarm\Core\ExpressionNormalizer::normalize('(x0×K2)'),
            $first['law_formula']
        );
        self::assertSame('(K2×x0)', $first['law_formula'], 'канон (K2×x0), не сырая (x0×K2)');
        self::assertSame('test_vts_dom', $first['domain']);
        self::assertSame('fp_alpha', $first['fingerprint']);
        self::assertSame('*', self::maskShape((string) $first['law_shape']), 'law_shape — маска формы');
        self::assertSame(1, $first['target_sign']);
        // law_id=0 допустим: T1 вызывает источник напрямую без записи закона;
        // law_id>0 проверяется в T4 (живой путь после recordDiscovery).
        self::assertGreaterThanOrEqual(0, $first['law_id']);

        // Задачи записаны в очередь (таблица — источник WU-2)
        self::assertSame(6, $this->queueCount());
    }

    /**
     * RED: повторное открытие того же закона НЕ дублирует очередь.
     */
    public function testSpawnForLawIsIdempotentPerLawDomainKind(): void
    {
        $this->source()
            ->spawnForLaw('(x0×K2)', 'test_vts_dom', 'fp_alpha');
        $this->source()
            ->spawnForLaw('(x0×K2)', 'test_vts_dom', 'fp_alpha');

        self::assertSame(6, $this->queueCount(), 'INSERT OR IGNORE: дедуп по (law, kind, seed, domain)');
    }

    /**
     * RED: неизвестный закон (нет в laws) → law_id = 0, задачи всё равно порождаются.
     */
    public function testSpawnForUnknownLawStillCreatesTasks(): void
    {
        $tasks = $this->source()
            ->spawnForLaw('(x0+K5)', 'test_ghost_dom', 'fp_beta');

        self::assertCount(6, $tasks);
        self::assertSame(0, $tasks[0]['law_id'], 'law_id=0 — закон ещё не записан (хук после record)');
    }

    /**
     * RED: wiring recordDiscovery — живой путь порождает V-задачи.
     */
    public function testRecordDiscoverySpawnsVerificationTasks(): void
    {
        $foundAny = false;
        $X = [[1.0], [2.0], [3.0]];
        $y = [2.0, 4.0, 6.0];
        $this->invokeDiscovery(
            [
                'atom' => '(x0×K2)',
                'cv' => 0.001,
                'class' => 'EMPIRICAL',
            ],
            [
                'name' => 'vts_w1',
                'domain' => 'test_vts_dom',
                'fingerprint' => 'fp_live',
            ],
            'test_vts_dom',
            $foundAny,
            $X,
            $y
        );

        self::assertTrue($foundAny, 'запись закона не должна пострадать');
        self::assertSame(6, $this->queueCount(), 'живой путь recordDiscovery порождает 6 V-задач');

        $row = Database::get()->query(
            'SELECT law_id, law_formula, fingerprint FROM verification_tasks LIMIT 1'
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertNotFalse($row);
        self::assertSame('fp_live', $row['fingerprint'], 'fingerprint исходного закона передаётся в V-задачу');
        self::assertGreaterThan(0, (int) $row['law_id']);
    }

    /**
     * RED: CONTRADICTION-событие → задача inverted_research.
     */
    public function testContradictionSpawnsInvertedResearch(): void
    {
        // Сначала закон — для закона-контекста противоречия
        $this->lastCandidates(['(x0×K2)', '(x0+K2)']);
        $X = [[1.0], [2.0], [3.0]];
        $y = [2.0, 4.0, 6.0];
        $this->runCheck($X, $y, 'vts_contra');

        $log = (string) file_get_contents($this->logFile);
        self::assertStringContainsString('event=CONTRADICTION', $log, 'противоречие детектировано (пререквизит)');

        $tasks = Database::get()->query(
            "SELECT formula_a, formula_b, kind, domain FROM verification_tasks WHERE kind = 'inverted_research'"
        )->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(1, $tasks, 'CONTRADICTION порождает одну inverted_research-задачу');

        $t = $tasks[0];
        self::assertSame('test_vts', $t['domain']);
        self::assertNotSame('', $t['formula_a']);
        self::assertNotSame('', $t['formula_b'], 'обе гипотезы противоречия в задаче');
    }

    /**
     * RED: противоречия нет → inverted_research не порождается.
     */
    public function testNoContradictionNoResearchTask(): void
    {
        $this->lastCandidates(['(x0×K2)']);
        $X = [[1.0], [2.0], [3.0]];
        $y = [2.0, 4.0, 6.0];
        $this->runCheck($X, $y, 'vts_no_contra');

        self::assertSame(0, $this->queueCount('inverted_research'));
    }

    /**
     * RED: NO_VERIFY_SPAWN=1 — guard глушит спавн V-задач (диагностика).
     */
    public function testNoVerifySpawnGuardSilencesLawSpawning(): void
    {
        putenv('NO_VERIFY_SPAWN=1');
        try {
            $foundAny = false;
            $X = [[1.0], [2.0], [3.0]];
            $y = [2.0, 4.0, 6.0];
            $this->invokeDiscovery(
                [
                    'atom' => '(x0×K2)',
                    'cv' => 0.001,
                    'class' => 'EMPIRICAL',
                ],
                [
                    'name' => 'vts_guard',
                    'domain' => 'test_guard_dom',
                    'fingerprint' => 'fp_guard',
                ],
                'test_guard_dom',
                $foundAny,
                $X,
                $y
            );

            self::assertTrue($foundAny, 'закон записывается при выключенном спавне');
            self::assertSame(0, $this->queueCount(), 'guard глушит V-задачи закона');
        } finally {
            putenv('NO_VERIFY_SPAWN');
        }
    }

    /**
     * RED: NO_VERIFY_SPAWN=1 — guard глушит research-задачу противоречия.
     */
    public function testNoVerifySpawnGuardSilencesResearch(): void
    {
        putenv('NO_VERIFY_SPAWN=1');
        try {
            $this->lastCandidates(['(x0×K2)', '(x0+K2)']);
            $X = [[1.0], [2.0], [3.0]];
            $y = [2.0, 4.0, 6.0];
            $this->runCheck($X, $y, 'vts_guard_contra');

            $log = (string) file_get_contents($this->logFile);
            self::assertStringContainsString('event=CONTRADICTION', $log, 'детекция не глушится');
            self::assertSame(0, $this->queueCount('inverted_research'), 'guard глушит research-задачу');
        } finally {
            putenv('NO_VERIFY_SPAWN');
        }
    }

    private static function maskShape(string $shape): string
    {
        // LawShape канон '(x0×K2)' → '(**)' — проверяем инвариант формы,
        // не конкретный формат маски (он тестируется в LawShapeTest).
        return str_contains($shape, '*') ? '*' : $shape;
    }
}
