<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Bee;

/**
 * §2.5.14 AUTOPHAGY — обновлённый контракт hunger-пути.
 *
 * Старые тесты §S1.5-HUNGER (случайная мутация GrammarMutator) заменены:
 * §2.5.14 заменяет panic-мутацию на селективную деградацию. Спячка при
 * E<3 (SHRINK, 08.08) сохранена — тест №2 прежней семантики.
 */
final class HungerMutationTest extends TestCase
{
    /**
     * При 3≤E<5 пчела не растит грамматику: деградация или ничего.
     */
    public function testHungerDoesNotGrowGrammar(): void
    {
        $bee = new Bee(['+', '×', 'sq', 'B7'], 4.0);
        $before = count($bee->grammar());

        $bee->autophagy();

        $this->assertLessThanOrEqual(
            $before,
            count($bee->grammar()),
            'hunger must never ADD ops (autophagy replaced random mutation)'
        );
    }

    /**
     * При E≥5 деградация НЕ происходит (пчела сыта).
     */
    public function testNoMutationWhenWellFed(): void
    {
        $bee = new Bee(['add', 'mul'], 7.0);
        $originalGrammar = $bee->grammar();

        $this->assertSame([], $bee->autophagy());
        $this->assertEquals($originalGrammar, $bee->grammar(), 'Well-fed bee must not degrade');
    }

    /**
     * Голодная пчела не пересекает spawn-порог деградацией.
     */
    public function testHungerDoesNotTriggerSpawn(): void
    {
        $bee = new Bee(['add', 'mul'], 4.0);

        $child = $bee->spawn(['add', 'mul', 'sq', 'sqrt']);
        $this->assertNull($child, 'Hungry bee (E<15) must not spawn');

        // Деградация базовых невозможна → грамматика/энергия не меняются
        $this->assertSame([], $bee->autophagy());
        $this->assertLessThan(15.0, $bee->energy());
    }
}
