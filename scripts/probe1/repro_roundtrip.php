<?php

// MOL-001: воспроизведение roundtrip-дефекта (до фикса).
// Фиксирует: AST молекулы, render, повторный parse, связанные/свободные
// переменные, вычисление исходного закона и сохранённого T₂.

require __DIR__ . '/../../vendor/autoload.php';

$runDir = $argv[1] ?? '/tmp/probe1_run';
$g1 = json_decode(file_get_contents("{$runDir}/g1_r1.json"), true);
$g2 = json_decode(file_get_contents("{$runDir}/g2_B_r1.json"), true);
$t1 = $g1['atoms'][0];
$t2 = $g2['T2'];
$law = $g2['laws'][0]['formula']; // исходный молекулярный закон (labels cN)

echo "=== MOL-001 REPRODUCTION ===\n";
echo "T1 name: {$t1['name']}\nT1 def:  {$t1['definition']}\n";
echo "T2 name: {$t2['name']}\nT2 def:  {$t2['definition']}\n";
echo "law:     {$law}\n\n";

// 1. AST молекулярного закона (как его видит нормалайзер)
// birthNames = имена атомов эксперимента (не из БД — repro вне БД-контекста)
$birthNames = [$t1['name'], $t2['name']];
echo 'birthNames: ' . json_encode($birthNames) . "\n\n";

// 2. parse/render roundtrip T₂ definition
$defX = str_replace(['c0', 'c1', 'c2'], ['x0', 'x1', 'x2'], $t2['definition']);
echo "T2 def (x-space): {$defX}\n";
$ast1 = BeeSwarm\Core\ExpressionNormalizer::parse($defX, $birthNames);
echo 'AST(parse#1): ' . json_encode($ast1) . "\n";
$rendered = BeeSwarm\Core\ExpressionNormalizer::render($ast1);
echo "render(AST): {$rendered}\n";
$ast2 = BeeSwarm\Core\ExpressionNormalizer::parse($rendered, $birthNames);
echo 'AST(parse#2): ' . json_encode($ast2) . "\n";
echo 'R1 roundtrip structural: ' . (json_encode($ast1) === json_encode($ast2) ? 'PASS' : 'FAIL') . "\n\n";

// 3. связанные/свободные переменные (терминалы дерева)
$collect = function (array|string $node) use (&$collect): array {
    $out = [];
    if (is_string($node)) {
        return [$node];
    }
    foreach (['l', 'r', 'arg'] as $k) {
        if (isset($node[$k]) && is_array($node[$k])) {
            $out = array_merge($out, $collect($node[$k]));
        }
    }
    if (isset($node['atom'])) {
        $out[] = $node['atom'];
    }
    return array_values(array_unique($out));
};
$vars = $collect(is_array($ast1) ? $ast1 : []);
echo 'переменные T2 (все терминалы): ' . json_encode($vars) . "\n";
$glued = array_filter($vars, fn ($v) => preg_match('/^(x\d+)?BW[0-9a-f]{32}/', $v) === 1);
echo 'СКЛЕЕННЫЕ атомы (дефект): ' . json_encode(array_values($glued)) . "\n\n";

// 4. вычисление: исходный закон vs сохранённый T₂ на независимых входах
mt_srand(4242);
$X = [];
$lawVals = [];
for ($i = 0; $i < 12; $i++) {
    $c = [mt_rand(10, 30) / 10, mt_rand(40, 60) / 10, mt_rand(30, 70) / 10, mt_rand(5, 20) / 10, mt_rand(0, 90) / 10];
    $X[] = $c;
    $lawVals[] = ($c[0] + $c[1]) / ($c[0] - $c[1]) + ($c[0] - $c[2]);
}
$lawX = BeeSwarm\Core\ExpressionEvaluator::evaluateFormula(str_replace(['c0', 'c1', 'c2'], ['x0', 'x1', 'x2'], $law), $X);
$t2X = BeeSwarm\Core\ExpressionEvaluator::evaluateFormula(str_replace(['c0', 'c1', 'c2'], ['x0', 'x1', 'x2'], $t2['definition']), $X);
echo 'исходный закон eval: ' . json_encode($lawX) . "\n";
echo 'T₂ def eval:         ' . json_encode($t2X) . "\n";
