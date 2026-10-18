<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;
use BeeSwarm\Math\CvCalculator;

/**
 * A19 — Baseline-Relative Certification + scale-aware exact tolerance.
 *
 * Источник: stories/exp-040/BUG-EXP040-EXACT-FLOOR.md + master map row 172
 * (EXP-040 диагноз 1: null-guard wine FPR=0.99 — на низких σ_rel (wine 0.062,
 * airfoil 0.055) КОНСТАНТА проходит абсолютный порог cv≤0.10; диагноз 2:
 * exact-floor bug — eps=1e-4·max(1.0,·) при y~1e-9 даёт допуск в 10⁵ раз
 * больше цели, константа x8 (≡G) = «exact» cv_train=0).
 *
 * WU-A19.1/2: certify только если cv_model существенно меньше cv тривиальной
 * модели mean(y) НА ТОМ ЖЕ множестве, где считался CV кандидата (§1.7-семантика).
 * WU-A19.3: exact-допуск привязан к масштабу данных, абсолютный floor 1e-4 уходит.
 */
final class BaselineCompressionGateTest extends TestCase
{
    /**
     * Low-σ_rel цель wine-класса: σ_rel = 0.03 (y = 100±3), фича x0 —
     * околоконстантная тень (spread 1%), паттерн НЕ совпадает с целью
     * (jitter каждый 3-й ряд против альтернации цели) — mean-level подгонка.
     */
    private static function lowSigmaRelTask(): array
    {
        $X = [];
        $y = [];
        for ($i = 0; $i < 30; $i++) {
            $X[] = [1.0 + (($i % 3 === 0) ? 0.01 : 0.0)];
            $y[] = 100.0 + (($i % 2 === 0) ? 3.0 : -3.0);
        }
        return [$X, $y];
    }

    /**
     * Микро-масштаб цель (гравитация, y ~ 1e-9, σ_rel = 0.05).
     */
    private static function microScaleTarget(int $n): array
    {
        $y = [];
        for ($i = 0; $i < $n; $i++) {
            $y[] = 1.0e-9 * (1.0 + (($i % 2 === 0) ? 0.05 : -0.05));
        }
        return $y;
    }

    /**
     * WU-A19.1: mean-level подгонка на low-σ_rel данных НЕ сертифицируется.
     * Путь testRatio=0 — как в диге EXP-040: engine-гейт решает по train-CV,
     * внешний holdout оценивался отдельно («гейт уже пропустил кандидата
     * как exact, holdout FAIL»). Кандидат cv≈σ_rel=0.03 < 0.10 проходит
     * абсолютный порог → found=true (RED). После A19.2: cv кандидата ≈
     * cv_baseline(mean на том же train-множестве) → компрессии нет →
     * found=false. Holdout-путь дополнительно прикрыт NonConstancyFilter
     * (cyclic-shift null), train-путь — нет (пробой probe_a19_baseline.php:
     * nearconst-tr0 found=true cv=8e-6 — тень, честно трекающая паттерн;
     * decorrelated-jitter вариант = mean-level, он и есть дыра).
     */
    public function testConstantOnLowSigmaRelTargetRefused(): void
    {
        [$X, $y] = self::lowSigmaRelTask();
        $g = Grammar::fromOps(Grammar::baseOpNames());

        [$found, $cv, $formula, $cvTest] = Search::find($X, $y, $g, 1, null, 0.0, 0.10);

        $this->assertFalse(
            $found,
            'mean-level подгонка на low-σ_rel цели не сертифицируется (A19.1: wine FPR 0.99→0); got cv=' . var_export($cv, true) . ' cvTest=' . var_export($cvTest, true) . " formula={$formula}"
        );
    }

