<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Search;
use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\ExpressionEvaluator;

/**
 * EXP-036 фаза 2.5 K3 (kill-test y×10, 29.08): Search НЕ масштабно-инвариантен.
 *
 * Дефект: affine-shift (ЭКСП-012) shift = min(y)−1 — АБСОЛЮТНЫЙ якорь.
 * При rescale цели y→a·y (units-мутация) знаменатель ratio в строке
 * минимума не масштабируется (остаётся ~1.0) → ratio взрывается →
 * валидная форма v≈y/a (масштаб не изменился) получает CV≫cvTrainMax
 * → no_find. Плюс exact-гейт abs(v−y)>1e-4 — абсолютный epsilon,
 * отвергает точный закон 10·f(x) c остатком ≤1e-3.
 *
 * Фикс: scale-equivariant shift: s = min(y) − 0.01·range(y) (однородный,
 * s(a·y) = a·s(y)) + ĉ-коррекция числителя (ĉ = Σvy/Σy² — МНК-масштаб
 * формы к цели; для v=y0 при y=a·y0 ratio ≡ 1/a const → CV=0) +
 * относительный exact-eps abs(v−y) > 1e-4·max(1,|y_i|).
 *
 * Свойство, которое тестируем: SCALE-INVARIANCE поиска —
 * если закон найден на y, он обязан найтись и на a·y (a>0).
 */
class ScaleInvarianceTest extends TestCase
{
    /** Знакопеременный закон с zero-crossing: y = x − 2 (аналог AffineLawsTest). */
    private function makeZeroCrossing(): array
    {
        $X = []; $y = [];
        for ($i = 1; $i <= 20; $i++) {
            $X[] = [(float) $i];
            $y[] = $i - 2.0;
        }
        return [$X, $y];
    }

    public function testRescaledZeroCrossingStillFound(): void
    {
        // K3-зеркало (heat): y = 10·(u−v) — цель в ДРУГОМ масштабе, чем
        // выразимая форма (u−v); y пересекает ноль (affine-shift активен);
        // константы 10 в грамматике нет. Форма (x0−x1) обязана найтись
        // через пропорциональный канал CV (v/y = 0.1 const, shift=0).
        mt_srand(77);
        $X = []; $y = [];
        for ($i = 0; $i < 40; $i++) {
            $u = (mt_rand() / mt_getrandmax()) * 20 - 10;
            $v = (mt_rand() / mt_getrandmax()) * 20 - 10;
            $X[] = [$u, $v];
            $y[] = 10.0 * ($u - $v);
        }
        $g = Grammar::fromOps(Grammar::baseOpNames());

        [$found, $cv, $formula, $cvTest] = Search::find($X, $y, $g, 2, null, 0.2, 0.15);

        $this->assertTrue($found, 'y=10(u−v) must be found (K3 scale-invariance, 29.08: 0/20)');
        $this->assertLessThan(0.10, $cvTest, "cv_test must pass; got {$cvTest} formula={$formula}");
    }

    public function testBaselineUnscaledStillFound(): void
    {
        [$X, $y] = $this->makeZeroCrossing();
        $g = Grammar::fromOps(Grammar::baseOpNames());

        [$found, $cv, $formula, $cvTest] = Search::find($X, $y, $g, 2, null, 0.2, 0.15);

        $this->assertTrue($found, 'regression: unscaled y=x−2 must still be found');
        $this->assertLessThan(0.10, $cvTest);
    }

    public function testCvIsScaleInvariantOnExactForm(): void
    {
        // Свойство критерия: cv(v, a·y) == cv(v, y) для идеальной формы v=y.
        // Данные БЕЗ нулевой строки (y0=0 даёт ratio=0/1e-8=0 — отдельный
        // прокол, чинится ĉ-коррекцией при s≠0; здесь чистый масштаб).
        $X = []; $y = [];
        for ($i = 3; $i <= 22; $i++) {
            $X[] = [(float) $i];
            $y[] = (float) $i;
        }
        $vec = $y;
        $cv1 = Search::cv($vec, $y, 0.0);
        $yScaled = array_map(fn ($v) => $v * 10.0, $y);
        $cv2 = Search::cv($vec, $yScaled, 0.0);
        $this->assertLessThan(0.001, $cv1, 'cv on y');
        $this->assertLessThan(0.001, $cv2, 'cv on 10·y must match cv on y (invariance)');
        $this->assertEqualsWithDelta($cv1, $cv2, 1e-9);
    }

    public function testZeroRowOfExactLawDoesNotPunctureCv(): void
    {
        // Нулевая строка цели: v_i = y_i = 0 — точный закон, но ratio = 0/1e-8
        // даёт прокол (CV 0.229 в K3-диагностике). С ĉ-коррекцией при s≠0:
        // (0−ĉ·s)/(0−s) = ĉ = 1 для точного закона — прокола нет.
        // Полный путь: y = x − 2 содержит ноль при x=2; поиск обязан найти.
        [$X, $y] = $this->makeZeroCrossing();
        $vec = $y; // идеальная форма
        $minY = min($y); $maxY = max($y);
        $range = $maxY - $minY;
        $s = $minY - 0.01 * $range; // scale-equivariant shift (фикс)
        $cv = Search::cv($vec, $y, $s);
        $this->assertLessThan(0.001, $cv, "exact law with zero row must have CV≈0; got {$cv}");
    }

    public function testExactGateIsRelative(): void
    {
        // Относительный eps: 10·f vs 10·y c остатком 1e-3 — ОБЯЗАН пройти
        // (abs diff 1e-3 > старого 1e-4, но < 1e-4·max(1,|y|) при |y|≥10).
        $v = []; $y = [];
        for ($i = 1; $i <= 20; $i++) {
            $v[] = 10.0 * $i + 1e-3 * ($i % 2 ? 1 : -1);
            $y[] = 10.0 * $i;
        }
        $eps = 1e-4;
        $rel = true;
        $abs = true;
        foreach ($v as $i => $x) {
            if (abs($x - $y[$i]) > $eps) $abs = false;
            if (abs($x - $y[$i]) > $eps * max(1.0, abs($y[$i]))) $rel = false;
        }
        $this->assertFalse($abs, 'precondition: abs-eps rejects this exact law');
        $this->assertTrue($rel, 'relative eps must accept the rescaled exact law');
    }
}
