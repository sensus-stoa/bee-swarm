<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\LawClassifier;

/**
 * DREAM-HARDENING WU-2 (prereg 29.09): law_class = hash(канонической
 * формулы), data уходит из сигнатуры.
 *
 * Класс риска (premortem H3): LawClassifier::hash квантует pred/(|y|·1e-4) —
 * data-зависим. Одна формула на двух срезах одного домена → разные
 * law_class → фрагментация классов, вторая линия оплаты деградирует
 * до «всегда новый класс».
 *
 * RED (двух-срезовый): одна формула (мост sq(+) → '((x0+x1))²') на двух
 * срезах одного домена (fmod, разный seed/фаза) → ОДИНАКОВЫЙ law_class.
 * До реализации — разные (пробой фиксируется как RED-факт).
 *
 * Open question prereg №1 решён живым кодом: вызов Hive.php уже скоуплен
 * `WHERE domain = ? AND law_class = ?` → домен в SQL-ключе, хэш formula-only.
 * Инконгруэнтные формы остаются разными классами (синтаксическая линия;
 * predictive-equivalence остаётся у CongruenceDetector).
 */
class LawClassifierFormulaHashTest extends TestCase
{
    /**
     * Срез домена: fmod-траектории с разными множителями (seed/фаза).
     * y — детерминированная функция среза (разная между срезами).
     *
     * @return array{X: list<array{0: float, 1: float}>, y: list<float>}
     */
    private function slice(float $m0, float $m1, int $n): array
    {
        $X = [];
        $y = [];
        for ($i = 0; $i < $n; ++$i) {
            $x0 = fmod($i * $m0, 40.0);
            $x1 = fmod($i * $m1, 40.0);
            $X[] = [$x0, $x1];
            $y[] = ($x0 + $x1) + fmod($i * 0.07, 3.0);
        }

        return ['X' => $X, 'y' => $y];
    }

    public function testSameFormulaTwoSlicesSameLawClass(): void
    {
        $formula = '((x0+x1))²'; // канон моста sq(+), WU-3b

        $a = LawClassifier::hash($formula, $this->slice(0.41, 1.37, 40)['X'], $this->slice(0.41, 1.37, 40)['y']);
        $b = LawClassifier::hash($formula, $this->slice(0.53, 1.19, 40)['X'], $this->slice(0.53, 1.19, 40)['y']);

        $this->assertNotSame('', $a, 'hash не должен возвращать пустую сигнатуру на живой формуле');
        $this->assertSame($a, $b, 'Одна формула на двух срезах одного домена = один law_class');
    }

    public function testIncongruentFormsStayDifferentClasses(): void
    {
        $s = $this->slice(0.41, 1.37, 40);
        $sq = LawClassifier::hash('((x0+x1))²', $s['X'], $s['y']);
        $abs = LawClassifier::hash('((x0+x1))abs', $s['X'], $s['y']);
        $this->assertNotSame($sq, $abs, 'Инконгруэнтные формы = разные классы (синтаксическая линия)');
    }

    public function testSameFormulaSameDataStable(): void
    {
        $s = $this->slice(0.41, 1.37, 40);
        $this->assertSame(
            LawClassifier::hash('((x0+x1))²', $s['X'], $s['y']),
            LawClassifier::hash('((x0+x1))²', $s['X'], $s['y'])
        );
    }
}
