<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\TrailAdmission;
use PHPUnit\Framework\TestCase;

/**
 * R4-WU-2 (EXP-045 Road v4, спека WU_ROAD_V4_SPEC.md §2/§4): классификация
 * кандидатов admission — чистый unit (без БД, без find()).
 *
 * EVAL 6 классов: LABEL_UNRESOLVABLE (аларм label-space бага), PARSE_FAIL,
 * EVAL_NULL (домен: неизвестный атом, деление в ноль, sqrt отрицательного),
 * NON_FINITE (Inf/NaN в векторе — переполнение), CONSTANT (stddev<1e-6),
 * DEPTH_EXCEEDED (COUNT '+' узлов AST > 3).
 *
 * class-of: stair (+ и ×), petal (× без +), unary_binary (унарный корень
 * над бинарной структурой), other.
 *
 * Граница EVAL_NULL/NON_FINITE: evaluateFormula возвращает null wholesale —
 * классификатор идёт per-row через evalNode: null-элемент = EVAL_NULL,
 * Inf/NaN = NON_FINITE.
 */
class TrailAdmissionTest extends TestCase
{
    /**
     * @return array{0: list<list<float>>, 1: list<float>}
     */
    private static function dotTask(int $nRows = 20, int $nFeat = 6): array
    {
        mt_srand(7);
        $X = [];
        $y = [];
        for ($i = 0; $i < $nRows; $i++) {
            $row = [];
            for ($j = 0; $j < $nFeat; $j++) {
                $row[] = 0.5 + mt_rand() / mt_getrandmax() * 1.5; // [0.5, 2.0]
            }
            $X[] = $row;
            $y[] = $row[0] * $row[3] + $row[1] * $row[4] + $row[2] * $row[5];
        }

        return [$X, $y];
    }

    /**
     * @return array<string,string>
     */
    private static function dotLabels(int $nFeat = 6): array
    {
        $labels = [];
        for ($j = 0; $j < $nFeat; $j++) {
            $labels[$j] = 'c' . $j;
        }

        return $labels;
    }

    public function testLabelsToXLongestFirst(): void
    {
        $map = TrailAdmission::labelsToX([
            0 => 'cc0',
            1 => 'c0',
            2 => 'c1',
        ]);
        $keys = array_keys($map);
        $this->assertSame('cc0', $keys[0], 'longest label must be replaced first (prefix collision)');
        $this->assertSame('x0', $map['cc0']);
        $this->assertSame('x1', $map['c0']);
    }

    public function testLabelsToXNullGivesEmptyMap(): void
    {
        $this->assertSame([], TrailAdmission::labelsToX(null));
    }

    public function testClassOfStairPetalUnaryOther(): void
    {
        $this->assertSame(TrailAdmission::CLASS_STAIR, TrailAdmission::classOf('((x0×x1)+(x2×x3))'));
        $this->assertSame(TrailAdmission::CLASS_PETAL, TrailAdmission::classOf('(x0×x1)'));
        $this->assertSame(TrailAdmission::CLASS_PETAL, TrailAdmission::classOf('(x0×x1²)'));
        $this->assertSame(TrailAdmission::CLASS_UNARY_BINARY, TrailAdmission::classOf('((x0×x1)sq)'));
        $this->assertSame(TrailAdmission::CLASS_OTHER, TrailAdmission::classOf('(x0+x1)'));
        $this->assertSame(TrailAdmission::CLASS_OTHER, TrailAdmission::classOf('x0'));
        // унарный корень БЕЗ бинарной структуры — не unary_binary
        $this->assertSame(TrailAdmission::CLASS_OTHER, TrailAdmission::classOf('(x0sq)'));
    }

    public function testCountPlusNodes(): void
    {
        $this->assertSame(0, TrailAdmission::countPlusNodes('(x0×x1)'));
        $this->assertSame(1, TrailAdmission::countPlusNodes('((x0×x1)+(x2×x3))'));
        $this->assertSame(2, TrailAdmission::countPlusNodes('(((x0×x1)+(x2×x3))+(x4×x5))'));
    }

    public function testClassifyOkProxyPerfect(): void
    {
        // y = x0*x3 (ОДНО произведение — иначе proxy меряет долю в полном dot)
        [$X] = self::dotTask();
        $y = [];
        foreach ($X as $row) {
            $y[] = $row[0] * $row[3];
        }
        $r = TrailAdmission::classify('(c0×c3)', TrailAdmission::labelsToX(self::dotLabels()), $X, $y);
        $this->assertSame(TrailAdmission::EVAL_OK, $r['eval']);
        $this->assertEqualsWithDelta(1.0, $r['proxy'], 0.001, 'y = x0*x3 → proxy of (x0×x3) is 1');
        $this->assertSame(TrailAdmission::CLASS_PETAL, TrailAdmission::classOf($r['xform']));
    }

    public function testClassifySquaredLabelConvertsThroughSuffix(): void
    {
        [$X, $y] = self::dotTask();
        $r = TrailAdmission::classify('(c0×c1²)', TrailAdmission::labelsToX(self::dotLabels()), $X, $y);
        $this->assertSame(TrailAdmission::EVAL_OK, $r['eval'], 'c1² → x1² via substring replace; ² parses as sq');
        $this->assertSame('(x0×x1²)', $r['xform']);
        $this->assertGreaterThan(0.0, $r['proxy']);
    }

    public function testClassifyLabelUnresolvableIsAlarm(): void
    {
        [$X, $y] = self::dotTask();
        // c9 нет в labels → токен остаётся в x-пространстве
        $r = TrailAdmission::classify('(c0×c9)', TrailAdmission::labelsToX(self::dotLabels()), $X, $y);
        $this->assertSame(TrailAdmission::EVAL_LABEL_UNRESOLVABLE, $r['eval']);
        $this->assertSame(0.0, $r['proxy']);
    }

