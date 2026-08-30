<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;
use BeeSwarm\Core\SlotCascade;

/**
 * A3 (pysr-rematch): SUM-каркасы обобщённые — kinetic тем же механизмом.
 *
 * kinetic: y = 0.5·m·(vx²+vy²+vz²) → каркас S = ((x0×x1²)+(x0×x2²))+(x0×x3²),
 * S = 2y → ratio-CV (масштабно-инвариантный, kill-test 2.5) = 0.
 * Точная константа 0.5 — зона A4 (калибровка c train-only); здесь —
 * каркас в классе пропорциональности, что и даёт поиск с слотами (x0×v²).
 *
 * A3-правки против A2: mulKeys включает (xa×xb²) слоты; assemble
 * best-by-cv (premortem H1: first-pass-wins блокировал точную тройку —
 * воспроизведено на dot после расширения слотов).
 */
class KineticSlotsTest extends TestCase
{
    /**
     * y = 0.5·m·(vx²+vy²+vz²) — 4 фичи, mt_srand детерминизм.
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

    public function testKineticSolvedBySlotCascade(): void
    {
        // Критерий A3: kinetic-каркас собирается слотами (класс
        // пропорциональности, cv→0; константа — A4).
        [$X, $y] = $this->kineticData();

        [$found, $cv, $formula] = Search::find($X, $y, $this->grammar(), 3, null, 0.0, 0.15, 60.0);

        $this->assertTrue($found, 'kinetic must be found: got formula=' . (string) $formula);
        $this->assertLessThan(1e-9, $cv, 'kinetic scaffold must be exact in ratio-CV');
        $formula = (string) $formula;
        // Три ×-слота из каскада (m × квадраты скоростей)
        $this->assertStringContainsString('(x0×x1²)', $formula, "got: {$formula}");
        $this->assertStringContainsString('(x0×x2²)', $formula, "got: {$formula}");
        $this->assertStringContainsString('(x0×x3²)', $formula, "got: {$formula}");
    }

    public function testAssemblePicksBestByCvNotFirst(): void
    {
        // Premortem H1-гвард: две passer-тройки — cv=0.1 и cv=0.0;
        // best-by-cv обязан вернуть вторую (точную), не первую.
        $n = 4;
        $y = [1.0, 2.0, 3.0, 4.0];
        // Тройка A: y + шум 0.1·|y| (cv=0.1 при cv-колбэке-эмуляторе)
        $noise = array_map(fn (float $v): float => $v * 1.1, $y);
        // Тройка B: точная
        $exact = $y;
        $zero = array_fill(0, $n, 0.0);
        $exprs = [
            '(x0×x3)' => $noise,
            '(x1×x4)' => $zero,
            '(x2×x5)' => $zero,
            '(x0×x1)' => $exact,
            '(x1×x2)' => $zero,
            '(x2×x3)' => $zero,
        ];
        // cv-эмуляция: точная сумма (vec == y) → 0.0, любая другая → 0.1.
        // (шумовая тройка, смешанная — всё не-точное получает 0.1)
        $cv = fn (array $vec, array $target, float $shift): float => ($vec === $y) ? 0.0 : 0.1;
        $res = SlotCascade::assemble($exprs, $y, $cv, 0.15);

        $this->assertNotNull($res, 'passer must exist');
        // Точная тройка состоит из (x0×x1),(x1×x2),(x2×x3): вектор = y
        $this->assertSame([1.0, 2.0, 3.0, 4.0], $res[1], 'exact vector must win');
        $this->assertStringContainsString('(x0×x1)', $res[0], 'best-by-cv, not first');
    }
}
