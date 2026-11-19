<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
\BeeSwarm\Infra\Database::setPath(':memory:');
$g = new BeeSwarm\Core\Grammar();
$all = $g->all();
echo 'ops в чистой грамматике: ' . count($all) . "\n";
print_r(array_slice($all, 0, 2, true));
