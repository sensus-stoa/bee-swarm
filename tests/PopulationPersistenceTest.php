<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Hive;
use BeeSwarm\Hive\Bee;
use BeeSwarm\Infra\Database;

class PopulationPersistenceTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbPath = tempnam(sys_get_temp_dir(), 'persist_') . '.db';
        Database::setPath($this->dbPath);
        Database::get();
    }

    protected function tearDown(): void
    {
        Database::reset();
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
        Database::setPath(':memory:');
        parent::tearDown();
    }

    public function testSaveAndRestoreAliveBees(): void
    {
        // Запуск с сохранением
        $hive1 = new Hive(maxTicks: 5, logFile: tempnam(sys_get_temp_dir(), 'pl1_'));
        $hive1->run();
        $hive1->savePopulation();
        $aliveBefore = count(array_filter($hive1->getBees(), fn (Bee $b) => $b->isAlive()));

        // Перезапуск: та же БД, новый Hive
        Database::reset();
        Database::setPath($this->dbPath);
        $hive2 = new Hive(maxTicks: 0, logFile: tempnam(sys_get_temp_dir(), 'pl2_'));
        $hive2->run(); // bootstrap → loadPopulation

        $beesAfter = $hive2->getBees();
        $aliveAfter = count(array_filter($beesAfter, fn (Bee $b) => $b->isAlive()));

        // Восстановлены живые пчёлы (хотя бы bootstrap-минимум 3)
        $this->assertGreaterThanOrEqual(3, count($beesAfter), 'at least 3 bees after restore');
        $this->assertEquals($aliveBefore, $aliveAfter, 'alive count preserved');
    }

    public function testColdStartCreatesSeedBees(): void
    {
        // Пустая БД → cold start
        $hive = new Hive(maxTicks: 1, logFile: tempnam(sys_get_temp_dir(), 'pl_cold_'));
        $hive->run();

        $bees = $hive->getBees();
        $this->assertCount(3, $bees, 'cold start must create 3 seed bees');
        $hasPlus = (bool) array_filter($bees, fn (Bee $b) => in_array('+', $b->grammar()));
        $hasMul  = (bool) array_filter($bees, fn (Bee $b) => in_array('×', $b->grammar()));
        $this->assertTrue($hasPlus, 'at least one seed bee must have + in grammar');
        $this->assertTrue($hasMul, 'at least one seed bee must have × in grammar');
    }
}
