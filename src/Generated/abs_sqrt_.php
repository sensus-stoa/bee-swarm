<?php
// Auto-generated: abs_sqrt_ from SQRT
require_once '~/.bee_swarm/vendor/autoload.php';
use BeeSwarm\Core\AtomRegistry;
$data = array (
  0 => 
  array (
    0 => 0,
    1 => 0,
  ),
  1 => 
  array (
    0 => 1,
    1 => 1,
  ),
  2 => 
  array (
    0 => 4,
    1 => 2,
  ),
  3 => 
  array (
    0 => 9,
    1 => 3,
  ),
  4 => 
  array (
    0 => 16,
    1 => 4,
  ),
);
$X = array_map(fn($r) => array_slice($r, 0, 1), $data);
$y = array_column($data, 1);
$vec = [];
foreach ($X as $row) {
    $a = (float)$row[0]; $b = 1 >= 2 ? (float)$row[1] : null;
    $v = AtomRegistry::apply('abs(sqrt)', $a, $b);
    if ($v === null) { echo json_encode(['ok' => false]); exit(1); }
    $vec[] = $v;
}
$cv = AtomRegistry::cv($vec, $y);
echo json_encode(['ok' => $cv < 0.01, 'cv' => $cv, 'atom' => 'abs(sqrt)']);