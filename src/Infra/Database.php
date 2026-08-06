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
        source_path TEXT DEFAULT '',
        content_sample TEXT DEFAULT '',
        col_labels TEXT DEFAULT '[]',
        law_class TEXT DEFAULT 'EMPIRICAL',
        found_at TEXT DEFAULT (datetime('now')),
        usage_count INTEGER DEFAULT 1,
        UNIQUE(formula, domain)
    )";

    public static function setPath(string $path): void
    {
        self::$forcedPath = $path;
        self::$instance = null;  // force reconnect
    }

    public static function reset(): void
    {
        // CONCERNS (deleg_bc5b6d02): сброс кэшей definition (GRAMMAR-BIRTH)
        \BeeSwarm\Core\ExpressionEvaluator::clearDefCache();
        \BeeSwarm\Core\AtomRegistry::clearDefCache();
        self::$instance = null;
    }

    public static function get(): PDO
    {
        if (self::$instance === null) {
            $path = self::$forcedPath
                ?? getenv('SWARM_DB_PATH')
                ?: __DIR__ . '/../../data/swarm.db';
            self::$instance = new PDO("sqlite:{$path}", null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::$instance->exec('PRAGMA journal_mode=WAL');
            self::$instance->exec('PRAGMA synchronous=NORMAL');
            self::migrate();
        }
        return self::$instance;
    }

    private static function migrate(): void
    {
        $db = self::$instance;
        $db->exec(sprintf(self::LAWS_DDL, 'laws'));
        // S1.11: Add source_path + content_sample to existing laws table
        try {
            $db->exec('ALTER TABLE laws ADD COLUMN source_path TEXT DEFAULT \'\'');
        } catch (\PDOException) {
            // Column already exists — ok
        }
        try {
            $db->exec('ALTER TABLE laws ADD COLUMN content_sample TEXT DEFAULT \'\'');
        } catch (\PDOException) {
            // Column already exists — ok
        }
        try {
            $db->exec('ALTER TABLE laws ADD COLUMN col_labels TEXT DEFAULT \'[]\'');
        } catch (\PDOException) {
            // Column already exists — ok
        }
        try {
            $db->exec('ALTER TABLE laws ADD COLUMN law_class TEXT DEFAULT \'EMPIRICAL\'');
        } catch (\PDOException) {
            // Column already exists — ok
        }
        // Migration: remove old name-only UNIQUE if exists (SQLite 3.35+)
        try {
            $cols = $db->query('PRAGMA index_list(laws)')
                ->fetchAll(\PDO::FETCH_ASSOC);
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
                $db->exec('INSERT OR IGNORE INTO laws_migrated SELECT * FROM laws');
                $db->exec('DROP TABLE laws');
                $db->exec('ALTER TABLE laws_migrated RENAME TO laws');
            }
        } catch (\PDOException $e) {
            // Migration already done or table doesn't exist — ok
        }
        // FORMAL-LAYER Ф1 (05.08): бэкфилл — нормализовать существующие формулы
        try {
            $rows = $db->query('SELECT id, formula FROM laws')->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                if ($row['formula'] === null || $row['formula'] === '') {
                    continue;
                }
                $canon = \BeeSwarm\Core\ExpressionNormalizer::normalize($row['formula']);
                if ($canon !== $row['formula']) {
                    $stmt = $db->prepare('UPDATE laws SET formula = ? WHERE id = ?');
                    $stmt->execute([$canon, $row['id']]);
                }
            }
        } catch (\PDOException $e) {
            // laws table doesn't exist yet — ok
        }
        // FORMAL-LAYER Ф1 (05.08): UNIQUE (name,formula,domain) → (formula,domain).
        // Формула — сущность дедупликации; name задачи вторичен (CONCERNS Ф1).
        try {
            $cols = $db->query('PRAGMA index_list(laws)')->fetchAll(\PDO::FETCH_ASSOC);
            $hasComposite = false;
            foreach ($cols as $col) {
                if (str_contains($col['name'] ?? '', 'sqlite_autoindex_laws') && ($col['unique'] ?? 0)) {
                    $info = $db->query("PRAGMA index_info({$col['name']})")->fetchAll(\PDO::FETCH_ASSOC);
                    $indexedCols = array_column($info, 'name');
                    if ($indexedCols === ['name', 'formula', 'domain']) {
                        $hasComposite = true;
                        break;
                    }
                }
            }
            if ($hasComposite) {
                $db->exec(sprintf(self::LAWS_DDL, 'laws_migrated2'));
                $db->exec(
                    "INSERT OR IGNORE INTO laws_migrated2 (name,formula,cv,domain,source_path,content_sample,col_labels,law_class,found_at)
                     SELECT name,formula,cv,domain,source_path,content_sample,col_labels,law_class,found_at FROM laws"
                );
                $db->exec('DROP TABLE laws');
                $db->exec('ALTER TABLE laws_migrated2 RENAME TO laws');
            }
        } catch (\PDOException $e) {
            // already migrated or table missing — ok
        }
        // Ф1: usage_count для существующих БД (добавлен в DDL, но старые таблицы
        // пересоздаются только при миграции UNIQUE — здесь ALTER-фолбэк)
        try {
            $db->exec('ALTER TABLE laws ADD COLUMN usage_count INTEGER DEFAULT 1');
        } catch (\PDOException $e) {
            // column already exists — ok
        }
        // GRAMMAR-PROPAGATION (ЭКСП-012): вес оператора в grammar_ops
        try {
            $db->exec('ALTER TABLE grammar_ops ADD COLUMN usage_count INTEGER DEFAULT 1');
        } catch (\PDOException $e) {
            // column already exists — ok
        }
        $db->exec("CREATE TABLE IF NOT EXISTS grammar_ops (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            source TEXT DEFAULT 'base',
            invented_at TEXT DEFAULT (datetime('now')),
            definition TEXT,
            usage_count INTEGER DEFAULT 1
        )");
        // B1-DBDEDUP (05.08): дедуп существующих — keep first по id
        try {
            $db->exec(
                'DELETE FROM grammar_ops WHERE id NOT IN (SELECT MIN(id) FROM grammar_ops GROUP BY name)'
            );
            // Единый путь UNIQUE — явный индекс (старая и новая БД одинаковы)
            $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_grammar_ops_name ON grammar_ops(name)');
        } catch (\PDOException $e) {
            // table empty or missing — ok
        }
        // REUSE-TRACKING (06.08): домен рождения + reuse (ПОСЛЕ CREATE TABLE!)
        try {
            $db->exec('ALTER TABLE grammar_ops ADD COLUMN birth_domain TEXT DEFAULT \'\'');
        } catch (\PDOException $e) {}
        try {
            $db->exec('ALTER TABLE grammar_ops ADD COLUMN reuse_count INTEGER DEFAULT 0');
        } catch (\PDOException $e) {}
        try {
            $db->exec('ALTER TABLE grammar_ops ADD COLUMN reuse_domains TEXT DEFAULT \'[]\'');
        } catch (\PDOException $e) {}
        // POPULATION-PERSISTENCE (06.08, P0): сохранение пчёл при перезапуске
        $db->exec("CREATE TABLE IF NOT EXISTS bee_persistence (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            grammar TEXT NOT NULL,
            energy REAL NOT NULL DEFAULT 10.0,
            is_alive INTEGER NOT NULL DEFAULT 1,
            tick_cost REAL NOT NULL DEFAULT -0.01,
            search_cost REAL NOT NULL DEFAULT -0.1,
            discovery_reward REAL NOT NULL DEFAULT 2.0,
            info_reward REAL NOT NULL DEFAULT 0.5,
            custom_ops TEXT NOT NULL DEFAULT '[]'
        )");
        // CONCERNS (deleg_0f9d1b7f): ALTER для существующих БД
        try { $db->exec('ALTER TABLE bee_persistence ADD COLUMN tick_cost REAL NOT NULL DEFAULT -0.01'); } catch (\PDOException $e) {}
        try { $db->exec('ALTER TABLE bee_persistence ADD COLUMN search_cost REAL NOT NULL DEFAULT -0.1'); } catch (\PDOException $e) {}
        try { $db->exec('ALTER TABLE bee_persistence ADD COLUMN discovery_reward REAL NOT NULL DEFAULT 2.0'); } catch (\PDOException $e) {}
        try { $db->exec('ALTER TABLE bee_persistence ADD COLUMN info_reward REAL NOT NULL DEFAULT 0.5'); } catch (\PDOException $e) {}
        try { $db->exec("ALTER TABLE bee_persistence ADD COLUMN custom_ops TEXT NOT NULL DEFAULT '[]'"); } catch (\PDOException $e) {}

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

        // §1.8: Overlap Awareness — pairwise bee answer comparison
        $db->exec("CREATE TABLE IF NOT EXISTS overlap_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            bee_a TEXT NOT NULL,
            bee_b TEXT NOT NULL,
            task TEXT NOT NULL,
            answer_a TEXT NOT NULL,
            answer_b TEXT NOT NULL,
            matched INTEGER DEFAULT 0,
            created_at TEXT DEFAULT (datetime('now'))
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_overlap_pair ON overlap_log(bee_a, bee_b)");
    }
}
