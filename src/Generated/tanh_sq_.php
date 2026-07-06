<?php
// Auto-generated: tanh_sq_ from foraged_sem_GitHub - janvarevIrene-Voice-Assistant Ирина - русский голосовой ассистент для работы оффлайн. Поддерживает скиллы через плагины..md
require_once '~/.bee_swarm/vendor/autoload.php';
use BeeSwarm\Core\AtomRegistry;
$data = array (
  0 => 
  array (
    0 => 4.0,
    1 => 0.0,
    2 => 1.0,
  ),
  1 => 
  array (
    0 => 9.0,
    1 => 9.0,
    2 => 1.0,
  ),
);
$X = array_map(fn($r) => array_slice($r, 0, 2), $data);
$y = array_column($data, 2);
$vec = [];
foreach ($X as $row) {
    $a = (float)$row[0]; $b = 2 >= 2 ? (float)$row[1] : null;
    $v = AtomRegistry::apply('tanh(sq)', $a, $b);
    if ($v === null) { echo json_encode(['ok' => false]); exit(1); }
    $vec[] = $v;
}
$cv = AtomRegistry::cv($vec, $y);
echo json_encode(['ok' => $cv < 0.01, 'cv' => $cv, 'atom' => 'tanh(sq)']);