<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Bee;
use BeeSwarm\Hive\SpawnManager;

/**
 * CONCERNS deleg_7319d247: SPAWN-индексы при НЕСКОЛЬКИХ родителях
 * и дырах в ключах. Фактический ключ (child_key) ДО append — не формула!
 */
class SpawnDetailsTest extends TestCase
{
    public function testTwoParentsSpawnInOneTickExactKeys(): void
    {
        $bees = [new Bee(['add']), new Bee(['mul']), new Bee(['sub'])];
        // Форсируем спавн: энергия выше порога SPAWN_THRESHOLD
        foreach ($bees as $b) {
            $r = new \ReflectionProperty(Bee::class, 'energy');
            $r->setValue($b, 20.0);
        }
        $sm = new SpawnManager();
        // Форсируем дыру: удаляем пчелу №1 (unset, без реиндексации!)
        unset($bees[1]);

        [$count, $details] = $sm->trySpawn($bees, ['add', 'mul', 'sub']);

        $this->assertGreaterThan(0, $count, 'спавн случился');
        foreach ($details as $d) {
            // фактический ключ: bees[d['child_key']] — ребёнок на месте
            $this->assertArrayHasKey($d['child_key'], $bees,
                'child_key указывает на существующего ребёнка!');
            $this->assertNotEquals($d['parent'], $d['child_key'],
                'ребёнок ≠ родитель (позиция!)');
        }
        // плотность: после append без реиндексации ключи {0,2,3...}
        // проверяем: у родителя 2 (mul) — key 2: ребёнок получил новый ключ
        foreach ($details as $d) {
            if ($d['parent'] === 2) {
                $this->assertGreaterThan(2, $d['child_key'], 'ребёнок в хвосте!');
            }
        }
    }

    public function testSeedSpawnDetails(): void
    {
        $bees = [new Bee(['add'])];
        foreach ($bees as $b) {
            $r = new \ReflectionProperty(Bee::class, 'energy');
            $r->setValue($b, 0.0);
        }
        $sm = new SpawnManager();
        [$count, $details] = $sm->trySpawn($bees, ['add']);

        $this->assertSame(3, $count, 'SEED_SPAWN: 3 пчелы!');
        foreach ($details as $d) {
            $this->assertNull($d['parent'], 'seed: родителя нет!');
            $this->assertArrayHasKey($d['child_key'], $bees);
        }
    }
}
