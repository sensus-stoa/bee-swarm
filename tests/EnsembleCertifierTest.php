<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Certification\EnsembleCertifier;
use PHPUnit\Framework\TestCase;

/**
 * V0.11 WU-2: ядро EnsembleCertifier (§1.9).
 *
 * Процедура: K членов, member k = bootstrap 80% train (with replacement,
 * seed 1000+k) + гейт θ_k из GATE_GRID (цикл, порядок от seed 42).
 * Held-out хвост НИКОГДА не ресемплится.
 *
 * Гейты:
 *  (a) рецидив консенсус-формы >= 80% от foundN (ЗНАМЕНАТЕЛЬ = foundN,
 *      члены с found=true, НЕ K — hindsight-фикс runner'а);
 *  (b) ensemble held-out CV консенсус-представителя на нетронутом хвосте
 *      <= 0.10;
 *  (c) null-gate: рецидив на реальных > max рецидива null-ансамблей.
 *
 * Вердикты: ENSEMBLE_CERT | UNSTABLE_CERTIFICATE (прошёл single-run,
 * рецидив < 50%) | NO_CONSENSUS.
 *
 * FIXTURE-КОНТРАКТ (пробой 16.09): с дефолтной beam-шапкой (SEARCH_BEAM_K=10)
 * Search::find НЕ находит y=x0*x1+x0*x2 — beam срезает родителей compose
 * ((x0×x1),(x0×x2) — слабые поодиночке). Unit-фикстуры WU-2 обязаны ставить
 * SEARCH_BEAM_K=0 (beam OFF): exact найден, shape '((*×*)+(*×*))'.
 * env-шапка bench'ей с beam=10 — для других контекстов, НЕ для compose-тестов.
 */
final class EnsembleCertifierTest extends TestCase
{
    private string $logFile = '';

