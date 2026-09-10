<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\LawShape;
use BeeSwarm\Hive\Hive;
use BeeSwarm\Hive\VerificationExecutor;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * V0.14 WU-2 (verification-economy): исполнитель V-задач.
 *
 * Контракт спеки: resample → бутстрап-срез train, find(), подтверждение при
 * LawShape(находки) == law_shape задачи (T4-маска). inverted → поиск на −y,
 * подтверждение если маска совпала И anchor-статистика (median pred/y) после
 * снятия знака не расходится с исходной более чем в 2 раза (иначе ANOMALY).
 *
 * Память (ops-урок Demo #3): train-only срез, beam ≤ 15 — параметры среды.
 *
 * Данные: V-задача несёт data_json (train-срез оригинала, cap 30) — WU-1
 * очередь без данных была мертва для асинхронного исполнителя.
 */
final class VerificationExecutorTest extends TestCase
{
    private string $logFile;

    private Hive $hive;

    protected function setUp(): void
    {
        Database::reset();
        Database::get();
        $this->logFile = tempnam(sys_get_temp_dir(), 'vex_exec_');
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
     * 12 строк точного закона y=2x (≥ tMin=10 для 1 фичи).
     */
    private static function exactData(): array
    {
        $rows = [];
        for ($x = 1; $x <= 12; $x++) {
            $rows[] = [(float) $x, 2.0 * $x];
        }

        return $rows;
    }

    private function vtask(array $overrides = []): array
    {
        return array_merge([
            'law_id' => 1,
            'law_formula' => '(K2×x0)',
            'law_shape' => LawShape::of('(K2×x0)'),
            'kind' => 'resample_3',
            'resample_seed' => 3,
            'target_sign' => 1,
            'fingerprint' => 'fp_wu2',
            'domain' => 'test_vex_dom',
            'data_json' => json_encode(self::exactData()),
        ], $overrides);
    }

    private function data12(): array
    {
        $rows = self::exactData();
        $X = array_map(static fn (array $r): array => [$r[0]], $rows);

        return [$X, array_column($rows, 1)];
    }

    /**
     * RED: resample на точных данных → подтверждение по T4-маске.
     */
    public function testResampleConfirmedOnExactData(): void
    {
        [$X, $y] = $this->data12();
        $result = (new VerificationExecutor())->runResample($this->vtask(), $X, $y);

        self::assertSame('confirmed', $result['outcome'], json_encode($result));
        self::assertFalse($result['anomaly']);
    }

    /**
     * RED: маска находки ≠ law_shape задачи → refuted (T4-гейт).
     */
    public function testResampleRefutedOnShapeMismatch(): void
    {
        [$X, $y] = $this->data12();
        $result = (new VerificationExecutor())->runResample(
            $this->vtask([
                'law_shape' => '(C+C)',
            ]),
            $X,
            $y
        );

        self::assertSame('refuted', $result['outcome'], json_encode($result));
    }

    /**
     * RED: anchor-статистика — граница 2x (spec: не более чем в 2 раза).
     */
    public function testAnchorRatioBoundary(): void
    {
        [$X, $y] = $this->data12();
        $ex = new VerificationExecutor();

        // (K2×(K2×x0)) pred=4x на y=2x: anchor 4 vs 2 → ratio 2.0 — на границе.
        self::assertSame(2.0, $ex->anchorRatio('(K2×(K2×x0))', '(K2×x0)', $X, $y));
        // (K2×(K2×(K2×x0))): pred=8x → ratio 4.0 — за границей (ANOMALY-зона).
        self::assertSame(4.0, $ex->anchorRatio('(K2×(K2×(K2×x0)))', '(K2×x0)', $X, $y));
        // Зеркальный знак: (K2×(0−x0)) pred=−2x → снятие знака → ratio 1.0.
        self::assertSame(1.0, $ex->anchorRatio('(K2×(0−x0))', '(K2×x0)', $X, $y));
        // Не-вычислимая формула (K3 вне грамматики констант) → null = честное
        // «не могу оценить» (не ANOMALY).
        self::assertNull($ex->anchorRatio('(K3×x0)', '(K2×x0)', $X, $y));
    }

    /**
     * RED: inverted — поиск на −y, контракт исхода + признак инверсии в логе.
     */
    public function testInvertedRunsOnNegatedTarget(): void
    {
        [$X, $y] = $this->data12();
        $logFile = $this->logFile;
        $executor = new VerificationExecutor(
            function (string $m) use ($logFile): void {
                file_put_contents($logFile, $m . "\n", FILE_APPEND);
            }
        );
        $result = $executor->runInverted(
            $this->vtask([
                'kind' => 'inverted',
                'resample_seed' => 0,
                'target_sign' => -1,
            ]),
            $X,
            $y
        );

        self::assertContains(
            $result['outcome'],
            ['confirmed', 'refuted', 'inconclusive'],
            json_encode($result)
        );
        if ($result['anomaly']) {
            self::assertSame('refuted', $result['outcome'], 'ANOMALY = опровержение');
        }
        $log = (string) file_get_contents($this->logFile);
        self::assertStringContainsString('VTASK:inverted', $log, 'исполнитель.mark инверсной ветки');
    }

    /**
     * RED: wiring — pending V-задачи исполняются, статус меняется, лог V*.
     */
    public function testRunPendingFlipsStatusAndLogs(): void
    {
        $this->spawnLawViaDiscovery('(K2×x0)', 'test_vex_dom', 'fp_live2');

        $pending = (int) Database::get()->query(
            "SELECT COUNT(*) FROM verification_tasks WHERE status = 'pending' AND kind LIKE 'resample%'"
        )->fetchColumn();
        self::assertGreaterThanOrEqual(5, $pending, 'пререквизит: спавн дал pending-задачи');

        $this->runPendingOnHive('test_vex_dom', 2);

        $done = (int) Database::get()->query(
            "SELECT COUNT(*) FROM verification_tasks WHERE status != 'pending' AND kind LIKE 'resample%'"
        )->fetchColumn();
        self::assertSame(2, $done, 'лимит 2: ровно две задачи исполнены');

        $log = (string) file_get_contents($this->logFile);
        self::assertMatchesRegularExpression('/VCONFIRMED|VREFUTED|VINCONCLUSIVE/', $log);
    }

    private function spawnLawViaDiscovery(string $atom, string $domain, string $fp): void
    {
        $rows = self::exactData();
        $X = array_map(static fn (array $r): array => [$r[0]], $rows);
        $y = array_column($rows, 1);
        $foundAny = false;
        $m = new \ReflectionMethod(Hive::class, 'recordDiscovery');
        $m->setAccessible(true);
        $m->invokeArgs($this->hive, [
            [
                'atom' => $atom,
                'cv' => 0.001,
                'class' => 'EMPIRICAL',
            ],
            [
                'name' => 'vex_w2',
                'domain' => $domain,
                'fingerprint' => $fp,
            ],
            $domain,
            &$foundAny,
            $X,
            $y,
        ]);
    }

    private function runPendingOnHive(string $domain, int $limit): void
    {
        $m = new \ReflectionMethod(Hive::class, 'runPendingVerificationTasks');
        $m->setAccessible(true);
        $m->invoke($this->hive, $domain, $limit);
    }
}
