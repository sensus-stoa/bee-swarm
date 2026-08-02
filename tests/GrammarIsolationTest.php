<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Bee;

/**
 * Story V1.3: Grammar Isolation (§2.3)
 *
 * Phase 1: каждая пчела хранит свою грамматику. Открытия добавляются в per-bee набор,
 * не в общую БД. Две пчелы с разными грамматиками — не влияют друг на друга.
 */
class GrammarIsolationTest extends TestCase
{
    /**
     * Пчела хранит СВОЙ набор операций, независимый от других пчёл.
     *
     * Predicted: FAIL — метод addToGrammar не существует.
     */
    public function testBeeHasOwnGrammar(): void
    {
        $bee1 = new Bee(['add', 'mul', 'sub'], 10.0);
        $bee2 = new Bee(['add', 'div', 'sq'], 10.0);

        // У каждой пчелы своя грамматика (seed + BASE_OPS)
        $this->assertContains('add', $bee1->grammar());
        $this->assertContains('mul', $bee1->grammar());
        $this->assertContains('sub', $bee1->grammar());
        $this->assertContains('add', $bee2->grammar());
        $this->assertContains('div', $bee2->grammar());
        $this->assertContains('sq', $bee2->grammar());

        // Пчела 1 добавляет атом в свою грамматику
        $bee1->addToGrammar('custom_op');
        $this->assertContains('custom_op', $bee1->grammar(), 'Bee1 must have custom_op');

        // Пчела 2 НЕ должна получить custom_op
        $this->assertNotContains('custom_op', $bee2->grammar(), 'Bee2 must NOT have custom_op — grammar isolation');
    }

    /**
     * grammar() возвращает per-bee ops (seed + custom), без BASE_OPS.
     * BASE_OPS доступны через Grammar::baseOpNames().
     */
    public function testGrammarReturnsPerBeeOpsOnly(): void
    {
        $bee = new Bee(['custom1'], 10.0);

        $grammar = $bee->grammar();

        // Должны быть per-bee ops
        $this->assertContains('custom1', $grammar, 'Seed op must be in grammar');
        // BASE_OPS НЕ должны быть в grammar() — они добавляются отдельно
        $this->assertNotContains('+', $grammar, 'BASE_OPS must NOT be in per-bee grammar');
    }

    /**
     * Две пчелы не влияют на грамматику друг друга.
     *
     * Predicted: FAIL — addToGrammar не существует.
     */
    public function testGrammarIsolationBetweenBees(): void
    {
        $beeA = new Bee(['add', 'mul'], 10.0);
        $beeB = new Bee(['sub', 'div'], 10.0);

        $beeA->addToGrammar('discovery_A');
        $beeB->addToGrammar('discovery_B');

        $this->assertContains('discovery_A', $beeA->grammar());
        $this->assertNotContains('discovery_A', $beeB->grammar());
        $this->assertContains('discovery_B', $beeB->grammar());
        $this->assertNotContains('discovery_B', $beeA->grammar());
    }
}
