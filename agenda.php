<?php
// ~/.bee_swarm/agenda.php v5 — Hive
date_default_timezone_set('Europe/Moscow');

// Error reporting: всё в stderr + лог-файл
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/logs/php_errors.log');
ini_set('memory_limit', '8G');

require_once __DIR__ . '/vendor/autoload.php';

(new BeeSwarm\Hive\Hive())->run();