    protected function setUp(): void
    {
        putenv('SWARM_DB_PATH=:memory:');
        putenv('FORAGER_SOURCES=:');
        putenv('NO_BIRTH=1');
        putenv('SEARCH_NO_PREREG=1');
        putenv('SEARCH_BEAM_K=0');
        // V0.17 WU-2c (19.09): изоляция от cross-test pollution. Serial-прогон
        // slow-группы = ОДИН процесс, Database::get() singleton шарится между
        // тестами. BD-фикстура (BehavioralDiversityTest, сид 777) пишет законы
        // в laws + атомы в grammar_ops; Grammar-конструктор тянет grammar_ops →
        // перебор certify идёт с ЧУЖИМИ атомами (11→13 ops) → три члена
        // found=false → NO_CONSENSUS (clean1/2/3; run1b GREEN — рандом-сид BD
        // не рождал атомов). Матрица изоляции: соло GREEN, BD+Certifier RED,
        // Anchor+Certifier GREEN, Search-проба после BD-прогона GREEN.
        // Полная чистка schema-таблиц BD-протечки.
        $db = \BeeSwarm\Infra\Database::get();
        $db->exec('DELETE FROM laws');
        $db->exec("DELETE FROM grammar_ops WHERE source = 'discovered'");
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'ens_cert_');
    }

    protected function tearDown(): void
    {
        foreach (['NO_BIRTH', 'SEARCH_NO_PREREG', 'SEARCH_BEAM_K'] as $k) {
            putenv($k);
        }
        // PHPUnit <env force=true> ставит env один раз на старте процесса;
        // putenv($k) выше снимает переменную ДО КОНЦА ПРОЦЕССА — все
        // последующие классы в этом воркере получают getenv()=false и
        // TestCase-гвард скипает их (678 скипов, 23.09). Восстановить.
        putenv('SEARCH_BEAM_K=10');
        if ($this->logFile !== '' && is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    /**
     * Синтетика n=200, y = x0*x1 + x0*x2 (точный закон, depth 2 compose).
     */
    private function syntheticData(): array
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
     * V0.18 WU-3: budgetTicks=100 — полный d=2 перебор compose-домена =
     * 11-14 тиков (проба 23.09) → бюджет не исчерпывается, вердикт
     * детерминирован данными. Wall-clock-класс ушёл — не slow.
     */
    public function testExactLawGetsEnsembleCert(): void
    {
        [$X, $y] = $this->syntheticData();
        $out = EnsembleCertifier::certify(
            $X,
            $y,
            [
                'k' => 5,
                'depth' => 2,
                'test_ratio' => 0.2,
                'budget_ticks' => 100,
                'gate_grid' => [0.05, 0.1],
                'bootstrap_frac' => 0.8,
                'log_file' => $this->logFile,
            ],
        );

        $this->assertSame('ENSEMBLE_CERT', $out['verdict'], json_encode($out));
        $this->assertSame('((*×*)+(*×*))', $out['shape']);
        $this->assertGreaterThanOrEqual(0.8, $out['recurrence']);
        $this->assertLessThanOrEqual(0.10, $out['ensemble_cv_h']);
        // Гейт (c) вынесен в testFullGatesWithNullEnsembleSmall (wall-clock:
        // null-члены жгут budget_sec на шуме — питфолл SelfDiagnosis-класса).
    }

    public function testShuffledTargetGetsNoConsensus(): void
    {
        [$X, $y] = $this->syntheticData();
        mt_srand(999);
        shuffle($y); // uniform-шум: метки перемешаны, структура уничтожена
        $out = EnsembleCertifier::certify(
            $X,
            $y,
            [
                'k' => 5,
                'depth' => 2,
                'test_ratio' => 0.2,
                'budget_ticks' => 100,
                'gate_grid' => [0.05, 0.1],
                'bootstrap_frac' => 0.8,
                'null_ensembles' => 0,
                'log_file' => $this->logFile,
            ],
        );

        $this->assertContains(
            $out['verdict'],
            ['NO_CONSENSUS', 'UNSTABLE_CERTIFICATE'],
            'Шум не может дать устойчивый консенсус: ' . json_encode($out['shape'] ?? null),
        );
        if ($out['verdict'] === 'NO_CONSENSUS') {
            $this->assertNull($out['shape']);
        }
    }

    public function testMembersAreSequentialAndKRespected(): void
    {
        [$X, $y] = $this->syntheticData();
        $out = EnsembleCertifier::certify(
            $X,
            $y,
            [
                'k' => 3,
                'depth' => 2,
                'test_ratio' => 0.2,
                'budget_ticks' => 100,
                'gate_grid' => [0.05],
                'bootstrap_frac' => 0.8,
                'log_file' => $this->logFile,
            ],
        );

        $this->assertCount(3, $out['members'], 'K членов ровно, последовательно');
        foreach ($out['members'] as $i => $m) {
            $this->assertSame($i + 1, $m['member']);
        }
    }

    public function testUnstableWhenRecurrenceBelowHalf(): void
    {
        // UNSTABLE_CERTIFICATE: single-run прошёл (формула есть), консенсус < 50%.
        // Конструируем через доменную структуру: не отдать из certify() напрямую
        // нельзя без инверсии гейтов — используем публичный контракт: утилита
        // verdictOf() должна существовать и классифицировать рецидив < 50%.
        $this->assertSame(
            'UNSTABLE_CERTIFICATE',
            EnsembleCertifier::verdictOf(0.4, true),
            'Рецидив 40% при найденном single-run = UNSTABLE',
        );
        $this->assertSame(
            'NO_CONSENSUS',
            EnsembleCertifier::verdictOf(0.4, false),
            'Нет кандидата вообще = NO_CONSENSUS',
        );
        // F3 (deleg_258f3c12): вердиктOf согласован с finalVerdict —
        // rec∈[0.5,0.8) не проходит гейт (a) 0.80.
        $this->assertSame('NO_CONSENSUS', EnsembleCertifier::verdictOf(0.6, true));
        $this->assertSame('ENSEMBLE_CERT', EnsembleCertifier::verdictOf(0.8, true));
    }

    /**
     * Полная механика с null-гейтом (null 2×2, не дефолт 5×5).
     * V0.18 WU-3: budgetTicks=50 > полного d=2 перебора члена (11-14 тиков,
     * проба 23.09) → вердикт детерминирован данными, не бюджетом.
     * V0.17 wall-clock-класс ушёл — не slow.
     */
    public function testFullGatesWithNullEnsembleSmall(): void
    {
        [$X, $y] = $this->syntheticData();
        $out = EnsembleCertifier::certify(
            $X,
            $y,
            [
                'k' => 3,
                'depth' => 2,
                'test_ratio' => 0.2,
                'budget_ticks' => 50,
                'gate_grid' => [0.05, 0.1],
                'bootstrap_frac' => 0.8,
                'null_ensembles' => 2,
                'null_k' => 2,
                'log_file' => $this->logFile,
            ],
        );

        $this->assertSame('ENSEMBLE_CERT', $out['verdict'], json_encode($out));
        $this->assertNotNull($out['null_max_recurrence']);
        $this->assertLessThan($out['recurrence'], $out['null_max_recurrence']);
    }

    /**
     * F1 (agent-review deleg_258f3c12): null_k жив — null-ансамбль гоняет
     * РОВНО null_k членов (не K). members null-ансамбля не публикуются,
     * поэтому проверка через members основного ансамбля невозможна; пинним
     * контракт конфига: null_k=1 x null_ensembles=1 = 1 null-член, его
     * рецидив на однозначном шуме 0/1 (не 5 членов, как до фикса).
     * V0.18 WU-3: budgetTicks — вердикт детерминирован, не slow.
     */
    public function testNullKIsLive(): void
    {
        [$X, $y] = $this->syntheticData();
        $out = EnsembleCertifier::certify(
            $X,
            $y,
            [
                'k' => 3,
                'depth' => 2,
                'test_ratio' => 0.2,
                'budget_ticks' => 50,
                'gate_grid' => [0.05],
                'bootstrap_frac' => 0.8,
                'null_ensembles' => 1,
                'null_k' => 1,
                'log_file' => $this->logFile,
            ],
        );

        $this->assertSame('ENSEMBLE_CERT', $out['verdict'], json_encode($out));
        // null_k=1: рецидив null-ансамбля либо 0 (член не нашёл), либо 1
        // (единственный нашёл) — но НЕ статистика 5 членов. Гейт (c) просто
        // должен пройти; пиннинг — отсутствие модификации основного k.
        $this->assertSame(3, $out['k'], 'Основной ансамбль остаётся K членов');
        $this->assertLessThan($out['recurrence'], $out['null_max_recurrence']);
    }
}
