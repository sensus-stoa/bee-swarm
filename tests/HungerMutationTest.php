<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Bee;

/**
 * S1.5-HUNGER: Hunger-driven mutation
 *
 * При E<5 пчела мутирует грамматику ДО смерти.
 * Cold-start bridge: без открытий → голод → mutation → exploration.
 */
class HungerMutationTest extends TestCase
{
    /** При E<5 пчела мутирует грамматику */
    public function testHungerTriggersMutation(): void
    {
        $bee = new Bee(['add', 'mul', 'sq', 'sqrt', 'max', 'min', 'sub', 'div'], 4.0);
        $available = ['add', 'mul', 'sq', 'sqrt', 'max', 'min', 'sub', 'div', 'Parity', 'Log2'];

        $originalGrammar = $bee->grammar();
        $bee->hungerMutate($available);

        $this->assertNotEquals($originalGrammar, $bee->grammar(), 'Grammar must change on hunger');
    }

    /** При E≥5 мутация НЕ происходит */
    public function testNoMutationWhenWellFed(): void
    {
        $bee = new Bee(['add', 'mul'], 7.0);
        $available = ['add', 'mul', 'sq', 'sqrt'];

        $originalGrammar = $bee->grammar();
        $bee->hungerMutate($available);

        $this->assertEquals($originalGrammar, $bee->grammar(), 'Well-fed bee must not mutate');
    }

    /** Голодная мутация не пересекает spawn-порог */
    public function testHungerMutationDoesNotTriggerSpawn(): void
    {
        $bee = new Bee(['add', 'mul'], 4.0);
        $available = ['add', 'mul', 'sq', 'sqrt'];

        $child = $bee->spawn($available);
        $this->assertNull($child, 'Hungry bee (E<15) must not spawn');

        // Но мутация срабатывает
        $bee->hungerMutate($available);
        $this->assertLessThan(15.0, $bee->energy(), 'Mutation costs energy but must not trigger spawn threshold');
    }
}
