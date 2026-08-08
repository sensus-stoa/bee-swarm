<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\LawClassifier;

/**
 * LAW-CLASS (ЭКСП-022c, юнит 2): pred-векторный класс закона.
 * Численно эквивалентные формулы на данных — ОДИН класс →
 * награда только за первый представитель (дефицит знания).
 */
class LawClassifierTest extends TestCase
{
    private array $X;

    private array $y;

    protected function setUp(): void
    {
        parent::setUp();
        // y = x0 + x1
        $this->X = [];
        $this->y = [];
        for ($i = 1; $i <= 20; $i++) {
            $x0 = (float) $i;
            $x1 = (float) ($i * 3);
            $this->X[] = [$x0, $x1];
            $this->y[] = $x0 + $x1;
        }
    }

    public function testNumericallyEquivalentFormulasShareClass(): void
    {
        // (x0+x1) и ((x0+x1)×K1) численно эквивалентны на данных
        // (K1=1.0); ×K1 на верхнем уровне парсится корректно
        $a = LawClassifier::hash('(x0+x1)', $this->X, $this->y);
        $b = LawClassifier::hash('((x0+x1)×K1)', $this->X, $this->y);

        $this->assertSame($a, $b, 'numerically equivalent formulas must share class');
    }

    public function testDifferentLawsHaveDifferentClasses(): void
    {
        // y = x0 + x1: (x0+x1) vs (x0×x1) — разные классы
        $a = LawClassifier::hash('(x0+x1)', $this->X, $this->y);
        $b = LawClassifier::hash('(x0×x1)', $this->X, $this->y);

        $this->assertNotSame($a, $b, 'different laws must have different classes');
    }

    public function testNoiseTolerantSameClass(): void
    {
        // Малый числовой шум (1e-9) не меняет класс
        $a = LawClassifier::hash('(x0+x1)', $this->X, $this->y);
        $yNoisy = array_map(fn (float $v): float => $v + 1e-9, $this->y);
        $b = LawClassifier::hash('(x0+x1)', $this->X, $yNoisy);

        $this->assertSame($a, $b, 'tiny noise must not change class');
    }
}
