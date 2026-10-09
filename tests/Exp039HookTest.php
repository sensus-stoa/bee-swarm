<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Bee;
use BeeSwarm\Hive\Hive;
use BeeSwarm\Hive\SeasonScheduler;

/**
 * EXP-039: интеграционные тесты Hive-хука (env EXP039_MODE).
 *
 * Канон: тест ДО кода. Hive.php не переписывается — только:
 *  1. init SeasonScheduler в конструкторе при EXP039_MODE
 *  2. гейт когнитивного блока doTick (explore/dream/verify/rest)
 *  3. pop-ledger в run()
 * Без env — нулевой код-путь (прод не затронут).
 */
final class Exp039HookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('EXP039_MODE');
        putenv('EXP039_SEED');
        putenv('EXP039_BUDGET');
        putenv('EXP039_TICKS');
    }

    protected function tearDown(): void
    {
        putenv('EXP039_MODE');
        putenv('EXP039_SEED');
        putenv('EXP039_BUDGET');
        putenv('EXP039_TICKS');
        parent::tearDown();
    }

    private function makeHive(): Hive
    {
        $hive = new Hive(maxTicks: 0, logFile: tempnam(sys_get_temp_dir(), 'exp039_') . '.log');
        $hive->run(); // bootstrap only

        return $hive;
    }

    private function beesOf(Hive $hive): array
    {
        $p = new \ReflectionProperty(Hive::class, 'bees');
        $p->setAccessible(true);

        return $p->getValue($hive) ?: [];
    }

    private function schedulerOf(Hive $hive): ?SeasonScheduler
    {
        $p = new \ReflectionProperty(Hive::class, 'seasonScheduler');
        $p->setAccessible(true);

        return $p->getValue($hive);
    }

    public function testNoEnvNoScheduler(): void
    {
        $hive = $this->makeHive();
        $this->assertNull($this->schedulerOf($hive), 'без env scheduler не создаётся');
    }

    public function testModeAInjected(): void
    {
        putenv('EXP039_MODE=A');
        putenv('EXP039_SEED=777');
        $hive = $this->makeHive();
        $s = $this->schedulerOf($hive);
        $this->assertNotNull($s);
        $this->assertSame('explore', $s->roll(12345));
    }

    public function testModeBGateDream(): void
    {
        putenv('EXP039_MODE=B');
        putenv('EXP039_SEED=42');
        $hive = $this->makeHive();
        $s = $this->schedulerOf($hive);
        $this->assertNotNull($s);
        // tick в фазе 0 (Imbolc): dream 40% — существует тик с roll=dream,
        // гейт обязан пропускать ТОЛЬКО dream (без explore-поиска)
        $dreamTick = null;
        for ($t = 1; $t < SeasonScheduler::TICKS_PER_PHASE; $t++) {
            if ($s->roll($t) === 'dream') {
                $dreamTick = $t;
                break;
            }
        }
        $this->assertNotNull($dreamTick, 'в фазе 0 dream обязан выпадать');
        // исполняем dream-тик живым роем: не должно быть探索 (candidates/search)
        $p = new \ReflectionProperty(Hive::class, 'tick');
        $p->setAccessible(true);
        $p->setValue($hive, $dreamTick);
        $doTick = new \ReflectionMethod(Hive::class, 'doTick');
        $doTick->setAccessible(true);
        // инжекция routedBee (иначе dream скипается по no-live-bee)
        $bees = $this->beesOf($hive);
        $rbP = new \ReflectionProperty(Hive::class, 'routedBee');
        $rbP->setAccessible(true);
        $rbP->setValue($hive, $bees[0] ?? null);
        $before = count($this->logOf($hive));
        $doTick->invoke($hive);
        $log = implode("\n", array_slice($this->logOf($hive), $before));
        $this->assertStringNotContainsString('ROUTE:', $log, 'dream-тик не маршрутизирует задачу');
        $this->assertStringNotContainsString('AUTOPHAGY:', $log, 'метаболизм/энергетика живут — но только не когнитивный explore');
        // dream-тик МОЖЕТ логировать DREAM (если открытие) — запрещён только explore
    }

    public function testModeBRestTickSkipsCognitionButMetabolismRuns(): void
    {
        putenv('EXP039_MODE=B');
        putenv('EXP039_SEED=42');
        $hive = $this->makeHive();
        $s = $this->schedulerOf($hive);
        // ищем rest-тик в фазе 4 (Lammas: rest 20%)
        $restTick = null;
        for ($t = 40000; $t < 45000; $t++) {
            if ($s->roll($t) === 'rest') {
                $restTick = $t;
                break;
            }
        }
        $this->assertNotNull($restTick);
        $bees = $this->beesOf($hive);
        $e0 = $bees[0]->energy();
        $p = new \ReflectionProperty(Hive::class, 'tick');
        $p->setAccessible(true);
        $p->setValue($hive, $restTick);
        $doTick = new \ReflectionMethod(Hive::class, 'doTick');
        $doTick->setAccessible(true);
        $rbP = new \ReflectionProperty(Hive::class, 'routedBee');
        $rbP->setAccessible(true);
        $rbP->setValue($hive, $bees[0] ?? null);
        $doTick->invoke($hive);
        $this->assertLessThan($e0, $this->beesOf($hive)[0]->energy(), 'rest-тик жжёт метаболизм (иначе B получает бессмертие)');
    }

    public function testLedgerWrittenInRun(): void
    {
        putenv('EXP039_MODE=A');
        putenv('EXP039_SEED=1');
        $hive = $this->makeHive();
        // ручной тик-цикл как в драйвере
        $p = new \ReflectionProperty(Hive::class, 'tick');
        $p->setAccessible(true);
        $doTick = new \ReflectionMethod(Hive::class, 'doTick');
        $doTick->setAccessible(true);
        $p->setValue($hive, 1);
        $doTick->invoke($hive);
        $s = $this->schedulerOf($hive);
        $this->assertNotNull($s);
        $this->assertSame([], $s->ledgerRows(), 'tick=1 не кратен 50 — ledger пуст');

        // tick 50: ledger обязан записать строку
        $p->setValue($hive, 50);
        $doTick->invoke($hive);
        $this->assertNotEmpty($s->ledgerRows(), 'ledger пишет на кратных 50 тиках');
        $rows = $s->ledgerRows();
        $row = end($rows);
        $this->assertSame(50, $row['tick']);
    }

    public function testLedgerWritesEvery50(): void
    {
        putenv('EXP039_MODE=A');
        putenv('EXP039_SEED=1');
        $hive = $this->makeHive();
        $p = new \ReflectionProperty(Hive::class, 'tick');
        $p->setAccessible(true);
        $doTick = new \ReflectionMethod(Hive::class, 'doTick');
        $doTick->setAccessible(true);
        $p->setValue($hive, 50);
        $doTick->invoke($hive);
        $s = $this->schedulerOf($hive);
        $rows = $s->ledgerRows();
        $this->assertCount(1, $rows);
        $this->assertSame(50, $rows[0]['tick']);
        $this->assertArrayHasKey('injections', $rows[0]);
        $this->assertArrayHasKey('sum_energy', $rows[0]);
    }

    /**
     * @return string[]
     */
    private function logOf(Hive $hive): array
    {
        $p = new \ReflectionProperty(Hive::class, 'log');
        $p->setAccessible(true);

        return $p->getValue($hive) ?: [];
    }
}
