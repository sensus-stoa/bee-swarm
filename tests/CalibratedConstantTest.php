<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;
use BeeSwarm\Core\SlotCascade;

/**
 * A4 (pysr-rematch): константы — калибровка c на train-only.
 *
 * K3-легитимность (EXP-036 фаза 2.5, 29.08): c = Σ(v·y)/Σ(v²) на TRAIN —
 * МНК закрытой формой; точен при y=c·v (K3: cal_c=10 с точностью 1e-13);
 * train-only; скаляр не спасает неверную форму (разные функции не
 * совмещаются множителем). Аналог: Ньютон вывел форму F=G·m₁m₂/r²,
 * число G — из измерений.
 *
 * A4 = калибровка В ДВИЖКЕ: kinetic-каркас S=2y проходит гейт по ratio-CV,
 * но exact-check (abs-гейт) отвергает масштабный фактор 2. c=0.5 →
 * cal-форма (S×0.5) = y → exact. Без A4 kinetic = skeleton-only (A3).
 */
class CalibratedConstantTest extends TestCase
{
    /**
     * y = 0.5·m·(vx²+vy²+vz²) — kinetic, mt_srand детерминизм.
     *
     * @return array{0: array<array<float>>, 1: array<float>}
     */
    private function kineticData(int $rows = 40, int $seed = 11): array
    {
        mt_srand($seed);
        $X = [];
        $y = [];
        for ($r = 0; $r < $rows; $r++) {
            $m = (float) mt_rand(10, 80) / 10.0;
            $vx = (float) mt_rand(-90, 90) / 10.0;
            $vy = (float) mt_rand(-90, 90) / 10.0;
            $vz = (float) mt_rand(-90, 90) / 10.0;
            $X[] = [$m, $vx, $vy, $vz];
            $y[] = 0.5 * $m * ($vx * $vx + $vy * $vy + $vz * $vz);
        }

        return [$X, $y];
    }

    private function grammar(): Grammar
    {
        $g = new Grammar();
        $g->restrictTo(['+', '×', '−', '/', 'sq']);

        return $g;
    }

    public function testKineticExactWithCalibratedConstant(): void
    {
        // Критерий A4: kinetic решается ДО ТОЧНОСТИ (exact через cal-форму),
        // формула несёт калиброванный коэффициент.
        [$X, $y] = $this->kineticData();

        [$found, $cv, $formula] = Search::find($X, $y, $this->grammar(), 3, null, 0.0, 0.15, 60.0);

        $this->assertTrue($found, 'kinetic must be found');
        // cal-форма = y → exact-гейт → cv ровно 0.0 (не ratio-приближение)
        $this->assertSame(0.0, $cv, 'calibrated form must be exact');
        $formula = (string) $formula;
        // Каркас + калиброванный коэффициент 0.5 в имени формы
        $this->assertStringContainsString('(x0×x1²)', $formula, "got: {$formula}");
        $this->assertStringContainsString('(x0×x2²)', $formula, "got: {$formula}");
        $this->assertStringContainsString('(x0×x3²)', $formula, "got: {$formula}");
        $this->assertStringContainsString('0.5', $formula, 'calibrated constant in formula, got: ' . $formula);
    }

    public function testAssembleReturnsCalibratedScalar(): void
    {
        // K3-инвариант на уровне assemble: y=10·S → cal_c=10 (точность 1e-9),
        // y=S → cal_c=1.0 (identity не портит имя).
        $n = 20;
        mt_srand(3);
        $s1 = [];
        $s2 = [];
        $s3 = [];
        for ($i = 0; $i < $n; $i++) {
            $s1[] = (float) mt_rand(1, 99) / 10.0;
            $s2[] = (float) mt_rand(1, 99) / 10.0;
            $s3[] = (float) mt_rand(1, 99) / 10.0;
        }
        $sum = [];
        for ($i = 0; $i < $n; $i++) {
            $sum[] = $s1[$i] + $s2[$i] + $s3[$i];
        }
        $y10 = array_map(fn (float $v): float => $v * 10.0, $sum);
        $slots = [
            '(x0×x3)' => $s1,
            '(x1×x4)' => $s2,
            '(x2×x5)' => $s3,
        ];
        $cv = fn (array $vec, array $target, float $shift): float => 0.0; // всё passer

        $res = SlotCascade::assemble($slots, $y10, $cv, 0.15);
        $this->assertNotNull($res);
        // cal-вектор = cal_c·S == y10 точно (МНК замкнутой формой)
        $this->assertEqualsWithDelta(10.0, $res[1][0] / $sum[0], 1e-9, 'cal_c=10 for y=10·S');
    }
}
