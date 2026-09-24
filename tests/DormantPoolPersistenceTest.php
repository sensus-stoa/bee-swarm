<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Hive\Bee;
use BeeSwarm\Hive\DormantPool;
use BeeSwarm\Hive\Hive;
use BeeSwarm\Infra\Database;

/**
 * DORMANT-PERSIST (24.09, triage R4): dormantPool in-memory — рецепты не
 * переживают рестарт демона, dormant-потомство теряется. Фикс по
 * прецеденту bee_persistence: DDL dormant_pool (tableopts=poolType,
 * поимённые ALTER), save в периодический savePopulation-ритм и shutdown,
 * load при RESTORE/cold-start в bootstrap.
 *
 * Контракт save/load: сохраняются ВСЕ записи (включая pinned —
 * awakened-флаг + awakened_at восстанавливаются), nextId продолжается
 * (id не переиспользуются — remove() чужих id невозможен).
 */
final class DormantPoolPersistenceTest extends TestCase
{
    /** @var list<string> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        putenv('NO_BASE_TASKS=1');
        putenv('FORAGER_SOURCES=:');
        putenv('CORPUS_DIRS=:');
        Database::get()->exec('DELETE FROM dormant_pool');
        Database::get()->exec('DELETE FROM bee_persistence');
        $this->logs = [];
    }

    protected function tearDown(): void
    {
        foreach (['NO_BASE_TASKS', 'FORAGER_SOURCES', 'CORPUS_DIRS'] as $key) {
            putenv($key);
        }
        foreach ($this->logs as $log) {
            if (is_file($log)) {
                unlink($log);
            }
        }
        Database::get()->exec('DELETE FROM dormant_pool');
        Database::get()->exec('DELETE FROM bee_persistence');
        Database::setPath(':memory:');
        Database::reset();
        parent::tearDown();
    }

    /**
     * savePopulation() материализует пул в dormant_pool.
     */
    public function testSavePersistsPoolEntries(): void
    {
        $hive = $this->bootstrappedHive();
        $pool = $this->poolOf($hive);
        $id1 = $pool->deposit(['op' => '×', 'i' => 1, 'j' => 2], 'PRODUCT', 0.9, 'L1');
        $id2 = $pool->deposit(['op' => '+', 'i' => 3, 'j' => 4], 'SUM', 0.5, 'L2');

        $hive->savePopulation();

        $count = (int) Database::get()->query('SELECT COUNT(*) FROM dormant_pool')->fetchColumn();
        $this->assertSame(2, $count, 'savePopulation материализует обе записи пула');
        $row = Database::get()->query("SELECT * FROM dormant_pool WHERE pool_id={$id1}")->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($row, 'строка пула с исходным id');
        $this->assertSame('PRODUCT', $row['sector']);
        $this->assertSame(0.9, (float) $row['novelty']);
        $this->assertSame('L1', $row['lineage_id']);
        $this->assertSame(0, (int) $row['awakened'], 'не-awakened запись сохраняется флагом 0 (санити шейпа)');
        unset($id2);
    }

    /**
     * loadPersistedPool() восстанавливает пул: рецепты, novelty, lineage,
     * nextId продолжается после максимального сохранённого id.
     */
    public function testLoadRestoresEntriesAndNextId(): void
    {
        $pool = new DormantPool(300, 0);
        $id1 = $pool->deposit(['op' => '×', 'i' => 1, 'j' => 2], 'PRODUCT', 0.9, 'L1');
        $id2 = $pool->deposit(['op' => '+', 'i' => 3], 'SUM', 0.5);
        $pool->remove($id2); // gap в id — nextId обязан продолжиться с id2+1

        $pool->saveToDb();

        $fresh = new DormantPool(300, 0);
        $loaded = $fresh->loadFromDb();
        $this->assertSame(1, $loaded, 'восстановлена 1 живая запись');

        $this->assertSame(1, $fresh->size());
        $after = $fresh->deposit(['op' => '−'], 'DIFF', 0.7);
        $this->assertGreaterThan($id1, $after, 'nextId продолжается за max сохранённым id (id не переиспользуются)');
    }

    /**
     * Рестарт-контракт: пул с pinned → save → новый Hive (RESTORE-путь с
     * bee_persistence) → load в его dormantPool; pinned-флаг восстановлен.
     */
    public function testRestartRestoresPoolWithPinnedFlags(): void
    {
        $db = Database::get();
        $db->exec('DELETE FROM bee_persistence');
        $stmt = $db->prepare('INSERT INTO bee_persistence (grammar, energy, is_alive) VALUES (?, ?, 1)');
        $stmt->execute([json_encode(['+', '*']), 10.0]);

        $log1 = (string) tempnam(sys_get_temp_dir(), 'dp_a_');
        $this->logs[] = $log1;
        $hive1 = new Hive(maxTicks: 0, logFile: $log1);
        $hive1->run();
        $pool1 = $this->poolOf($hive1);
        $pool1->deposit(['op' => '×', 'i' => 9], 'PRODUCT', 0.95, 'LX');
        // pinned: awaken с timeout, который ещё не истёк
        $pool1->awaken(1, ['PRODUCT' => 1]);
        $hive1->savePopulation();

        $log2 = (string) tempnam(sys_get_temp_dir(), 'dp_b_');
        $this->logs[] = $log2;
        $hive2 = new Hive(maxTicks: 0, logFile: $log2);
        $hive2->run(); // RESTORE-путь (bee_persistence непуста)

        $pool2 = $this->poolOf($hive2);
        $this->assertSame(1, $pool2->size(), 'после рестарта пул восстановлен (раньше терялся)');
        $seen = $pool2->awaken(10, ['PRODUCT' => 10]);
        $this->assertSame([], $seen, 'восстановленная запись pinned (awakened-флаг пережил рестарт) — не ре-awaken');
    }

    private function bootstrappedHive(): Hive
    {
        $logFile = (string) tempnam(sys_get_temp_dir(), 'dpp_');
        $this->logs[] = $logFile;
        $hive = new Hive(maxTicks: 0, logFile: $logFile);
        $hive->run();

        return $hive;
    }

    private function poolOf(Hive $hive): DormantPool
    {
        $prop = new \ReflectionProperty(Hive::class, 'dormantPool');
        $prop->setAccessible(true);
        $pool = $prop->getValue($hive);
        self::assertInstanceOf(DormantPool::class, $pool);

        return $pool;
    }
}
