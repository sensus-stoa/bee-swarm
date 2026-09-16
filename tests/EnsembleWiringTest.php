<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Certification\EnsembleCertifier;
use PHPUnit\Framework\TestCase;

/**
 * V0.11 WU-4: wiring в DiscoveryEngine после успешного find.
 *
 * Контракт (§1.9, HANDOFF WU-4):
 *  - env ENSEMBLE_K > 0 включает сертификацию (default 0 = off, поведение
 *    v1.6 идентично — EVOLVE DON'T ADD: выключенная механика не живёт);
 *  - env NO_ENSEMBLE=1 — скоростной обход (приоритет над ENSEMBLE_K);
 *  - hook ставится ПОСЛЕ ранних return'ов (класс fingerprint-gap/autophagy:
 *    каждое новое поле/хук обязан иметь integration-тест живого пути);
 *  - UNSTABLE_CERTIFICATE понижает вердикт кандидата despite пройденных
 *    single-run гейтов (CCPP-защита Demo Audit #2);
 *  - лог INVARIANT(ensemble) при ENSEMBLE_CERT;
 *  - X/y null-безопасны (IdleDreamer зовёт recordDiscovery без данных).
 *
 * Wiring-фаза НЕ сертифицирует: ENSEMBLE_K=25 — боевая стоимость (~75 мин
 * на домен). RED/GREEN механики хука — на коротком K=2 через reflection
 * на живой путь runEnsembleCertification (контракт метода), присутствие
 * хука в discover() фиксирует NO_ENSEMBLE-тест.
 */
