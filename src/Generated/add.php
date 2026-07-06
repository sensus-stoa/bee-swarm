<?php
// Auto-generated: add from ADD
// Generated: ' . date('Y-m-d H:i:s') . '

require_once '~/.bee_swarm/vendor/autoload.php';
use BeeSwarm\Core\AtomRegistry;

$data = array (
  0 => 
  array (
    0 => 1,
    1 => 2,
    2 => 3,
  ),
  1 => 
  array (
    0 => 3,
    1 => 4,
    2 => 7,
  ),
  2 => 
  array (
    0 => 5,
    1 => 6,
    2 => 11,
  ),
);
$nFeat = 2;
$X = array_map(fn($r) => array_slice($r, 0, $nFeat), $data);
$y = array_column($data, $nFeat);

$vec = [];
foreach ($X as $row) {
    $a = (float)$row[0];
    $b = $nFeat >= 2 ? (float)$row[1] : null;
    $v = AtomRegistry::apply('add', $a, $b);
    if ($v === null) { echo json_encode(['ok' => false]); exit(1); }
    $vec[] = $v;
}

$cv = AtomRegistry::cv($vec, $y);
echo json_encode(['ok' => $cv < 0.01, 'cv' => $cv, 'atom' => 'add']);