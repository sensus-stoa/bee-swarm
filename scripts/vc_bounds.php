<?php
declare(strict_types=1);

/**
 * VC-BOUNDS: мощность класса выражений и верхняя граница VCdim.
 * Стори: VC-BOUNDS
 *
 * Класс выражений: L0 (фичи) + L1 (попарные бинарные + унарные) + L2 (пары L1).
 * Для конечного класса: VCdim ≤ log₂(N).
 */

// Бинарные операции в грамматике
$binaryOps = ['+', '×', '−', '/', 'min', 'max'];
// Унарные операции
$unaryOps = ['sq', 'abs', 'neg', 'inv', 'sqrt', 'log2', 'parity'];

function combinations(int $n, int $k): int
{
    if ($k < 0 || $k > $n) return 0;
    $r = 1;
    for ($i = 0; $i < $k; $i++) {
        $r = (int) ($r * ($n - $i) / ($i + 1));
    }
    return $r;
}

echo "=== Мощность класса выражений ===\n";
echo str_repeat('-', 60) . "\n";

foreach ([2, 3, 7, 12] as $nFeat) {
    // L0: фичи + квадраты + константы K1,K2
    $l0 = $nFeat * 2 + 2;

    // L1: попарные бинарные (C(n,2) пар × |binOps|) + унарные (n × |unaryOps|)
    $pairs = combinations($nFeat, 2);
    $l1Bin = $pairs * count($binaryOps);
    $l1Unary = $nFeat * count($unaryOps);
    $l1 = $l1Bin + $l1Unary;

    // L2: пары L1 (C(L1,2) × |binOps|) — только верхняя граница
    $l2 = combinations(min($l1, 200), 2) * count($binaryOps);

    $total = $l0 + $l1 + $l2;
    $vcBound = (int) floor(log($total, 2));

    printf("nFeat=%2d | L0=%5d | L1=%6d | L2≈%8d | N_total≈%9d | VCdim≤%5d\n",
        $nFeat, $l0, $l1, $l2, $total, $vcBound);
}

echo str_repeat('-', 60) . "\n";

// Sample complexity (PAC): m ≥ (VCdim + ln(1/δ)) / ε
echo "\n=== Sample complexity (PAC) ===\n";
$eps = 0.01; // ε_train
$delta = 0.05;
foreach ([2, 3, 7, 12] as $nFeat) {
    $pairs = combinations($nFeat, 2);
    $l1 = $pairs * count($binaryOps) + $nFeat * count($unaryOps);
    $total = $nFeat * 2 + 2 + $l1;
    $vc = (int) floor(log($total, 2));
    $m = (int) ceil(($vc + log(1 / $delta, 2)) / $eps);
    printf("nFeat=%2d: VCdim≤%d → m_min(ε=0.01, δ=0.05) ≈ %d точек\n", $nFeat, $vc, $m);
}

echo "\nТекущий эмпирический t_min = max(10, nFeat*5):\n";
foreach ([2, 3, 7, 12] as $nFeat) {
    printf("  nFeat=%2d → t_min=%d\n", $nFeat, max(10, $nFeat * 5));
}
