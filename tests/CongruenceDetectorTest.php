<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\CongruenceDetector;

/**
 * V0.15 WU-1: CongruenceDetector — предсказательная эквивалентность на домене.
 *
 * Класс C = {формулы, предсказывающие одно и то же на D в пределах eps}.
 * (x+y)^2 vs x^2+2xy+y^2: normalize их НЕ склеивает (разные каноны) —
 * это вход детектора. Пара (x+y) vs (x*y) должна расходиться сразу.
 *
 * Фикстуры детерминированные (RngIsolation::assertClean в tearDown TestCase):
 * ни одного mt_rand/mt_srand.
 */
class CongruenceDetectorTest extends TestCase
{
    /**
     * Детерминированный домен [0,40]^2: иррациональные шаги по обеим осям,
     * покрытие включая нули и углы масштаба. n точек, без ГСЧ.
     *
     * @return list<list<float>>
     */
    private function buildRows(int $n): array
    {
        $rows = [];
        for ($i = 0; $i < $n; ++$i) {
            $rows[] = [fmod($i * 0.41, 40.0), fmod($i * 1.37, 40.0)];
        }

        return $rows;
    }

    public function testCongruentExpansionPairIsIndistinguishable(): void
    {
        $detector = new CongruenceDetector();
        // (x0+x1)^2 vs x0^2 + 2*x0*x1 + x1^2 (2xy = (x0*x1)+(x0*x1), без литералов)
        $r = $detector->test(
            '((x0+x1)×(x0+x1))',
            '(((x0×x0)+((x0×x1)+(x0×x1)))+(x1×x1))',
            $this->buildRows(100000)
        );
        $this->assertFalse($r['inconclusive'], 'обе формулы вычислимы');
        $this->assertTrue($r['congruent'], 'max_diff=' . var_export($r['max_diff'], true));
        $this->assertIsFloat($r['max_diff']);
        $this->assertLessThan(1e-9, $r['max_diff']);
        $this->assertNull($r['split_point']);
    }

    public function testDistinctPairSplitsImmediately(): void
    {
        $detector = new CongruenceDetector();
        $r = $detector->test('(x0+x1)', '(x0×x1)', $this->buildRows(100000));
        $this->assertFalse($r['inconclusive']);
        $this->assertFalse($r['congruent']);
        $this->assertGreaterThan(0.5, $r['max_diff'], 'расхождение > 50%');
        $this->assertNotNull($r['split_point'], 'первая точка расхождения');
        $this->assertSame('index', array_key_first($r['split_point']));
    }

    public function testNullEvaluationIsInconclusiveNotCongruent(): void
    {
        $detector = new CongruenceDetector();
        // '(x0x1)' не парсится → evaluateFormula = null → «не доказана
        // эквивалентность» ≠ «доказана» → congruent=false + inconclusive.
        $r = $detector->test('(x0x1)', '(x0+x1)', [[1.0, 2.0], [3.0, 4.0]]);
        $this->assertTrue($r['inconclusive']);
        $this->assertFalse($r['congruent']);
        $this->assertNull($r['split_point']);
    }
}
