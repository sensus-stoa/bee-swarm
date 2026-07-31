<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Infra\Database;

/**
 * S1.10-MEMORY-DB: тестовая БД в памяти (:memory:).
 *
 * Guard в TestCase должен пропускать :memory: (иначе ВСЕ тесты
 * скипаются молча — 335 skipped, ловушка из test-guard-verification).
 */
class MemoryDbTest extends TestCase
{
    /**
     * Database::get() с :memory: создаёт рабочую БД (migrate() выполнен).
     */
    public function testMemoryDbIsUsable(): void
    {
        $db = Database::get();
        $this->assertInstanceOf(\PDO::class, $db);

        // Запись/чтение работают
        $db->exec("INSERT INTO laws (name, formula, cv, domain) VALUES ('MEM_TEST', 'x', 0, 'test')");
        $val = $db->query("SELECT name FROM laws WHERE name = 'MEM_TEST'")->fetchColumn();
        $this->assertSame('MEM_TEST', $val);
        $db->exec("DELETE FROM laws WHERE name = 'MEM_TEST'");
    }

    /**
     * migrate() создал все 4 рабочие таблицы.
     */
    public function testMemoryDbHasSchema(): void
    {
        $db = Database::get();
        $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table'")
            ->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertContains('laws', $tables);
        $this->assertContains('grammar_ops', $tables);
        $this->assertContains('knowledge_graph', $tables);
        $this->assertContains('overlap_log', $tables);
    }
}