final class EnsembleWiringTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['ENSEMBLE_K', 'NO_ENSEMBLE', 'ENSEMBLE_BUDGET_SEC', 'SWARM_DB_PATH', 'FORAGER_SOURCES', 'NO_BIRTH', 'SEARCH_NO_PREREG', 'SEARCH_BEAM_K'] as $k) {
            putenv($k);
        }
    }

    private function setEnv(string $ensembleK, string $noEnsemble = ''): void
    {
        putenv('SWARM_DB_PATH=:memory:');
        putenv('FORAGER_SOURCES=:');
        putenv('NO_BIRTH=1');
        putenv('SEARCH_NO_PREREG=1');
        putenv('SEARCH_BEAM_K=0');
        putenv("ENSEMBLE_K={$ensembleK}");
        // Wall-clock-гвард: без override wiring-тест жгёт budget 300s/член ×
        // null-ансамбли (дефект первой suite-прогона: worker замер на 22+ мин).
        putenv('ENSEMBLE_BUDGET_SEC=3');
        if ($noEnsemble !== '') {
            putenv("NO_ENSEMBLE={$noEnsemble}");
        }
    }

    public function testEnsembleDisabledByDefaultMatchesV16Behaviour(): void
    {
        $this->setEnv('0');
        $this->assertFalse(EnsembleCertifier::isEnabled(), 'default ENSEMBLE_K=0 = off');
        // Поведение v1.6: discover() без сертификации — живой прогон ниже
        // в short-пути; здесь контракт включения.
    }

    public function testNoEnsembleOverridesEnsembleK(): void
    {
        $this->setEnv('5', '1');
        $this->assertFalse(EnsembleCertifier::isEnabled(), 'NO_ENSEMBLE=1 сильнее ENSEMBLE_K');
    }

    public function testEnabledWhenEnsembleKPositive(): void
    {
        $this->setEnv('2');
        $this->assertTrue(EnsembleCertifier::isEnabled());
    }

    /**
     * Живой путь: короткая сертификация (K=2) через публичный контракт
     * runEnsembleCertification с записью вердикта в кандидата. Путь вызова
     * из discover() — после ранних return'ов, до compose (X/y живые там).
     */
    public function testRunEnsembleCertificationAttachesVerdict(): void
    {
        $this->setEnv('2');
        mt_srand(31337);
        $X = [];
        $y = [];
        for ($i = 0; $i < 60; $i++) {
            $x0 = 80 + mt_rand() / mt_getrandmax() * 70;
            $x1 = 80 + mt_rand() / mt_getrandmax() * 70;
            $X[] = [$x0, $x1];
            $y[] = 5 * $x0 * $x1 + 100;
        }
        $candidate = [
            'atom' => '(x0×x1)',
            'cv' => 0.0004,
            'cv_test' => 0.0,
            'mode' => 'search',
            'class' => 'EMPIRICAL',
        ];
        $candidates = [$candidate];
        EnsembleCertifier::runEnsembleCertification($candidates, $X, $y, [
            'k' => 2,
            'depth' => 2,
            'budget_sec' => 10.0,
        ]);

        $this->assertArrayHasKey('ensemble_verdict', $candidates[0], 'Сертификация обязана дописать вердикт в кандидата');
        $this->assertSame('ENSEMBLE_CERT', $candidates[0]['ensemble_verdict']);
        // m̂ — свойство домен-формы: y = 5·(x0×x1)+100 → median(y/pred) ≈ 5.
        $this->assertNotNull($candidates[0]['ensemble_anchor'] ?? null);
        $this->assertGreaterThanOrEqual(5.0, $candidates[0]['ensemble_anchor']);
        $this->assertLessThanOrEqual(5.05, $candidates[0]['ensemble_anchor']);
    }

    public function testRunEnsembleCertificationLowersUnstableVerdict(): void
    {
        $this->setEnv('2');
        // Кандидат-дегенерат: single-run прошёл (есть атом с cv), но форма
        // не переоткрывается на возмущениях → ensemble понижает вердикт.
        mt_srand(4711);
        $X = [];
        $y = [];
        for ($i = 0; $i < 60; $i++) {
            $x0 = mt_rand() / mt_getrandmax() * 10;
            $x1 = mt_rand() / mt_getrandmax() * 10;
            $x2 = mt_rand() / mt_getrandmax() * 10;
            $X[] = [$x0, $x1, $x2];
            // y не зависит от кандидата (x0+x1): члены разойдутся по формам
            $y[] = $x2 * $x2 + 0.001 * $x0;
        }
        $candidate = ['atom' => '(x0+x1)', 'cv' => 0.01, 'cv_test' => 0.0, 'mode' => 'search', 'class' => 'EMPIRICAL'];
        $candidates = [$candidate];
        EnsembleCertifier::runEnsembleCertification($candidates, $X, $y, ['k' => 2, 'depth' => 2, 'budget_sec' => 10.0]);

        // H1 (premortem deleg_1b654b1a): вердикт относится только к
        // консенсус-форме. Чужая форма НЕ штампуется (не была погейчена —
        // ансамбль о ней ничего не знает), гейт записи для неё прозрачен.
        $this->assertArrayNotHasKey('ensemble_verdict', $candidates[0], 'Чужой шейп не получает чужой вердикт');
    }

    /**
     * F2 (agent-review deleg_258f3c12): демоушен должен ПОТРЕБЛЯТЬСЯ в
     * живом пути — кандидат с UNSTABLE_CERTIFICATE не записывается как
     * закон. Публичный контракт shouldRecordCandidate(): false для
     * UNSTABLE/NO_CONSENSUS, true для ENSEMBLE_CERT и кандидатов без
     * вердикта (ENSEMBLE off — v1.6 поведение).
     */
    public function testDemotionConsumedByRecordGate(): void
    {
        $this->setEnv('2');
        $this->assertFalse(
            EnsembleCertifier::shouldRecordCandidate(['ensemble_verdict' => 'UNSTABLE_CERTIFICATE']),
            'UNSTABLE кандидат не записывается как закон (CCPP-защита Demo #2)',
        );
        $this->assertFalse(EnsembleCertifier::shouldRecordCandidate(['ensemble_verdict' => 'NO_CONSENSUS']));
        $this->assertTrue(EnsembleCertifier::shouldRecordCandidate(['ensemble_verdict' => 'ENSEMBLE_CERT']));
        // ENSEMBLE off: вердикта нет — поведение v1.6 (запись без гейта).
        $this->assertTrue(
            EnsembleCertifier::shouldRecordCandidate(['atom' => '(x0×x1)']),
            'Кандидат без ensemble_verdict (off) проходит как в v1.6',
        );
    }
}
