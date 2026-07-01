<?php
declare(strict_types=1);

namespace BeeSwarm;

use PDO;

class Database
{
    private static ?PDO $instance = null;
    
    public static function get(): PDO
    {
        if (self::$instance === null) {
            $path = __DIR__ . '/../data/swarm.db';
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
        $db->exec("CREATE TABLE IF NOT EXISTS laws (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT UNIQUE NOT NULL,
            formula TEXT,
            cv REAL,
            domain TEXT DEFAULT 'unknown',
            found_at TEXT DEFAULT (datetime('now'))
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS grammar_ops (
            name TEXT PRIMARY KEY,
            source TEXT DEFAULT 'base',
            created_at TEXT DEFAULT (datetime('now'))
        )");
        // All other tables created by their respective modules:
        // knowledge_graph → SelfLearningBee
        // hive_state → PersistentHive
        // action_pool → SelfFeedingGenerator
        // conscious_state/events → ConsciousBee
        // data_requests → agenda.php
        // coalitions → ParadigmSwarm
    }
}
