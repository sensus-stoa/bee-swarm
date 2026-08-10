<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Infra\Database;

/**
 * TYPED-EXECUTE (09.08, ЭКСП-022o): SQLite INTEGER >= TEXT('7') = 0 строк
 * (PHP PDO execute([...int]) связывает как TEXT). Database::run() — PHP-типы
 * → PDO-типы. Регрессия: числовой параметр в WHERE сравнивается верно.
 */
class DatabaseTypedRunTest extends TestCase
{
    public function testIntParamComparesAsInt(): void
    {
        $db = Database::get();
        $db->exec('CREATE TABLE IF NOT EXISTS typed_probe (v INTEGER, t TEXT)');
        $db->exec('DELETE FROM typed_probe');
        $db->exec('INSERT INTO typed_probe VALUES (7, "seven"), (3, "three"), (10, "ten")');

        // ПРОБЛЕМНЫЙ путь: execute(массив) связывает int как TEXT.
        // На КОЛОНКЕ спасает affinity; на ВЫРАЖЕНИИ (length()) — баг:
        $stmt = $db->prepare('SELECT COUNT(*) FROM typed_probe WHERE length(t) >= ?');
        $stmt->execute([5]); // 'seven' (5), 'three' (5) — ожидаем 2, получаем 0
        $broken = (int) $stmt->fetchColumn();

        // ПРАВИЛЬНЫЙ путь (Database::run — int → PARAM_INT):
        $ok = (int) Database::run('SELECT COUNT(*) FROM typed_probe WHERE length(t) >= ?', [5])->fetchColumn();

        $this->assertSame(0, $broken, 'execute([int]) на выражении length() → 0 (баг-регрессия)');
        $this->assertSame(2, $ok, 'Database::run([int]) → PARAM_INT → 2 строки (seven, three)');
    }
}
