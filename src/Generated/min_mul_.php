<?php
// Auto-generated: min_mul_ from AND
require_once '~/.bee_swarm/vendor/autoload.php';
use BeeSwarm\AtomRegistry;
$data = array (
  0 => 
  array (
    0 => 0,
    1 => 0,
    2 => 0,
  ),
  1 => 
  array (
    0 => 0,
    1 => 1,
    2 => 0,
  ),
  2 => 
  array (
    0 => 1,
    1 => 0,
    2 => 0,
  ),
  3 => 
  array (
    0 => 1,
    1 => 1,
    2 => 1,
  ),
);
$X = array_map(fn($r) => array_slice($r, 0, 2), $data);
$y = array_column($data, 2);
$vec = [];
foreach ($X as $row) {
    $a = (float)$row[0]; $b = 2 >= 2 ? (float)$row[1] : null;
    $v = AtomRegistry::apply('min(mul)', $a, $b);
    if ($v === null) { echo json_encode(['ok' => false]); exit(1); }
    $vec[] = $v;
}
$cv = AtomRegistry::cv($vec, $y);
echo json_encode(['ok' => $cv < 0.01, 'cv' => $cv, 'atom' => 'min(mul)']);