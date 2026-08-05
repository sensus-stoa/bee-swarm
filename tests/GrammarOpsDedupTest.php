<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Infra\Database;

/**
 * B1-DBDEDUP: миграция дедуплицирует grammar_ops и ставит UNIQUE(name).
 * Факт (05.08): 1044 строки, 544 уникальных, 500 дублей (match_label(GI) x14).
 */
class GrammarOpsDedupTest extends TestCase
{
    private string $tmpDb = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDb = tempnam(sys_get_temp_dir(), 'dedup_') . '.db';
        Database::setPath($this->tmpDb);
    }

    protected function tearDown(): void
    {
        if ($this->tmpDb !== '' && file_exists($this->tmpDb)) {
            unlink($this->tmpDb);
        }
        Database::setPath(':memory:');
        parent::tearDown();
    }

    private function seedDuplicates(): void
    {
        // Имитация СТАРОЙ БД: таблица БЕЗ UNIQUE + дубли, до Database::get()
        $pdo = new \PDO("sqlite:{$this->tmpDb}");
        $pdo->exec(
            "CREATE TABLE grammar_ops (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                source TEXT DEFAULT 'base',
                invented_at TEXT DEFAULT (datetime('now')),
                definition TEXT
            )"
        );
        for ($i = 0; $i < 3; $i++) {
            $pdo->exec("INSERT INTO grammar_ops (name, source) VALUES ('dup_op', 'test')");
        }
        $pdo = null;
    }

    public function testMigrationRemovesDuplicates(): void
    {
        $this->seedDuplicates();

        // Проверка seed ДО миграции — через сырой PDO (get() мигрирует сразу)
        $raw = new \PDO("sqlite:{$this->tmpDb}");
        $this->assertSame(3, (int) $raw->query('SELECT COUNT(*) FROM grammar_ops')->fetchColumn(), 'seed must create 3 rows');
        $raw = null;

        // Переинициализация — миграция должна дедупить
        Database::reset();
        $db = Database::get();

        $total = (int) $db->query('SELECT COUNT(*) FROM grammar_ops')->fetchColumn();
        $uniq = (int) $db->query('SELECT COUNT(DISTINCT name) FROM grammar_ops')->fetchColumn();
        $this->assertSame($uniq, $total, 'after migration COUNT must equal COUNT(DISTINCT name)');
        $this->assertLessThan(3, $total, 'duplicates must be removed');
    }

    public function testDuplicateInsertIgnored(): void
    {
        $this->seedDuplicates();
        Database::reset();
        $db = Database::get();

        // Повторная вставка того же name — IGNORE
        $db->prepare('INSERT OR IGNORE INTO grammar_ops (name, source) VALUES (?,?)')
            ->execute(['dup_op_0', 'test']);

        $cnt = (int) $db->query("SELECT COUNT(*) FROM grammar_ops WHERE name='dup_op_0'")->fetchColumn();
        $this->assertSame(1, $cnt, 'duplicate name must be ignored (UNIQUE)');
    }

    public function testUniqueIndexExists(): void
    {
        $this->seedDuplicates();
        Database::reset();
        $db = Database::get();

        $cols = $db->query('PRAGMA index_list(grammar_ops)')->fetchAll(\PDO::FETCH_ASSOC);
        $uniqueIdx = array_filter($cols, fn (array $i): bool => ($i['unique'] ?? 0) === 1);
        $this->assertNotEmpty($uniqueIdx, 'UNIQUE index on grammar_ops must exist after migration');
    }
}
