<?php

// PROBE-1: компрессор в свежем процессе.
// Причина: ин-процессный compress в драйвере (после 6+ сабпроцесс-полётов)
// не рождает атом при идентичном состоянии БД, тогда как свежий процесс
// рождает детерминированно (воспроизведено 15.10). Подозрение — статический
// кэш движка в длинноживущем процессе; разбор — вне PROBE-1 (ядро не трогаем).
// Harness-решение: компрессор = subprocess, как и полёты.

$dbPath = $argv[1] ?? '';
if ($dbPath === '') {
    fwrite(STDERR, "usage: compress.php <db.sqlite>\n");
    exit(2);
}
putenv('SWARM_DB_PATH=' . $dbPath);
require __DIR__ . '/../../vendor/autoload.php';
$born = (new BeeSwarm\Hive\LawIsomorphismCompressor())->compress(null);
echo (string) $born;