    /**
     * WU-A19.3: константа G (6.674e-11) на y~1e-9 НЕ exact.
     * OLD: tol = 1e-4·max(1.0,·) = 1e-4 ABS; |G−y| ≈ 9e-10 < 1e-4 → «exact» 0.0 (баг).
     * NEW: tol = 1e-4·max(|y_i|, floor·maxAbs) ~ 1e-13 → не exact → ratio-CV > 0.
     */
    public function testMicroScaleConstantNotExact(): void
    {
        $y = self::microScaleTarget(12);
        $gColumn = array_fill(0, 12, 6.674e-11);

        $cvSearch = Search::cv($gColumn, $y);
        $this->assertGreaterThan(
            0.0,
            $cvSearch,
            'константа на y~1e-9 не может быть exact (A19.3: floor допуска привязан к масштабу данных)'
        );

        $this->assertGreaterThan(
            0.0,
            CvCalculator::compute($gColumn, $y),
            'L1 unify: CvCalculator обязан вести себя идентично Search::cv на микро-масштабе'
        );
    }

    /**
     * WU-A19.3 regression pin: ИСТИННО точный закон на микро-масштабе
     * остаётся exact 0.0 — фикс не убивает exact-ветку.
     */
    public function testMicroScaleTrueExactLawStaysExact(): void
    {
        $y = self::microScaleTarget(12);
        $exact = [];
        foreach ($y as $yi) {
            $exact[] = $yi * (1.0 + 1.0e-10);
        }

        $this->assertSame(0.0, Search::cv($exact, $y), 'истинно точный закон на y~1e-9 остаётся exact');
        $this->assertSame(0.0, CvCalculator::compute($exact, $y), 'unify: CvCalculator согласован');
    }

    /**
     * Комбинированный пин (A19.2+A19.3): find() не возвращает константу G
     * как found на микро-масштабе. OLD: features-exact-ветка даёт cv_train=0.0
     * мгновенно (repro BUG-EXP040: found=true cv=0 formula=x8 за 11.5s).
     * Атрибуция (CA-3, deleg_0591ab9b): этот тест пинит exact-ветку — после
     * A19.3 константа НЕ объявляется exact (cv != 0.0); отказ found может
     * усиливаться degenerate-гвардом zero-variance фичи (pre-existing, до
     * гейта). Дискриминатор САМОГО гейта — тест mean-level выше (jitter-фича
     * ненулевой дисперсии проходит гвард).
     */
    public function testFindRefusesMicroScaleConstant(): void
    {
        $y = self::microScaleTarget(30);
        $X = [];
        for ($i = 0; $i < 30; $i++) {
            $X[] = [6.674e-11];
        }
        $g = Grammar::fromOps(Grammar::baseOpNames());

        [$found, $cv, $formula] = Search::find($X, $y, $g, 1, null, 0.0, 0.10);

        $this->assertFalse(
            $found,
            'константа x0 на y~1e-9 не сертифицируется (A19.2+A19.3); got cv=' . var_export($cv, true) . " formula={$formula}"
        );
    }

    /**
     * Пин heldout-ветки на знакопеременной цели (CA-2/AR-1 companion):
     * точный закон y=x0−2 при testRatio=0.2 — baseline считает shift из ТОГО
     * ЖЕ источника (affineShiftFor(y_test)), что и testCv → same-metric,
     * точная форма (cv_test==0) проходит автоматически.
     * (GREEN до кода — wiring-пин; содержательный RED WU — 3 теста выше.)
     * AR-2 strict-guard (не-конечный baseline → отказ) — defense-in-depth
     * БЕЗ end-to-end RED: spread знаменателей наследуется ratios ЛЮБОГО
     * кандидата (pred не наблюдает den-паттерн) → абсолютный гейт отказывает
     * раньше; end-to-end фиксстура недостижима (proof в progress.md).
     */
    public function testHeldoutBranchExactLawStillFound(): void
    {
        $X = [];
        $y = [];
        for ($i = 1; $i <= 20; $i++) {
            $X[] = [(float) $i];
            $y[] = $i - 2.0;
        }
        $g = Grammar::fromOps(Grammar::baseOpNames());

        [$found, $cv, $formula, $cvTest] = Search::find($X, $y, $g, 2, null, 0.2, 0.15);

        $this->assertTrue($found, 'точный закон на heldout-ветке остаётся found (baseline same-metric)');
        $this->assertSame(0.0, $cvTest, 'cv_test точного закона = 0.0');
    }
}
