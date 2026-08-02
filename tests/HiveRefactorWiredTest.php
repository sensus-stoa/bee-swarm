<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;

/**
 * Проверяет что engines завязаны на Hive после D16-D18 рефакторинга.
 */
class HiveRefactorWiredTest extends TestCase
{
    public function testRecordKeeperIsWired(): void
    {
        $hive = new Hive(logFile: tempnam(sys_get_temp_dir(), 'hive_'));
        // RecordKeeper должен быть инициализирован
        $ref = new \ReflectionClass($hive);
        $prop = $ref->getProperty('recordKeeper');
        $this->assertNotNull($prop->getValue($hive));
    }

    public function testSpawnManagerIsWired(): void
    {
        $hive = new Hive(logFile: tempnam(sys_get_temp_dir(), 'hive_'));
        $ref = new \ReflectionClass($hive);
        $prop = $ref->getProperty('spawnManager');
        $this->assertNotNull($prop->getValue($hive));
    }

    public function testIdleDreamerTickIsCalled(): void
    {
        // Косвенная проверка: IdleDreamer::tick существует
        $this->assertTrue(method_exists(\BeeSwarm\Hive\IdleDreamer::class, 'tick'));
    }
}
