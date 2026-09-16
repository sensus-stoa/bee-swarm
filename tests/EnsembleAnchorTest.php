<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Certification\EnsembleCertifier;
use PHPUnit\Framework\TestCase;

/**
 * V0.11 WU-3: ensemble anchor — масштаб аффинных таргетов БЕЗ хардкода
 * констант в формуле (§1.9 п.6).
 *
 * Семантика m̂: median(y/pred) по held-out хвосту консенсус-представителя.
 * Ожидание хендоффа «m̂ ∈ [5, 5.05]» для y = 5×(x0×x1)+100 пинит семантику
 * y/pred (shorthand «pred/y» в хендоффе неоднозначен: pred/y даёт 0.2).
 * CI = квартили ratio-распределения по строкам хвоста.
 *
 * Данные: x ∈ U(0,150) — 100/t для медианы t ≈ 4185 даёт m̂ = 5.024,
 * CV формы (x0×x1) ≈ 0.001 проходит θ-гейты. null_ensembles=0 (anchor-тест
 * не про гейт (c) — экономим ~2 мин прогона).
 */
final class EnsembleAnchorTest extends TestCase
{
    private string $logFile = '';

    protected function setUp(): void
    {
        putenv('SWARM_DB_PATH=:memory:');
        putenv('FORAGER_SOURCES=:');
        putenv('NO_BIRTH=1');
        putenv('SEARCH_NO_PREREG=1');
        putenv('SEARCH_BEAM_K=0');
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'ens_anchor_');
    }

    protected function tearDown(): void
    {
        foreach (['NO_BIRTH', 'SEARCH_NO_PREREG', 'SEARCH_BEAM_K'] as $k) {
            putenv($k);
        }
        if ($this->logFile !== '' && is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    /**
     * Фикстура домена (дизайн-уроки WU-3 в комментарии ниже).
     */
    private function anchorDomain(): array
    {
        mt_srand(4242);
        $X = [];
        $y = [];
        // U(80,150): (а) E[1/x] равномерного от нуля расходится — ratio
        // 100/t получает тяжёлый хвост, cv (x0×x1) превышает θ (поймано
        // прогоном); (б) на U(30,150) min-форма (x0×x1)+(x0minx1) аппрок-
        // симирует цель ЛУЧШЕ чистой (min≈135 стягивает член) — все члены
        // консолидировались на ней, m̂ съезжал до 4.97 (диаг-проба 16.09).
        // U(80,150): чистая форма cv 0.0004 < min-формы → консенсус чист.
        for ($i = 0; $i < 200; $i++) {
            $x0 = 80 + mt_rand() / mt_getrandmax() * 70;
            $x1 = 80 + mt_rand() / mt_getrandmax() * 70;
            $X[] = [$x0, $x1];
            $y[] = 5 * $x0 * $x1 + 100;
        }

        return [$X, $y];
    }

    public function testCertifyPublishesEnsembleAnchor(): void
    {
        [$X, $y] = $this->anchorDomain();
        $out = EnsembleCertifier::certify(
            $X,
            $y,
            [
                'k' => 5,
                'depth' => 2,
                'test_ratio' => 0.2,
                'budget_sec' => 15.0,
                'gate_grid' => [0.05],
                'null_ensembles' => 0,
                'log_file' => $this->logFile,
            ],
        );

        $this->assertSame('ENSEMBLE_CERT', $out['verdict'], json_encode($out['shape'] ?? null));
        $this->assertArrayHasKey('ensemble_anchor', $out);
        $anchor = $out['ensemble_anchor'];
        $this->assertNotNull($anchor);
        $this->assertGreaterThanOrEqual(5.0, $anchor['m_hat']);
        $this->assertLessThanOrEqual(5.05, $anchor['m_hat']);
        $this->assertGreaterThanOrEqual($anchor['m_hat'] - 1e-9, $anchor['ci_hi'], 'm̂ внутри квартильного CI');
        $this->assertLessThanOrEqual($anchor['m_hat'] + 1e-9, $anchor['ci_lo']);
        // Движок остаётся parameter-free: формулы без грамматик-констант.
        $this->assertSame(
            0,
            preg_match('/K\d+/', (string) $out['members'][0]['formula']),
            'Консенсус-форма не должна нести масштаб: ' . $out['members'][0]['formula'],
        );
    }
}
