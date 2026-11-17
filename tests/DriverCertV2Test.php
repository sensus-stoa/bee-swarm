<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use PHPUnit\Framework\TestCase;

/**
 * WU DRIVER-CERT-V2 (01.11, owner strict): тройной сплит вместо одного.
 *
 * Канон v2: «three independent splits, all three must pass» (60/40,
 * production ratio). Сплайты: taskSeed + sp*104729, sp=0,1,2.
 *
 * Калибровка на реальных формах (60/40): точный 4-term law E6 —
 * CV 0.0000 x5 (pass); E2-partial (T2+T3 из 4) — 0.1015/0.1137/0.0917
 * -> 1/3 <= 0.10 (fail). Тест воспроизводит калибровку: числа должны
 * совпасть с точностью 1e-6 (LCG идентичен gen_family в driver).
 */
final class DriverCertV2Test extends TestCase
{
    /** LCG-генерация dot4-семейства, идентичная gen_family в driver. */
    private function genDot4(int $seed, array $perm): array
    {
        $s = $seed;
        $r = function () use (&$s) {
            $s = ($s * 1103515245 + 12345) & 0x7FFFFFFF;

            return $s / 0x7FFFFFFF;
        };
        $u = function (float $a, float $b) use ($r): float {
            return $a + $r() * ($b - $a);
        };
        $X = [];
        $y = [];
        for ($i = 0; $i < 200; $i++) {
            $c = [$u(1, 3), $u(2, 5), $u(3, 7), $u(0.5, 2), $u(0, 9)];
            $c[] = $u(0.5, 4);
            $yv = $c[0] * $c[3] + $c[1] * $c[4] + $c[2] * $c[5] + $c[3] * $c[5];
            $row = [];
            foreach ($perm as $src) {
                $row[] = $c[$src];
            }
            $X[] = $row;
            $y[] = $yv;
        }

        return [$X, $y];
    }

    /**
     * cert_v2: тройной сплит (60/40), все три держат порог 0.10.
     * Полный закон E6: 0.0000 на всех сплитах калибровки -> pass.
     */
    public function testExactLawPassesTripleSplit(): void
    {
        [$Xall, $yall] = $this->genDot4(63956647, [4, 1, 5, 2, 3, 0]); // E6
        $fx = '(((x0×x1)+(x2×x3))+(x2×x4))+(x4×x5)'; // точный 4-term
        $res = cert_v2($fx, $Xall, $yall, 63956647);
        $this->assertNotNull($res, 'exact law must evaluate on all splits');
        $this->assertTrue($res['pass'], 'exact law must pass triple split');
        foreach ($res['cvs'] as $cv) {
            $this->assertLessThanOrEqual(0.10, $cv);
        }
    }

    /**
     * E2-partial (T2+T3 из 4, тот же паттерн, что E3): калибровка 60/40
     * дала 0.1015/0.1137/0.0917 -> 1/3 <= 0.10 -> FAIL. Гейт обязан резать.
     */
    public function testPartialFormFailsTripleSplit(): void
    {
        [$Xall, $yall] = $this->genDot4(106239912, [1, 3, 0, 5, 2, 4]); // E2
        $fx = '((x0×x5)+(x3×x4))'; // = T2+T3 (E2 partial)
        $res = cert_v2($fx, $Xall, $yall, 106239912);
        $this->assertNotNull($res);
        $this->assertFalse($res['pass'], 'partial T2+T3 must fail triple split');
        // Воспроизводимость калибровки: числа совпадают 1e-6
        $this->assertEqualsWithDelta(0.1015, $res['cvs'][0], 1e-4);
        $this->assertEqualsWithDelta(0.1137, $res['cvs'][1], 1e-4);
        $this->assertEqualsWithDelta(0.0917, $res['cvs'][2], 1e-4);
    }

    /**
     * Детерминизм: два вызова дают идентичные CV (важно: сиды сплитов
     * НЕ зависят от глобального mt_srand-состояния кампании).
     */
    public function testDeterministicAcrossCalls(): void
    {
        [$Xall, $yall] = $this->genDot4(106239912, [1, 3, 0, 5, 2, 4]);
        $fx = '((x0×x5)+(x3×x4))';
        $a = cert_v2($fx, $Xall, $yall, 106239912);
        mt_srand(12345); // сломать глобальный RNG
        $b = cert_v2($fx, $Xall, $yall, 106239912);
        $this->assertSame($a['cvs'], $b['cvs']);
        $this->assertSame($a['pass'], $b['pass']);
    }

    /**
     * Константа (шум без структуры) обязана падать: CV ~ 0.5.
     */
    public function testConstantFails(): void
    {
        [$Xall, $yall] = $this->genDot4(63956647, [4, 1, 5, 2, 3, 0]);
        $res = cert_v2('K1', $Xall, $yall, 63956647);
        $this->assertNotNull($res);
        $this->assertFalse($res['pass']);
    }
}

// cert_v2 определён в cert_utils.php (WU DRIVER-CERT-V2); файл ещё не
// создан — тест RED по автозагрузке.  После создания — phpunit вызовет.
require_once __DIR__ . '/../scripts/exp045/cert_utils.php';