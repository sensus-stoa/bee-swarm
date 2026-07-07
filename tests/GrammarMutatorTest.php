<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\GrammarMutator;

/**
 * Story S1.2 Phase 1: GrammarMutator
 * Protocol §2.2: mutate(G) — add/remove/replace one random operation
 */
class GrammarMutatorTest extends TestCase
{
    private const BASELINE = ['add', 'mul', 'sub', 'div', 'sq'];

    public function testMutateAddsOperation(): void
    {
        $available = ['add', 'mul', 'sub', 'div', 'sq', 'sqrt', 'abs', 'max'];
        $mutated = GrammarMutator::mutate(self::BASELINE, $available);

        // Either added, removed, or replaced → size changes by ±1 or stays same (replace)
        $sizeDiff = count($mutated) - count(self::BASELINE);
        $this->assertContains($sizeDiff, [-1, 0, 1], 'Mutation must change grammar by at most 1 op');
    }

    public function testMutateChangesGrammar(): void
    {
        $available = ['add', 'mul', 'sub', 'div', 'sq', 'sqrt', 'abs', 'max', 'min'];
        $mutated = GrammarMutator::mutate(self::BASELINE, $available);

        // Must differ from original (unless only one possible outcome)
        $this->assertNotEquals(self::BASELINE, $mutated, 'Mutation must change grammar');
    }

    public function testMutateNeverEmpty(): void
    {
        $available = ['add', 'mul'];
        $mutated = GrammarMutator::mutate(['add'], $available);

        $this->assertNotEmpty($mutated, 'Grammar must never be empty after mutation');
    }

    public function testMutateOnlyUsesAvailable(): void
    {
        $available = ['add', 'mul', 'sub'];
        $mutated = GrammarMutator::mutate(self::BASELINE, $available);

        // No op from outside available can be ADDED
        foreach ($mutated as $op) {
            if (! in_array($op, self::BASELINE)) {
                $this->assertContains($op, $available, "Added op '{$op}' not in available");
            }
        }
        // Should not be empty unless nothing to add/remove/replace
        $this->assertNotEmpty($mutated);
    }

    public function testMutateReturnsIdentityWhenSaturated(): void
    {
        // Grammar already has all available ops, too small to remove
        $grammar = ['add', 'mul'];
        $available = ['add', 'mul'];
        $mutated = GrammarMutator::mutate($grammar, $available);

        // Must return original (no mutation possible)
        $this->assertSame($grammar, $mutated, 'Saturated grammar returns unchanged');
    }
}
