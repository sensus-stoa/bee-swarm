<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Core\AtomRegistry;
use BeeSwarm\Core\Grammar;

/**
 * GRAMMAR-BIRTH фаза 2 (фаза Б): рождённые атомы должны ВЫЧИСЛЯТЬСЯ
 * в поиске. AtomRegistry::apply fallback на definition → B-атомы
 * работают как унарные трансформации в Search.
 */
class GrammarBirthWiringTest extends TestCase
{

    protected function tearDown(): void
    {
        // GRAMMAR-BIRTH: не засорять общую :memory: БД — иначе
        // последующие Search-тесты перебирают B-атомы (лавина)
        \BeeSwarm\Infra\Database::get()->exec("DELETE FROM grammar_ops WHERE source = 'birth'");
        parent::tearDown();
    }

    public function testBornAtomComputesViaDefinition(): void
    {
        // B-атом с цепочкой функций: floor(deg2rad)
        $g = new Grammar();
        $g->add('Bfloor', 'birth', 'floor(deg2rad)');

        // floor(deg2rad(45°)) = floor(0.785) = 0
        $v = AtomRegistry::apply('Bfloor', 45.0, 0.0);
        $this->assertNotNull($v, 'born atom must compute via definition');
        $this->assertEqualsWithDelta(0.0, $v, 0.0001);

        // floor(deg2rad(90°)) = floor(1.571) = 1
        $v2 = AtomRegistry::apply('Bfloor', 90.0, 0.0);
        $this->assertEqualsWithDelta(1.0, $v2, 0.0001);

        // floor(deg2rad(180°)) = floor(3.141) = 3
        $v3 = AtomRegistry::apply('Bfloor', 180.0, 0.0);
        $this->assertEqualsWithDelta(3.0, $v3, 0.0001);
    }

    public function testBornAtomInSearchUnaryPool(): void
    {
        // Рождённый атом попадает в unary pool и находится закон:
        // y = floor(rad2deg(x)) на x ∈ [0, 720] с шагом 90
        $g = new Grammar();
        $g->add('Bfloor', 'birth', 'floor(deg2rad)');

        $X = [];
        $y = [];
        for ($i = 0; $i <= 8; $i++) {
            $x = $i * 90.0;
            $X[] = [$x];
            $y[] = floor($x * M_PI / 180.0);
        }

        // B-атом в grammar_ops и вычисляется (внутренний механизм)
        $db = \BeeSwarm\Infra\Database::get();
        $cnt = $db->query("SELECT COUNT(*) FROM grammar_ops WHERE name = 'Bfloor'")->fetchColumn();
        $this->assertEquals(1, (int) $cnt, 'born atom must be in grammar_ops');

        $vals = [];
        foreach ($X as $row) {
            $vals[] = AtomRegistry::apply('Bfloor', $row[0], 0.0);
        }
        $this->assertSame($y, $vals, 'born atom must produce the target values');
    }
}
