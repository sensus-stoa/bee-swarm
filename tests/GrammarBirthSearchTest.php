<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\Search;
use BeeSwarm\Core\Grammar;

/**
 * GRAMMAR-BIRTH фаза 2 (RED-2): B-атомы в unary pool —
 * Search::find находит закон через рождённый атом.
 * y = floor(deg2rad(x)): атом Bfloor (definition) должен дать CV<0.10
 * без дерева floor(deg2rad(x0)).
 */
class GrammarBirthSearchTest extends TestCase
{

    protected function tearDown(): void
    {
        // GRAMMAR-BIRTH: не засорять общую :memory: БД — иначе
        // последующие Search-тесты перебирают B-атомы (лавина)
        \BeeSwarm\Infra\Database::get()->exec("DELETE FROM grammar_ops WHERE source = 'birth'");
        parent::tearDown();
    }

    public function testBornAtomInUnaryPoolFindsLaw(): void
    {
        // Рождаем атом: Bfloor => floor(deg2rad)
        $g = new Grammar();
        $g->add('Bfloor', 'birth', 'floor(deg2rad)');

        // Данные: y = floor(deg2rad(x)), x ∈ [0, 720], шаг 90
        $X = [];
        $y = [];
        for ($i = 0; $i <= 8; $i++) {
            $x = $i * 90.0;
            $X[] = [$x];
            $y[] = floor($x * M_PI / 180.0);
        }

        [$found, $cv, $formula, $cvTest] = Search::find($X, $y, $g, 2, null, 0.2, 0.15);

        $this->assertTrue($found, 'law must be found via born atom');
        $this->assertLessThan(0.10, $cvTest, "cv_test must pass; got {$cvTest}");
        $this->assertStringContainsString('Bfloor', $formula,
            "formula must use the born atom; got: {$formula}");
    }
}
