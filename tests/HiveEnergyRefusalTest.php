<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * T1 (story theorem-level): ENERGY-класс тихого отказа.
 *
 * Контрпример из эмпирики 04.09: Hive::doDiscoverTick при
 * routedBee===null / !isAlive() делает `return;` БЕЗ лога и БЕЗ вердикта —
 * «disembodied discovery»-гвард (02.08) решает проблему энергии, но
 * убивает наблюдаемость: улей не знает, что отказался.
 *
 * Критерий §3.3 (self-model): любой отказ discovery обязан оставлять
 * машиночитаемый след (log-строку с классом ENERGY). Не требуем
 * изменения контракта discoverTick — только наблюдаемость.
 */
final class HiveEnergyRefusalTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logFile = tempnam(sys_get_temp_dir(), 'hive_energy_');
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
        parent::tearDown();
    }

    /**
     * Вопрос-минимум (наблюдаемость): гвард doDiscoverTick при отсутствии
     * живой пчелы логируется как ENERGY-отказ, а не тихий return.
     *
     * Это characterization-тест ТРЕБУЕМОГО поведения: до фикса он RED
     * (лог пуст), после — GREEN (строка ENERGY в логе).
     * Симуляция: reflection-вызов private doDiscoverTick с routedBee=null.
     */
    public function testDeadBeeRefusalLeavesEnergyTrace(): void
    {
        Database::setPath(':memory:');
        $hive = new Hive(maxTicks: 0, logFile: $this->logFile);
        $hive->run(); // bootstrap без тиков

        $method = new \ReflectionMethod(Hive::class, 'doDiscoverTick');
        $method->setAccessible(true);

        $foundAny = false;
        $task = ['name' => 'test_task', 'domain' => 'test'];
        // routedBee у fresh-улья null (не было тиков) → гвард сработает
        $method->invoke($hive, $task, [[1.0, 2.0]], [1.0], 'test', $foundAny);

        $log = (string) file_get_contents($this->logFile);
        self::assertStringContainsString(
            'ENERGY',
            $log,
            'тихий отказ при мёртвой пчеле должен оставлять ENERGY-след (self-model §3.3)'
        );
    }
}
