<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Certification\MetricPreflight;
use PHPUnit\Framework\TestCase;

/**
 * V0.12 WU-3: ко-гейты пост-хок на кандидата (§1.10).
 *
 * CCPP-артефакт Demo#2: корректный кандидат по cv-метрике, но:
 *  - SIGN: corr(pred,y) = −0.948 < 0 — «закон» антикоррелирует с целью;
 *  - SCALE: |median(pred)|=65 при |median(y)|=454 — вне 10× коридора.
 *
 * Инвариант co-move с целью: закон обязан и коррелировать по знаку,
 * и попадать в масштаб. Провал → понижение вердикта (совместно с V0.11:
 * UNSTABLE_CERTIFICATE), лог CO_GATE_FAIL: sign|scale.
 */
final class MetricCoGateTest extends TestCase
{
    /** CCPP-дегенерат: pred ≈ −65 константа-антикоррелят (пост-хок Demo#2). */
    private function ccppDegeneratePred(array $y): array
    {
        return array_map(fn (float $v): float => -0.15 * $v - 3.0, $y);
    }

    private function ccppY(int $n = 200): array
    {
        mt_srand(2024);
        $y = [];
        for ($i = 0; $i < $n; $i++) {
            $y[] = 400 + (mt_rand() / mt_getrandmax()) * 100;
        }

        return $y;
    }

    public function testSignCoGateFailsOnAnticorrelated(): void
    {
        $y = $this->ccppY();
        $pred = $this->ccppDegeneratePred($y);
        $out = MetricPreflight::coGates($pred, $y);
        $this->assertFalse($out->sign_ok, 'Антикоррелированный предиктор обязан проваливать SIGN');
        $this->assertLessThan(0.0, $out->corr, 'corr(pred,y) < 0 (Demo#2: −0.948)');
    }

    public function testScaleCoGateFailsOnLevelFreeShape(): void
    {
        $y = $this->ccppY();
        // Форма верна, масштаб −1000×: |median(pred)|=450000 vs |median(y)|=450
        $pred = array_map(fn (float $v): float => $v * 1000, $y);
        $out = MetricPreflight::coGates($pred, $y);
        $this->assertFalse($out->scale_ok, 'Масштаб вне 10× обязан проваливать SCALE');
    }

    public function testHonestLawPassesBothGates(): void
    {
        $y = $this->ccppY();
        // Честный закон: pred = y + малый шум — corr>0, масштаб 1×
        mt_srand(77);
        $pred = array_map(fn (float $v): float => $v + (mt_rand() / mt_getrandmax() - 0.5) * 5, $y);
        $out = MetricPreflight::coGates($pred, $y);
        $this->assertTrue($out->sign_ok);
        $this->assertTrue($out->scale_ok);
    }

    public function testVerdictOfCoGates(): void
    {
        // Совместно с V0.11: провал ко-гейта → UNSTABLE_CERTIFICATE
        $this->assertSame('UNSTABLE_CERTIFICATE', MetricPreflight::verdictOfCoGates(['sign_ok' => false, 'scale_ok' => true]));
        $this->assertSame('UNSTABLE_CERTIFICATE', MetricPreflight::verdictOfCoGates(['sign_ok' => true, 'scale_ok' => false]));
        $this->assertSame('PASS', MetricPreflight::verdictOfCoGates(['sign_ok' => true, 'scale_ok' => true]));
    }

    public function testCcppArtifactIntegral(): void
    {
        // Интегральный: CCPP y, дегенерат pred — SIGN красный, SCALE пасс
        // (масштаб −0.157× — внутри 10× коридора; Demo#2 убил SIGN+R², не масштаб)
        $y = $this->ccppY();
        $pred = $this->ccppDegeneratePred($y);
        $out = MetricPreflight::coGates($pred, $y);
        $this->assertFalse($out->sign_ok, 'corr −0.948: закон обязан co-move');
        $this->assertTrue($out->scale_ok, 'Масштаб 0.157× внутри коридора — Demo#2 фейлил sign+R², не scale');
    }

    public function testScaleFailsOnNearZeroMedian(): void
    {
        // Медиана предиктора ≈0 при |median(y)|≈450: ratio 0.0002 << 0.1 → FAIL
        $y = $this->ccppY();
        $pred = array_map(fn (float $v): float => $v * 0.0001, $y);
        $out = MetricPreflight::coGates($pred, $y);
        $this->assertFalse($out->scale_ok, 'Схлопнутый масштаб обязан отказывать');
    }
}
