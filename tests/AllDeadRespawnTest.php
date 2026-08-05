<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Bee;
use BeeSwarm\Hive\SpawnManager;

/**
 * AUDIT 05.08 §2.7: Kill Test — при ALL_DEAD сторожевой процесс обязан
 * породить SEED_SPAWN (3 пчелы с разными G) и восстановить N≥3.
 * Баг: trySpawn спавнит только от живых — при всех мёртвых рой умирает.
 */
class AllDeadRespawnTest extends TestCase
{
    public function testSpawnFromDeadBeesResurrectsSwarm(): void
    {
        // 3 мёртвых пчелы (энергия 0)
        $bees = [];
        foreach (['+', '×', 'min'] as $g) {
            $bees[] = new Bee([$g], 0.0);
        }
        foreach ($bees as $b) {
            $this->assertFalse($b->isAlive(), 'seed bee must be dead');
        }

        $spawner = new SpawnManager();
        $spawned = $spawner->trySpawn($bees, ['+', '×', '-', '/', 'min', 'max', 'sq']);

        // §2.7: SEED_SPAWN — ровно 3 новых пчелы с разными G
        $this->assertSame(3, $spawned, 'SEED_SPAWN must create exactly 3 bees');
        $alive = array_filter($bees, fn (Bee $b): bool => $b->isAlive());
        $this->assertCount(3, $alive, 'exactly 3 alive bees after respawn');
        $grams = array_map(fn (Bee $b): string => implode(',', $b->grammar()), array_values($alive));
        $this->assertSame(3, count(array_unique($grams)), 'seed bees must have DIFFERENT grammars');
    }
}
