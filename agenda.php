<?php
// ~/.bee_swarm/agenda.php v5 — Hive
date_default_timezone_set('Europe/Moscow');
require_once __DIR__ . '/vendor/autoload.php';

(new BeeSwarm\Hive\Hive())->run();
