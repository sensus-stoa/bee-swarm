<?php
// Auto-generated: ___min_ from MIN_MUL
require_once '~/.bee_swarm/vendor/autoload.php';
use BeeSwarm\Core\AtomRegistry;
$data = array (
  0 => 
  array (
    0 => 2,
    1 => 5,
    2 => 3,
    3 => 6,
  ),
  1 => 
  array (
    0 => 3,
    1 => 1,
    2 => 2,
    3 => 2,
  ),
  2 => 
  array (
    0 => 4,
    1 => 4,
    2 => 1,
    3 => 4,
  ),
);
$X = array_map(fn($r) => array_slice($r, 0, 3), $data);
$y = array_column($data, 3);
$vec = [];
foreach ($X as $row) {
    $a = (float)$row[0]; $b = 3 >= 2 ? (float)$row[1] : null;
    $v = AtomRegistry::apply('×(min)', $a, $b);
    if ($v === null) { echo json_encode(['ok' => false]); exit(1); }
    $vec[] = $v;
}
$cv = AtomRegistry::cv($vec, $y);
echo json_encode(['ok' => $cv < 0.01, 'cv' => $cv, 'atom' => '×(min)']);