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
        $db->exec("CREATE TABLE IF NOT EXISTS tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT UNIQUE NOT NULL,
            data_json TEXT NOT NULL,
            domain TEXT DEFAULT 'unknown',
            solved INTEGER DEFAULT 0,
            created_at TEXT DEFAULT (datetime('now'))
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS grammar_ops (
            name TEXT PRIMARY KEY,
            source TEXT DEFAULT 'base',
            created_at TEXT DEFAULT (datetime('now'))
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS state (
            key TEXT PRIMARY KEY,
            value TEXT
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS bee_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            bee_name TEXT,
            energy REAL DEFAULT 1.0,
            curiosity REAL DEFAULT 0.8,
            virtue REAL DEFAULT 1.0,
            event TEXT,
            created_at TEXT DEFAULT (datetime('now'))
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS waggle_dance (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            bee_name TEXT NOT NULL,
            task_name TEXT NOT NULL,
            formula TEXT,
            cv REAL,
            strategy_used TEXT,
            timestamp TEXT DEFAULT (datetime('now'))
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS hive_knowledge (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            type TEXT NOT NULL,
            content TEXT NOT NULL,
            source_bee TEXT,
            confirmed_by TEXT DEFAULT '',
            confidence REAL DEFAULT 1.0,
            created_at TEXT DEFAULT (datetime('now'))
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS coalition (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_name TEXT NOT NULL,
            bees_involved TEXT NOT NULL,
            formulas_found TEXT,
            resolved_formula TEXT,
            fidelity REAL,
            created_at TEXT DEFAULT (datetime('now'))
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS paradigms (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT UNIQUE NOT NULL,
            domain TEXT,
            grammar_ops TEXT,
            spawned_from TEXT,
            first_seen TEXT DEFAULT (datetime('now'))
        )");
        
        // Seed base grammar
        $count = $db->query("SELECT COUNT(*) FROM grammar_ops")->fetchColumn();
        if ($count == 0) {
            foreach (Grammar::BASE_OPS as $name => $fn) {
                $db->prepare("INSERT OR IGNORE INTO grammar_ops (name, source) VALUES (?, 'base')")
                   ->execute([$name]);
            }
        }
    }
}
