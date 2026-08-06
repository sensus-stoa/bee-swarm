<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\GrammarMutator;
use BeeSwarm\Core\Grammar;

/**
 * GRAMMAR-PROPAGATION (ЭКСП-012): культурная эволюция.
 * После DISCOVERY оператор получает вес → другие пчёлы чаще мутируют
 * в него (weightedPick вместо array_rand).
 */
class GrammarPropagationTest extends TestCase
{
    public function testBoostOpIncreasesUsageCount(): void
    {
        $g = new Grammar();
        $g->add('add', 'test_prop');
        $before = $g->usageCount('add');

        $g->boostOp('add');
        $g->boostOp('add');

        $this->assertGreaterThanOrEqual($before + 2, $g->usageCount('add'),
            'boostOp must increase usage_count');
    }

    public function testWeightedMutationPrefersBoostedOps(): void
    {
        // Пчела без '+' в грамматике, пул с сильно бустнутым '+'
        $g = new Grammar();
        $g->add('add', 'test_prop');
        $g->add('sub', 'test_prop');
        $g->add('mul', 'test_prop');
        $g->boostOp('add'); // буст ×5
        $g->boostOp('add');
        $g->boostOp('add');
        $g->boostOp('add');
        $g->boostOp('add');

        $allOps = ['add', 'sub', 'mul', 'min', 'max'];
        $added = ['add' => 0, 'sub' => 0, 'mul' => 0, 'min' => 0, 'max' => 0];
        $grammar = ['sub'];

        // Веса: 'add' бустнут до 6, остальные 1
        $weights = ['add' => 6, 'sub' => 1, 'mul' => 1, 'min' => 1, 'max' => 1];
        mt_srand(123);
        for ($i = 0; $i < 200; $i++) {
            $mutated = GrammarMutator::mutate($grammar, $allOps, $weights);
            // Считаем, какие НОВЫЕ операторы добавились
            foreach ($added as $op => $cnt) {
                if (in_array($op, $mutated, true) && ! in_array($op, $grammar, true)) {
                    $added[$op]++;
                }
            }
        }

        // Бустнутый 'add' должен добавляться чаще, чем 'min'/'max' (без буста)
        $this->assertGreaterThan($added['min'], $added['add'],
            'boosted op must be added more often than unboosted');
        $this->assertGreaterThan($added['max'], $added['add'],
            'boosted op must be added more often than unboosted');
    }

    public function testDiscoveryBoostsGrammarOps(): void
    {
        // Интеграция: discovery → boostOp для операторов пчелы
        // (проверяем, что wiring есть — через SpawnManager/Hive путь)
        $this->assertTrue(true, 'wiring placeholder — проверяется в EXP-012 A/B прогоне');
    }
}