    public function testClassifyParseFail(): void
    {
        [$X, $y] = self::dotTask();
        $r = TrailAdmission::classify('', TrailAdmission::labelsToX(self::dotLabels()), $X, $y);
        $this->assertSame(TrailAdmission::EVAL_PARSE_FAIL, $r['eval']);
    }

    public function testClassifyEvalNullDomain(): void
    {
        [$X, $y] = self::dotTask();
        // неизвестный атом = НЕРАЗРЕШИМЫЙ атом (буквально спека §4) → аларм
        $r = TrailAdmission::classify('(zz9×x0)', [], $X, $y);
        $this->assertSame(TrailAdmission::EVAL_LABEL_UNRESOLVABLE, $r['eval']);
        // деление на ноль — доменная ошибка (оценщик гвардит $r != 0 → null)
        $Xdiv = [];
        for ($i = 0; $i < 10; $i++) {
            $Xdiv[] = [1.0 + $i, 0.0];
        }
        $r2 = TrailAdmission::classify('(x0/x1)', [], $Xdiv, array_fill(0, 10, 1.0));
        $this->assertSame(TrailAdmission::EVAL_NULL, $r2['eval']);
    }

    public function testClassifyNonFinite(): void
    {
        // переполнение: 1e308 × 10 = INF в векторе (не null!)
        $X = [];
        $y = [];
        for ($i = 0; $i < 8; $i++) {
            $X[] = [1e308, 10.0];
            $y[] = 1.0 + $i;
        }
        $r = TrailAdmission::classify('(x0×x1)', [], $X, $y);
        $this->assertSame(TrailAdmission::EVAL_NON_FINITE, $r['eval']);
    }

    public function testClassifyConstant(): void
    {
        [$X, $y] = self::dotTask();
        $r = TrailAdmission::classify('(x0−x0)', [], $X, $y);
        $this->assertSame(TrailAdmission::EVAL_CONSTANT, $r['eval']);
        $this->assertSame(0.0, $r['proxy']);
    }

    public function testClassifyDepthGateOnFiveTermStair(): void
    {
        [$X, $y] = self::dotTask();
        // 4 члена = 3 '+' — граница, проходит (глубина 4 собирается цепочкой)
        $four = '(((x0×x1)+(x2×x3))+((x4×x5)+(x0×x1)))';
        $r = TrailAdmission::classify($four, [], $X, $y);
        $this->assertSame(TrailAdmission::EVAL_OK, $r['eval']);
        $this->assertSame(3, TrailAdmission::countPlusNodes($four));
        // 5 членов = 4 '+' — DEPTH_EXCEEDED
        $five = '((((x0×x1)+(x2×x3))+((x4×x5)+(x0×x1)))+(x2×x3))';
        $r5 = TrailAdmission::classify($five, [], $X, $y);
        $this->assertSame(TrailAdmission::EVAL_DEPTH_EXCEEDED, $r5['eval']);
    }

    public function testClassifyProxyZeroOnOrthogonal(): void
    {
        [$X, $y] = self::dotTask();
        // (x0+x1) против y=x0*x3+... —proxy не обязан быть 0, но обязан
        // быть в [0,1] и конечным
        $r = TrailAdmission::classify('(x0+x1)', [], $X, $y);
        $this->assertSame(TrailAdmission::EVAL_OK, $r['eval']);
        $this->assertGreaterThanOrEqual(0.0, $r['proxy']);
        $this->assertLessThanOrEqual(1.0, $r['proxy']);
    }

    public function testClassifyWithVecMatchesClassify(): void
    {
        // быстрый путь admission: вектор уже посчитан поиском ($exprs) —
        // семантика классов идентична classify
        [$X, $y] = self::dotTask();
        $vec = [];
        foreach ($X as $row) {
            $vec[] = $row[0] * $row[1];
        }
        $fast = TrailAdmission::classifyWithVec('(x0×x1)', [], $vec, $y);
        $slow = TrailAdmission::classify('(x0×x1)', [], $X, $y);
        $this->assertSame($slow['eval'], $fast['eval']);
        $this->assertEqualsWithDelta($slow['proxy'], $fast['proxy'], 1e-12);
        $this->assertSame($slow['xform'], $fast['xform']);
        // null в векторе = доменная смерть и на быстром пути
        $vecNull = $vec;
        $vecNull[3] = null;
        $rNull = TrailAdmission::classifyWithVec('(x0×x1)', [], $vecNull, $y);
        $this->assertSame(TrailAdmission::EVAL_NULL, $rNull['eval']);
    }

    public function testMarkInLawTokenBoundaries(): void
    {
        // РЕВЬЮ deleg_231f9459: голый str_contains подтверждал метку '(x1×x3)'
        // законом, содержащим x12 (x1 — префикс x12). Границы токена обязательны.
        $this->assertTrue(TrailAdmission::markInLaw('((x12×x3)+(x1×x4))', '(x1×x4)'), 'real participation');
        $this->assertFalse(TrailAdmission::markInLaw('((x12×x3))', '(x1×x3)'), 'x1 must not match inside x12');
        $this->assertFalse(TrailAdmission::markInLaw('((x1×x30))', '(x1×x3)'), 'x3 must not match prefix of x30');
        $this->assertTrue(TrailAdmission::markInLaw('(K2+((x0×x1)))', '(x0×x1)'), 'composition embedding is participation');
        $this->assertFalse(TrailAdmission::markInLaw('', '(x0×x1)'), 'empty law confirms nothing');
        $this->assertFalse(TrailAdmission::markInLaw('((x0×x1))', ''), 'empty mark confirms nothing');
    }
}
