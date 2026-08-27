<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Bee;
use BeeSwarm\Hive\Hive;
use BeeSwarm\Hive\DormantPool;

/**
 * SPAWN-POOL-INTEGRATION Фаза C (27.08): Lineage Tracking.
 *
 * Линии (родословные) отслеживаются: parent → children.
 * Линия без прогресса K поколений умирает (без прогресса = ни одна
 * пчела линии не открыла закон и не выросла в энергии).
 * Новым линиям — exploration bonus (новизна рода).
 */
class LineageTrackingTest extends TestCase
{
    private function makeShortHive(): Hive
    {
        // FORAGER_SOURCES пустая строка = «источники заданы как пустой список»
        // — Hive::foragerSources станет [] и scanWithAccumulator не запустится
        putenv('FORAGER_SOURCES=:');
        $hive = new Hive(maxTicks: 0, logFile: tempnam(sys_get_temp_dir(), 'lin_'));
        $hive->run();
        return $hive;
    }

    public function testMaterializedBeeKnowsItsParent(): void
    {
        $hive = $this->makeShortHive();
        $pool = $hive->dormantPool();

        // Первый пчела из seed — её lineage id
        $bees = $hive->getBees();
        $this->assertNotEmpty($bees, 'bootstrap обязан дать seed-пчёл');
        $parentId = $bees[0]->lineageId();

        $pool->deposit(['op' => '+', 'operand' => 'x0'], 'ADDITIVE', 0.5);
        $hive->materializeFromPool(1);

        $newBee = $hive->getBees()[count($hive->getBees()) - 1];
        $this->assertNotSame('', $newBee->lineageId(), 'lineage id обязателен');
        $this->assertSame($parentId, $newBee->parentLineageId(),
            'потомок помнит родительскую линию');
    }

    public function testLineageStatsCountsLines(): void
    {
        $hive = $this->makeShortHive();
        $pool = $hive->dormantPool();

        $pool->deposit(['op' => '+', 'operand' => 'x0'], 'ADDITIVE', 0.5);
        $hive->materializeFromPool(2);

        $stats = $hive->lineageStats();
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('lines', $stats);
        $this->assertGreaterThan(0, $stats['lines'], 'есть минимум одна линия');
    }

    public function testNoProgressLineDiesAfterKGenerations(): void
    {
        $hive = $this->makeShortHive();
        $pool = $hive->dormantPool();

        // K=2: линия без прогресса 2 поколения подряд умирает
        $k = 2;
        for ($gen = 0; $gen <= $k; $gen++) {
            $pool->deposit(['op' => '+', 'operand' => 'x0'], 'ADDITIVE', 0.5);
            $hive->materializeFromPool(1);
            // НЕ делаем прогресс: ни discovery, ни рост энергии
            $dead = $hive->pruneLineages($k);
            if ($gen === $k) {
                $this->assertGreaterThan(0, $dead,
                    "линия без прогресса {$k} поколений обязана быть подрезана");
            }
        }
    }

    public function testProgressLineSurvives(): void
    {
        $hive = $this->makeShortHive();
        $pool = $hive->dormantPool();

        $bees = $hive->getBees();
        $lineageId = $bees[0]->lineageId();

        // Прогресс: энергия линии выросла (rewardDiscovery-эквивалент)
        $ref = new \ReflectionProperty(Bee::class, 'energy');
        $ref->setAccessible(true);
        $ref->setValue($bees[0], 12.0);
        $hive->noteLineageProgress($lineageId);

        $dead = $hive->pruneLineages(2);
        $this->assertSame(0, $dead, 'линия с прогрессом НЕ подрезается');
    }

    public function testNewLineageGetsExplorationBonus(): void
    {
        $hive = $this->makeShortHive();
        $pool = $hive->dormantPool();

        $bonusBefore = $this->bonusOf($hive, 0);

        $pool->deposit(['op' => 'sq', 'operand' => 'x1'], 'POWER', 0.5);
        $hive->materializeFromPool(1);

        // Новая линия (первое появление сектора POWER) получает бонус энергии
        $newBee = $hive->getBees()[count($hive->getBees()) - 1];
        $this->assertEqualsWithDelta(
            Bee::SPAWN_CHILD_ENERGY + Bee::EXPLORATION_BONUS,
            $newBee->energy(),
            0.001,
            'новая линия получает exploration bonus'
        );
    }

    private function bonusOf(Hive $hive, int $idx): float
    {
        $bees = $hive->getBees();
        return $bees[$idx]->energy() ?? 0.0;
    }
}
