<?php

declare(strict_types=1);

/** Smoke-фикстура WU-6: сеять 3 закона + escrow + tasks в temp-БД, потом дашборд читает. */

require __DIR__ . '/../vendor/autoload.php';

use BeeSwarm\Infra\Database;

$dbPath = $argv[1] ?? '/tmp/v14_dash_smoke.db';
@unlink($dbPath);
Database::setPath($dbPath);
Database::get();

$db = Database::get();
$db->exec("INSERT INTO laws (name, formula, cv, domain, escrow_status, usage_count, confirmed_count)
    VALUES ('law_a', '(K2×x0)', 0.001, 'dom1', 'PAID', 7, 5),
           ('law_b', '(x0+x1)×K1', 0.02, 'dom1', 'UNSTABLE', 4, 0),
           ('law_c', 'sqrt(x0)', 0.1, 'dom2', '', 1, 0)");
$db->exec("INSERT INTO law_escrow (law_formula, domain, amount, carrier, status) VALUES
    ('(K2×x0)', 'dom1', 2.1, 'bee#2', 'paid'),
    ('(x0+x1)×K1', 'dom1', 1.5, 'bee#0', 'burned'),
    ('(K3×x2)', 'dom2', 0.9, 'bee#1', 'holding')");
$db->exec("INSERT INTO verification_tasks (law_formula, law_shape, kind, domain, status, created_at) VALUES
    ('(K2×x0)', 'C×*', 'resample_1', 'dom1', 'confirmed', datetime('now', '-1 hour')),
    ('(x0+x1)×K1', '(C+*)×C', 'resample_1', 'dom1', 'refuted', datetime('now', '-30 hour')),
    ('(K3×x2)', 'C×*', 'inverted', 'dom2', 'pending', datetime('now', '-2 hour'))");
$db->exec("INSERT INTO atom_penalties (atom, penalty_count) VALUES ('×', 4), ('+', 1)");
$db->exec("INSERT INTO generation_snapshots (gen, diversity, avg_g, unique_grammars, alive, timestamp)
    VALUES (3, 0.42, 5.1, 8, 12, datetime('now'))");
echo "SEEDED {$dbPath}\n";
