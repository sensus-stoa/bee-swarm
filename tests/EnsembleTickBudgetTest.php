<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Certification\EnsembleCertifier;

/**
 * V0.18 WU-3: тиковый бюджет certifier'а.
 *
 * Контракты (Benchmarks/TICK_BUDGET_calibration.md, 23.09):
 * - ENSEMBLE_BUDGET_TICKS: config['budget_ticks'] > env > default 300
 *   (300s-эквивалент 400 тиков на worst-фазе, запас 25%).
 * - legacy ENSEMBLE_BUDGET_SEC жив (переходный период), но тики приоритетнее;
 *   бюджет=0 = бесконечный (H5) — env-гарды >0 сохраняются.
 * - Член ансамбля получает ОДИН бюджет (тики), wall-clock kill-switch
 *   SEARCH_WALLCLOCK_CAP_S остаётся поверх (INC-2: тик ≠ секунда, 100x разброс).
 *
 * Фикстуры тик-исчерпания: бисекция показала полный перебор heat d=4
 * прод-грамматики = 32-33 тика; для исчерпания на micro-бюджетах берём
 * heavy-фикстуру и малые тики (1-10) — окно [1..N_full-1].
 */
final class EnsembleTickBudgetTest extends TestCase
{
    private string $logFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        putenv('SWARM_DB_PATH=:memory:');
        putenv('FORAGER_SOURCES=:');
        putenv('NO_BIRTH=1');
        putenv('SEARCH_NO_PREREG=1');
        putenv('SEARCH_BEAM_K=0');
        // Изоляция грамматики (23.09): живые-Hive тесты раньше по процессу
        // рождают birth-опы (mul(sq) и пр.) в ОБЩЕЙ :memory: Database ->
        // 16 ops вместо 11 -> Search глубже -> DEPTH вместо find. Чистим
        // birth-опы; база/семантика восстанавливаются bootstrap'ом.
        // Чистим ВСЮ grammar_ops: культура/boost/discovered строки живого
        // Hive того же процесса расширяют грамматику (13+ ops) -> L2-пул
        // slice(0,40) выталкивает compose-пары -> ENSEMBLE фейлы. В соло БД
        // пуста — реплицируем именно это состояние.
        \BeeSwarm\Infra\Database::run('DELETE FROM grammar_ops');
        \BeeSwarm\Core\ExpressionEvaluator::clearDefCache();
        \BeeSwarm\Core\AtomRegistry::clearDefCache();
        // Text-атомы (match_label(GI) и пр.) живут в static-реестре процесса
        // и переживают DELETE FROM grammar_ops — чистить и их.
        \BeeSwarm\Core\AtomRegistry::resetDiscoveredAtoms();
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'ens_tick_');
    }

    protected function tearDown(): void
    {
        foreach (['ENSEMBLE_BUDGET_TICKS', 'ENSEMBLE_BUDGET_SEC', 'SWARM_DB_PATH', 'FORAGER_SOURCES', 'NO_BIRTH', 'SEARCH_NO_PREREG', 'SEARCH_BEAM_K'] as $k) {
            putenv($k);
        }
        // PHPUnit <env force=true> ставит env один раз на старте процесса;
        // putenv($k) выше снимает переменную ДО КОНЦА ПРОЦЕССА (678 скипов,
        // 23.09). Восстановить значения phpunit.xml.
        putenv('SWARM_DB_PATH=:memory:');
        putenv('SEARCH_BEAM_K=10');
        if ($this->logFile !== '' && is_file($this->logFile)) {
            unlink($this->logFile);
        }
        parent::tearDown();
    }

    /**
     * Точный compose-закон y = x0*x1 + x0*x2, n=200 (как syntheticData
     * EnsembleCertifierTest): shape '((*×*)+(*×*))', members дешёвые,
     * сертификация заканчивается вердиктом, не бюджетом.
     */
    private function affordableDomain(): array
    {
        mt_srand(777);
        $X = [];
        $y = [];
        for ($i = 0; $i < 200; $i++) {
            $x0 = mt_rand() / mt_getrandmax() * 10;
            $x1 = mt_rand() / mt_getrandmax() * 10;
            $x2 = mt_rand() / mt_getrandmax() * 10;
            $X[] = [$x0, $x1, $x2];
            $y[] = $x0 * $x1 + $x0 * $x2;
        }

        return [$X, $y];
    }

    /**
     * Тяжёлый домен: 20 фич шума — члены исчерпывают МАЛЫЙ тик-бюджет
     * (diag члена = TICKS_EXHAUSTED), ансамбль даёт NO_CONSENSUS/UNSTABLE
     * быстро и детерминированно (тик-механика, не wall-clock).
     */
    private function heavyDomain(): array
    {
        $X = [];
        $y = [];
        mt_srand(9);
        for ($i = 0; $i < 30; $i++) {
            $row = [];
            for ($j = 0; $j < 20; $j++) {
                $row[] = mt_rand() / mt_getrandmax() * 2 ** ($j % 12);
            }
            $X[] = $row;
            $y[] = mt_rand() / mt_getrandmax();
        }

        return [$X, $y];
    }

    /**
     * Приоритет каналов: config['budget_ticks'] > env ENSEMBLE_BUDGET_TICKS
     * > legacy config['budget_sec'] > env ENSEMBLE_BUDGET_SEC > default 300.
     * Пинню через лог-файл certifier'а: budget_ticks=2 на тяжёлом домене
     * возвращает вердикт (члены исчерпали 2 тика), а не висит.
     */
    public function testBudgetTicksConfigExhaustsFastOnHeavyDomain(): void
    {
        [$X, $y] = $this->heavyDomain();
        $t0 = microtime(true);
        $out = EnsembleCertifier::certify($X, $y, [
            'k' => 3,
            'depth' => 2,
            'test_ratio' => 0.2,
            'budget_ticks' => 2,
            'gate_grid' => [0.15],
            'null_ensembles' => 0,
            'log_file' => $this->logFile,
        ]);
        $elapsed = microtime(true) - $t0;

        $this->assertSame('NO_CONSENSUS', $out['verdict']);
        // 3 члена × 2 тика: исчерпание тиками = доли секунды даже на тяжёлом
        // домене (сравни: полный перебор этого домена — секунды на член).
        $this->assertLessThan(
            30.0,
            $elapsed,
            "2 тика/член должны исчерпываться быстро, получили {$elapsed}s"
        );
        // C2 (criterion-audit): ассерт на МЕХАНИКУ бюджета (diag члена =
        // тиковое исчерпание), не на «шум не фити». Спурный find на шуме
        // 0.15-гейте возможен конструктивно (σ|r|≈0.19 на n=30); спасает
        // детерминизм: seed 9 фиксирован, за 2 тика accept-цикл не успевает.
        foreach ($out['members'] as $m) {
            $this->assertFalse($m['found'], 'за 2 тика не находится (детерминизм seed 9)');
            $this->assertSame('TICKS_EXHAUSTED', $m['diag'], 'член остановлен тик-бюджетом, diag=' . ($m['diag'] ?? 'null'));
        }
    }

    /**
     * env-канал ENSEMBLE_BUDGET_TICKS: без config — читается env.
     */
    public function testEnvBudgetTicksRead(): void
    {
        putenv('ENSEMBLE_BUDGET_TICKS=2');
        [$X, $y] = $this->heavyDomain();
        $t0 = microtime(true);
        $out = EnsembleCertifier::certify($X, $y, [
            'k' => 3,
            'depth' => 2,
            'test_ratio' => 0.2,
            'gate_grid' => [0.15],
            'null_ensembles' => 0,
            'log_file' => $this->logFile,
        ]);
        $elapsed = microtime(true) - $t0;

        $this->assertSame('NO_CONSENSUS', $out['verdict']);
        $this->assertLessThan(30.0, $elapsed, "env ticks=2 должны исчерпываться быстро, получили {$elapsed}s");
    }

    /**
     * Боевой домен: тик-бюджет не мешает находить (found-члены остаются).
     *
     * 62s соло (k=5 × compose 200 строк): тик-детерминированный вердикт —
     * в fast (юзер-челлендж «опять fast/slow?»: длительность — не основание
     * для slow; slow = только недетерминизм). -p8 терпит +60s.
     */
    public function testTickBudgetDoesNotBreakExactLaw(): void
    {
        [$X, $y] = $this->affordableDomain();
        $out = EnsembleCertifier::certify($X, $y, [
            'k' => 5,
            'depth' => 2,
            'test_ratio' => 0.2,
            'budget_ticks' => 300,
            'gate_grid' => [0.05, 0.1],
            'bootstrap_frac' => 0.8,
            'log_file' => $this->logFile,
        ]);

        $this->assertSame('ENSEMBLE_CERT', $out['verdict'], json_encode($out));
        $this->assertSame('((*×*)+(*×*))', $out['shape']);
    }

    /**
     * H5-гвард (бюджет=0 = INF) распространяется на тики: env '0'/мусор →
     * fallback на следующий канал (не 0).
     *
     * Осознанный fall-through (criterion-audit C1, deleg_6fcba1f2): при
     * отсутствии валидного тикового канала дефолт = LEGACY [300s, null]
     * (300 секунд, не тики). Переходный период: тик-default 300 вводится
     * только явным env/config оператора; молчаливая смена секунды→тики
     * нарушила бы «оператор понимает, что получит».
     *
     * 37s соло (k=3, compose 200 строк) — в fast по той же причине.
     */
    public function testZeroOrGarbageTicksFallThrough(): void
    {
        putenv('ENSEMBLE_BUDGET_TICKS=0');
        [$X, $y] = $this->affordableDomain();
        // Если бы '0' прошёл как тик-бюджет → каждый член мгновенно
        // TICKS_EXHAUSTED → NO_CONSENSUS. Гард должен провалиться на legacy
        // default (300 SECONDS) и дать ENSEMBLE_CERT.
        $out = EnsembleCertifier::certify($X, $y, [
            'k' => 3,
            'depth' => 2,
            'test_ratio' => 0.2,
            'gate_grid' => [0.05, 0.1],
            'bootstrap_frac' => 0.8,
            'log_file' => $this->logFile,
        ]);
        $this->assertSame('ENSEMBLE_CERT', $out['verdict'], "env '0' должен игнорироваться (H5: 0 = INF), не глушить членов");
    }
}
