<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Bee;

/**
 * Story S1.3: Grammar Isolation — per-bee grammar, no shared state
 * Protocol §2.3: G_i ≠ G_j for independent bees
 */
class GrammarIsolationTest extends TestCase
{
    /**
     * Two bees spawned from same parent must have different grammar
     */
    public function testSpawnProducesIndependentGrammarInstances(): void
    {
        $available = ['add', 'mul', 'sub', 'div', 'sq', 'sqrt', 'abs', 'max', 'min'];

        $parent1 = new Bee(['add', 'mul', 'sub'], 15.0);
        $parent2 = new Bee(['add', 'mul', 'sub'], 15.0);

        $child1 = $parent1->spawn($available);
        $child2 = $parent2->spawn($available);

        $this->assertNotNull($child1);
        $this->assertNotNull($child2);
        // Grammars are independent arrays — modifying one doesn't affect the other
        $child1Grammar = $child1->grammar();
        $child2Grammar = $child2->grammar();
        $child1Grammar[] = 'INJECTED';
        $this->assertNotContains('INJECTED', $child2->grammar(), 'Grammar arrays must be independent copies');
    }

    /**
     * Bee's grammar is independent — modifying one doesn't affect the other
     */
    public function testGrammarIsIndependent(): void
    {
        $bee1 = new Bee(['add', 'mul'], 10.0);
        $bee2 = new Bee(['sub', 'div'], 10.0);

        // Each has its own grammar
        $this->assertSame(['add', 'mul'], $bee1->grammar());
        $this->assertSame(['sub', 'div'], $bee2->grammar());
    }

    /**
     * Grammar persists through energy mutations
     */
    public function testGrammarSurvivesTicks(): void
    {
        $bee = new Bee(['add', 'sq', 'sqrt'], 10.0);
        $bee->tick();
        $bee->chargeSearch();
        $bee->rewardDiscovery();

        $this->assertSame(['add', 'sq', 'sqrt'], $bee->grammar(), 'Grammar must survive energy mutations');
    }

    /**
     * Spawned child keeps its grammar after parent mutates
     */
    public function testChildGrammarIndependentOfParent(): void
    {
        $parent = new Bee(['add', 'mul'], 15.0);
        $child = $parent->spawn(['add', 'mul', 'sq', 'sqrt']);
        $this->assertNotNull($child);

        $childGrammar = $child->grammar();

        // Parent spawns again (mutates further) — child's grammar must not change
        $parent->spawn(['add', 'mul', 'sq', 'sqrt']);
        $this->assertSame($childGrammar, $child->grammar(), 'Child grammar must be independent of parent after spawn');
    }
}
