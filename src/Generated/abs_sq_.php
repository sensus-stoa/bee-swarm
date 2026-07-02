<?php
// Auto-generated: abs_sq_ from SQUARE
require_once '~/.bee_swarm/vendor/autoload.php';
use BeeSwarm\AtomRegistry;
$data = array (
  0 => 
  array (
    0 => 1,
    1 => 1,
  ),
  1 => 
  array (
    0 => 2,
    1 => 4,
  ),
  2 => 
  array (
    0 => 3,
    1 => 9,
  ),
  3 => 
  array (
    0 => 4,
    1 => 16,
  ),
  4 => 
  array (
    0 => 5,
    1 => 25,
  ),
);
$X = array_map(fn($r) => array_slice($r, 0, 1), $data);
$y = array_column($data, 1);
$vec = [];
foreach ($X as $row) {
    $a = (float)$row[0]; $b = 1 >= 2 ? (float)$row[1] : null;
    $v = AtomRegistry::apply('abs(sq)', $a, $b);
    if ($v === null) { echo json_encode(['ok' => false]); exit(1); }
    $vec[] = $v;
}
$cv = AtomRegistry::cv($vec, $y);
echo json_encode(['ok' => $cv < 0.01, 'cv' => $cv, 'atom' => 'abs(sq)']);