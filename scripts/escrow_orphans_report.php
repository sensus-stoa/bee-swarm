<?php

declare(strict_types=1);

/**
 * ESCROW-ORPHAN-DEPOSITS WU-1 (prereg 17.10): отчёт о висячих депозитах.
 *
 * READ-ONLY: PDO sqlite ?mode=ro — скрипт физически не может писать в БД
 * (prereg: «прод-БД читаем ONLY-SELECT»; Database::get() не используется —
 * он запускает DDL/миграции).
 *
 * Использование: php scripts/escrow_orphans_report.php [path/to/swarm.db]
 * Exit: 0 = сирот нет, 2 = сироты найдены, 3 = схемы нет (не «здорово»!),
 * 1 = ошибка открытия/чтения. 0 и 3 НЕ смешивать в автоматизации:
 * count 0 при schema-missing — не признак здоровья (premortem 17.10).
 */

use BeeSwarm\Hive\EscrowOrphanDetector;

require __DIR__ . '/../vendor/autoload.php';

$path = $argv[1] ?? __DIR__ . '/../data/swarm.db';
if (! is_file($path)) {
    fwrite(STDERR, "DB not found: {$path}\n");
    exit(1);
}
$real = (string) realpath($path);
try {
    $pdo = new \PDO('sqlite:' . $real . '?mode=ro', null, null, [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
    ]);
    // WAL-совместимость: живой демон держит -wal/-shm; ro-коннекция без
    // busy_timeout ловит SQLITE_BUSY посреди SELECTа (premortem 17.10).
    $pdo->exec('PRAGMA busy_timeout = 5000');
} catch (\PDOException $e) {
    fwrite(STDERR, 'DB open failed (read-only): ' . $e->getMessage() . "\n");
    exit(1);
}

// Схема-гвард: БД старше V0.14 (10.09) не содержит law_escrow — сирот нет
// по построению (депонирование невозможно). Честный отчёт вместо фатала.
$tables = $pdo->query(
    "SELECT name FROM sqlite_master WHERE type = 'table'
     AND name IN ('law_escrow', 'verification_tasks')"
)->fetchAll(\PDO::FETCH_COLUMN);
printf("ESCROW ORPHANS (holding без V-задач) — %s\n", date('Y-m-d H:i:s'));
printf("DB: %s\n", $real);
if (! in_array('law_escrow', $tables, true) || ! in_array('verification_tasks', $tables, true)) {
    printf("schema: tables в БД %d; law_escrow=%s verification_tasks=%s — escrow-схемы нет, сирот нет по построению (БД не инициализирована текущим движком)\n",
        count($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll()),
        in_array('law_escrow', $tables, true) ? 'yes' : 'MISSING',
        in_array('verification_tasks', $tables, true) ? 'yes' : 'MISSING');
    printf("count: 0\n");
    exit(3); // schema-missing ≠ здорово (dual-review 17.10); 0 — только чистая полная схема
}

$orphans = (new EscrowOrphanDetector($pdo))->orphans();

printf("ESCROW ORPHANS (holding без V-задач) — %s\n", date('Y-m-d H:i:s'));
printf("DB: %s\n", $real);
printf("count: %d\n", count($orphans));
$total = 0.0;
foreach ($orphans as $o) {
    $total += $o['amount'];
    printf(
        "  [%s] %s amount=%.4f carrier=%s updated=%s\n",
        $o['domain'],
        $o['law_formula'],
        $o['amount'],
        $o['carrier'] === '' ? '-' : $o['carrier'],
        $o['updated_at']
    );
}
printf("total amount: %.4f\n", $total);

exit($orphans === [] ? 0 : 2);
