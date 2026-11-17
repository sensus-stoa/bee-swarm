<?php

declare(strict_types=1);

/**
 * EXP045 0h-чеки A/C (флаг owner, до E1):
 * A. Reachability: создаёт ли мутатор F2 M F2 из F2F2M (SWAP/REPLACE перечисление).
 * C. Mutation sampling: 300 случайных мутаций F2F2M — как часто появляется
 *    M-mid (M между полётами) и F2MF2 конкретно.
 */

require __DIR__ . '/../../vendor/autoload.php';

use BeeSwarm\Hive\RouteMutator;
use BeeSwarm\Hive\RouteGenome;

function mkGenome(array $actions): array
{
    return RouteGenome::create(
        'route_' . substr(sha1(json_encode($actions)), 0, 8),
        '',
        0,
        ['kind' => 'ALWAYS'],
        $actions,
        0.30,
        count($actions)
    );
}

function sig(array $g): string
{
    $out = [];
    foreach ($g['actions'] as $a) {
        $out[] = $a['op'] === 'FLIGHT' ? 'F' . $a['depth'] : ($a['op'] === 'USE_MARKS' ? 'M' : 'B');
    }
    return implode('', $out);
}

$f2 = ['op' => 'FLIGHT', 'depth' => 2, 'grammar' => 'base', 'beam' => 'beam10nl'];
$m5 = ['op' => 'USE_MARKS', 'k' => 5];
$base = mkGenome([$f2, $f2, $m5]); // F2F2M

echo "=== A. Enumerative reachability (все одиночные мутации F2F2M) ===\n";
$types = [
    RouteMutator::TYPE_REPLACE,
    RouteMutator::TYPE_SWAP,
    RouteMutator::TYPE_CONDITION,
    RouteMutator::TYPE_BUDGET,
    RouteMutator::TYPE_SHORTEN,
];
$seen = [];
foreach ($types as $type) {
    mt_srand(4200 + $type);
    for ($i = 0; $i < 40; $i++) {
        $child = RouteMutator::mutate($base, $type);
        if ($child === null) {
            continue;
        }
        $seen[$type][sig($child)] = ($seen[$type][sig($child)] ?? 0) + 1;
    }
}
foreach ($seen as $type => $sigs) {
    echo "type $type:\n";
    arsort($sigs);
    foreach ($sigs as $s => $n) {
        echo "    x$n $s\n";
    }
}
$f2mf2 = isset($seen[RouteMutator::TYPE_SWAP]['F2MF2']);
echo "A-VERDICT: F2MF2 достижим заменой одного действия: ", var_export($f2mf2 || isset($seen[RouteMutator::TYPE_REPLACE]['F2MF2']), true), "\n";

echo "\n=== C. Mutation sampling: 300 мутаций F2F2M (смешанные типы) ===\n";
mt_srand(9001);
$counts = ['F2MF2' => 0, 'm_mid' => 0, 'm_any' => 0, 'total' => 0, 'd3_leak' => 0];
for ($i = 0; $i < 300; $i++) {
    $type = $types[array_rand($types)];
    $child = RouteMutator::mutate($base, $type);
    if ($child === null) {
        continue;
    }
    $s = sig($child);
    $counts['total']++;
    if ($s === 'F2MF2') {
        $counts['F2MF2']++;
    }
    $acts = $child['actions'];
    $flIdx = [];
    foreach ($acts as $k => $a) {
        if ($a['op'] === 'FLIGHT') {
            $flIdx[] = $k;
        }
        if ($a['op'] === 'FLIGHT' && ($a['depth'] ?? 0) > 2) {
            $counts['d3_leak']++;
        }
    }
    foreach ($acts as $k => $a) {
        if ($a['op'] !== 'USE_MARKS') {
            continue;
        }
        $before = count(array_filter(array_slice($acts, 0, $k), fn ($x) => $x['op'] === 'FLIGHT'));
        $after = count(array_filter(array_slice($acts, $k + 1), fn ($x) => $x['op'] === 'FLIGHT'));
        if ($before > 0 && $after > 0) {
            $counts['m_mid']++;
            break;
        }
    }
    if (strpos($s, 'M') !== false) {
        $counts['m_any']++;
    }
}
echo "total={$counts['total']} F2MF2_exact={$counts['F2MF2']} m_mid={$counts['m_mid']} m_any={$counts['m_any']} d3_leak={$counts['d3_leak']}\n";
echo "C-VERDICT: M-mid формы в mutation space ", ($counts['m_mid'] > 0 ? 'ЕСТЬ' : 'ОТСУТСТВУЮТ'), "\n";
