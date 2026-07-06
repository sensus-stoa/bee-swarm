<?php
// Auto-generated: tanh_or_ from foraged_sem_Интервью Дерипаски.md
require_once '~/.bee_swarm/vendor/autoload.php';
use BeeSwarm\Core\AtomRegistry;
$data = array (
  0 => 
  array (
    0 => 9.0,
    1 => 3.0,
    2 => 1.0,
  ),
  1 => 
  array (
    0 => 0.0,
    1 => 4.0,
    2 => 1.0,
  ),
  2 => 
  array (
    0 => 5.0,
    1 => 5.0,
    2 => 1.0,
  ),
  3 => 
  array (
    0 => 1.0,
    1 => 1.0,
    2 => 1.0,
  ),
  4 => 
  array (
    0 => 0.0,
    1 => 6.0,
    2 => 1.0,
  ),
  5 => 
  array (
    0 => 2.0,
    1 => 0.0,
    2 => 1.0,
  ),
  6 => 
  array (
    0 => 2.0,
    1 => 7.0,
    2 => 1.0,
  ),
  7 => 
  array (
    0 => 5.0,
    1 => 3.0,
    2 => 1.0,
  ),
  8 => 
  array (
    0 => 6.0,
    1 => 2.0,
    2 => 1.0,
  ),
);
$X = array_map(fn($r) => array_slice($r, 0, 2), $data);
$y = array_column($data, 2);
$vec = [];
foreach ($X as $row) {
    $a = (float)$row[0]; $b = 2 >= 2 ? (float)$row[1] : null;
    $v = AtomRegistry::apply('tanh(or)', $a, $b);
    if ($v === null) { echo json_encode(['ok' => false]); exit(1); }
    $vec[] = $v;
}
$cv = AtomRegistry::cv($vec, $y);
echo json_encode(['ok' => $cv < 0.01, 'cv' => $cv, 'atom' => 'tanh(or)']);