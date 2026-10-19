<?php
declare(strict_types=1);

namespace BeeSwarm\Tests;

use BeeSwarm\Infra\Database;
use PHPUnit\Framework\TestCase;

/**
 * R4-WU-1 (EXP-045 Road v4, спека WU_ROAD_V4_SPEC.md §1): схема state machine.
 *
 * trail_marks v4: +9 колонок (class, state, written_count, read_cross_count,
 * confirm_count, fit_last, birth_route, last_activity_tick, evict_count).
 * trail_ghosts: томстоны {evict_count, confirm_count, fit_last} per-formula.
 *
 * Fail-loud миграция: PRAGMA+ALTER паттерн corr-колонки (10.10), upgrade-путь
 * со старой схемой (prod-инсталляции без v4-колонок) тестируется явно.
 *
 * RED-прогноз: 3 failures — колонок v4 нет, таблицы trail_ghosts нет,
 * upgrade-путь не добавляет колонок.
 */
class TrailMarksV4SchemaTest extends TestCase
{
    private const V4_COLUMNS = [
        'class', 'state', 'written_count', 'read_cross_count', 'confirm_count',
        'fit_last', 'birth_route', 'last_activity_tick', 'evict_count',
    ];

    /**
     * @return list<string>
     */
    private static function trailColumns(): array
    {
        $cols = Database::get()->query('PRAGMA table_info(trail_marks)')->fetchAll();

        return array_column($cols, 'name');
    }

    public function testTrailMarksHasV4StateColumns(): void
    {
        $cols = self::trailColumns();
        foreach (self::V4_COLUMNS as $c) {
            $this->assertContains($c, $cols, "trail_marks must have v4 column {$c}");
        }
    }

    public function testGhostTableExistsWithTombstoneFields(): void
    {
        $cols = array_column(
            Database::get()->query('PRAGMA table_info(trail_ghosts)')->fetchAll(),
            'name'
        );
        foreach (['fingerprint', 'formula', 'evict_count', 'confirm_count', 'fit_last'] as $c) {
            $this->assertContains($c, $cols, "trail_ghosts must have {$c}");
        }
        // UNIQUE(fingerprint, formula): двойная выселка одной формулы
        // инкрементит evict_count, не плодит строки.
        $idx = Database::get()->query('PRAGMA index_list(trail_ghosts)')->fetchAll();
        $hasUnique = false;
        foreach ($idx as $i) {
            if ((int) ($i['unique'] ?? 0) === 1) {
                $hasUnique = true;
            }
        }
        $this->assertTrue($hasUnique, 'trail_ghosts needs a UNIQUE(fingerprint, formula) index');
    }

    public function testMigrateUpgradesOldSchemaInPlace(): void
    {
        // Старая установка (v1.1-канон): таблица без v4-колонок существует
        // до первого get() — migrate обязан досоздать колонки ALTER-ами.
        $tmp = tempnam(sys_get_temp_dir(), 'trailv4_') . '.db';
        try {
            $pdo = new \PDO('sqlite:' . $tmp);
            $pdo->exec("CREATE TABLE trail_marks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                fingerprint TEXT NOT NULL,
                formula TEXT NOT NULL,
                kind TEXT NOT NULL DEFAULT 'uncalibrated',
                corr REAL NOT NULL DEFAULT 0,
                uses INTEGER NOT NULL DEFAULT 0,
                mark_tick INTEGER NOT NULL DEFAULT 0,
                created_at TEXT DEFAULT (datetime('now')),
                UNIQUE(fingerprint, formula)
            )");
            $pdo = null;

            Database::setPath($tmp);
            Database::get(); // triggers migrate on old-schema table

            $cols = self::trailColumns();
            foreach (self::V4_COLUMNS as $c) {
                $this->assertContains($c, $cols, "upgrade must add {$c}");
            }
        } finally {
            Database::setPath(':memory:');
            Database::reset();
            @unlink($tmp);
        }
    }
}
