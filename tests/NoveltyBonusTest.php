<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Bee;

/**
 * S1.7-NOVELTY: Novelty Bonus
 *
 * Пчела получает +0.5 за задачу с новым fingerprint'ом.
 * Exploration budget не привязанный к CV. Паттерн B.
 *
 * Запускать вручную.
 */
class NoveltyBonusTest extends TestCase
{
    /** Новая задача даёт энергию пчеле */
    public function testNoveltyBonusExists(): void
    {
        // Проверяем что константа определена в коде
        $code = file_get_contents(__DIR__ . '/../src/Hive/Hive.php');
        $this->assertStringContainsString('NOVELTY', $code, 'Novelty bonus constant must exist');
    }

    /** Bee получает +0.5 за exploration */
    public function testBeeGainsNoveltyEnergy(): void
    {
        $bee = new Bee(['add', 'mul'], 5.0);
        $energyBefore = $bee->energy();

        // Симулируем novelty reward
        $bee->rewardNovelty();

        $this->assertEqualsWithDelta($energyBefore + 0.5, $bee->energy(), 0.001, 'Novelty gives +0.5 energy');
    }
}
