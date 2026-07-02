<?php
// Auto-generated: __max_ from DIV
require_once '~/.bee_swarm/vendor/autoload.php';
use BeeSwarm\AtomRegistry;
$data = array (
  0 => 
  array (
    0 => 6,
    1 => 2,
    2 => 3,
  ),
  1 => 
  array (
    0 => 12,
    1 => 3,
    2 => 4,
  ),
  2 => 
  array (
    0 => 20,
    1 => 4,
    2 => 5,
  ),
  3 => 
  array (
    0 => 10,
    1 => 2,
    2 => 5,
  ),
);
$X = array_map(fn($r) => array_slice($r, 0, 2), $data);
$y = array_column($data, 2);
$vec = [];
foreach ($X as $row) {
    $a = (float)$row[0]; $b = 2 >= 2 ? (float)$row[1] : null;
    $v = AtomRegistry::apply('/(max)', $a, $b);
    if ($v === null) { echo json_encode(['ok' => false]); exit(1); }
    $vec[] = $v;
}
$cv = AtomRegistry::cv($vec, $y);
echo json_encode(['ok' => $cv < 0.01, 'cv' => $cv, 'atom' => '/(max)']);