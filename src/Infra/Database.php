<?php

declare(strict_types=1);

namespace BeeSwarm\Infra;

use PDO;

class Database
{
    private static ?PDO $instance = null;
    private static ?string $forcedPath = null;

    private const LAWS_DDL = "CREATE TABLE IF NOT EXISTS %s (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        formula TEXT,
        cv REAL,
        domain TEXT DEFAULT 'unknown',
        found_at TEXT DEFAULT (datetime('now')),
        UNIQUE(name, formula)
    )";

    public static function setPath(string $path): void
    {
        self::$forcedPath = $path;
        self::$instance = null;  // force reconnect
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public static function get(): PDO
    {
        if (self::$instance === null) {
            $path = self::$forcedPath
                ?? getenv('SWARM_DB_PATH')
                ?: __DIR__ . '/../../data/swarm.db';
            self::$instance = new PDO("sqlite:$path", null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::$instance->exec("PRAGMA journal_mode=WAL");
            self::$instance->exec("PRAGMA synchronous=NORMAL");
            self::migrate();
        }
        return self::$instance;
    }

    private static function migrate(): void
    {
        $db = self::$instance;
        $db->exec(sprintf(self::LAWS_DDL, 'laws'));
        // Migration: remove old name-only UNIQUE if exists (SQLite 3.35+)
        try {
            $cols = $db->query("PRAGMA index_list(laws)")->fetchAll(\PDO::FETCH_ASSOC);
            $hasOldUnique = false;
            foreach ($cols as $col) {
                if (str_contains($col['name'] ?? '', 'sqlite_autoindex_laws') && ($col['unique'] ?? 0)) {
                    $info = $db->query("PRAGMA index_info({$col['name']})")->fetchAll(\PDO::FETCH_ASSOC);
                    $indexedCols = array_column($info, 'name');
                    if ($indexedCols === ['name']) {
                        $hasOldUnique = true;
                        break;
                    }
                }
            }
            if ($hasOldUnique) {
                // Recreate without old unique, with new composite unique
                $db->exec(sprintf(self::LAWS_DDL, 'laws_migrated'));
                $db->exec("INSERT OR IGNORE INTO laws_migrated SELECT * FROM laws");
                $db->exec("DROP TABLE laws");
                $db->exec("ALTER TABLE laws_migrated RENAME TO laws");
            }
        } catch (\PDOException $e) {
            // Migration already done or table doesn't exist — ok
        }
        $db->exec("CREATE TABLE IF NOT EXISTS grammar_ops (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            source TEXT DEFAULT 'base',
            invented_at TEXT DEFAULT (datetime('now')),
            definition TEXT
        )");
        // Все таблицы создаются здесь — не в модулях.
        // Это гарантирует, что тестовая БД и production БД идентичны.
        $db->exec("CREATE TABLE IF NOT EXISTS knowledge_graph (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subject TEXT NOT NULL,
            predicate TEXT NOT NULL,
            object TEXT NOT NULL,
            confidence REAL DEFAULT 1.0,
            inferred INTEGER DEFAULT 0,
            created_at TEXT DEFAULT (datetime('now')),
            UNIQUE(subject, predicate, object)
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS hive_state (
            key TEXT PRIMARY KEY,
            value TEXT
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS action_pool (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT,
            code_hash TEXT,
            success_count INTEGER DEFAULT 0,
            avg_cv REAL,
            source TEXT,
            created_at TEXT DEFAULT (datetime('now')),
            last_success_at TEXT
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS conscious_state (
            key TEXT PRIMARY KEY,
            value TEXT
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS conscious_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event TEXT,
            d_energy REAL,
            d_curiosity REAL,
            d_virtue REAL,
            d_focus REAL,
            energy_after REAL,
            virtue_after REAL,
            mood TEXT,
            created_at TEXT DEFAULT (datetime('now'))
        )");
    }
}
