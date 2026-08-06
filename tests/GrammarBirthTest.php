<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\ExpressionEvaluator;
use BeeSwarm\Core\Grammar;

/**
 * GRAMMAR-BIRTH (ЭКСП-015): пчела рождает НОВЫЙ оператор из найденной
 * составной формулы. Минимальная версия: discovery формулы глубиной ≥2
 * (не тавтология, не тривиальная x0/K) → регистрация как атома
 * (source='birth', definition=формула). Evaluator вычисляет атом
 * по definition.
 */
class GrammarBirthTest extends TestCase
{

    protected function tearDown(): void
    {
        // GRAMMAR-BIRTH: не засорять общую :memory: БД — иначе
        // последующие Search-тесты перебирают B-атомы (лавина)
        \BeeSwarm\Infra\Database::get()->exec("DELETE FROM grammar_ops WHERE source = 'birth'");
        parent::tearDown();
    }

    public function testBornAtomEvaluatesByDefinition(): void
    {
        // Рождаем атом: имя = нормализованная формула, definition = формула
        $g = new Grammar();
        $g->add('(x0+x1)', 'birth', '((x0+x1))');

        // Evaluator должен вычислить атом по definition
        $rows = [[1.0, 2.0], [3.0, 4.0], [5.0, 6.0]];
        $vec = ExpressionEvaluator::evaluateFormula('((x0+x1))', $rows);
        $this->assertNotNull($vec, 'expression must evaluate');
        $this->assertEqualsWithDelta(3.0, $vec[0], 0.0001);
        $this->assertEqualsWithDelta(7.0, $vec[1], 0.0001);
        $this->assertEqualsWithDelta(11.0, $vec[2], 0.0001);
    }

    public function testBornAtomNameResolvesToDefinition(): void
    {
        // Атом в формуле: имя '(x0+x1)' как АТОМ — evaluator подставляет definition
        $g = new Grammar();
        $g->add('sum2', 'birth', '((x0+x1))');
        $rows = [[2.0, 3.0]];
        // Атом 'sum2' в составе выражения
        $vec = ExpressionEvaluator::evaluateFormula('(sum2×K2)', $rows);
        $this->assertNotNull($vec, 'born atom must resolve via definition');
        $this->assertEqualsWithDelta(10.0, $vec[0], 0.0001); // (2+3)×2
    }
}
