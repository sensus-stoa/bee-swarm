<?php
// Auto-generated: sqrt from SQRT
// Generated: ' . date('Y-m-d H:i:s') . '

require_once '~/.bee_swarm/vendor/autoload.php';
use BeeSwarm\AtomRegistry;

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
$nFeat = 1;
$X = array_map(fn($r) => array_slice($r, 0, $nFeat), $data);
$y = array_column($data, $nFeat);

$vec = [];
foreach ($X as $row) {
    $a = (float)$row[0];
    $b = $nFeat >= 2 ? (float)$row[1] : null;
    $v = AtomRegistry::apply('sqrt', $a, $b);
    if ($v === null) { echo json_encode(['ok' => false]); exit(1); }
    $vec[] = $v;
}

$cv = AtomRegistry::cv($vec, $y);
echo json_encode(['ok' => $cv < 0.01, 'cv' => $cv, 'atom' => 'sqrt']);