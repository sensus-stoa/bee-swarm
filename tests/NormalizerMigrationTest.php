<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Infra\Database;

/**
 * FORMAL-LAYER Ф1: миграция — старые неканонические формулы нормализуются
 * при инициализации БД (бэкфилл). (x1+x0) → (x0+x1).
 *
 * Используется ФАЙЛОВАЯ временная БД: в :memory: reset() создаёт пустую
 * БД и запись теряется. setPath + tempnam — переинициализация той же БД.
 */
class NormalizerMigrationTest extends TestCase
{
    private string $tmpDb = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDb = tempnam(sys_get_temp_dir(), 'migr_') . '.db';
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

    public function testOldFormulaBackfilled(): void
    {
        $db = Database::get();

        // Вставляем НЕканоническую формулу напрямую (обход record())
        $db->prepare('INSERT OR IGNORE INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)')
            ->execute(['old_task', '(x1+x0)', 0.05, 'test_migr']);

        // Переинициализация той же файловой БД → миграция нормализует
        Database::reset();
        $db = Database::get();

        $stmt = $db->prepare('SELECT formula FROM laws WHERE name = ?');
        $stmt->execute(['old_task']);
        $formula = $stmt->fetchColumn();

        $this->assertSame('(x0+x1)', $formula, 'Non-canonical formula must be backfilled');
    }

    public function testCanonicalFormulaUnchanged(): void
    {
        $db = Database::get();
        $db->prepare('INSERT OR IGNORE INTO laws (name, formula, cv, domain) VALUES (?,?,?,?)')
            ->execute(['canon_task', '(x0+x1)', 0.05, 'test_migr2']);

        Database::reset();
        $db = Database::get();

        $stmt = $db->prepare('SELECT formula FROM laws WHERE name = ?');
        $stmt->execute(['canon_task']);
        $formula = $stmt->fetchColumn();

        $this->assertSame('(x0+x1)', $formula, 'Canonical formula must stay unchanged');
    }
}
