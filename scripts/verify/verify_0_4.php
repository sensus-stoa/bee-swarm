#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * verify_0_4.php — Non-Triviality (§1.4)
 *
 * Дух критерия: закон не должен быть тавтологией или тривиальной редукцией.
 *
 * Проверка: pattern-based (без данных — полный isTrivial требует X,y).
 * Паттерны из AtomRegistry::isTrivial():
 *   - Одиночная переменная: x0, x1...
 *   - Константа: K1, K2.5...
 *   - Тождества: +(x,0), ×(x,1), −(x,0), /(x,1)
 *   - Двойное отрицание: neg(neg(x)), inv(inv(x))
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use BeeSwarm\Core\QueryEngine;

$engine = new QueryEngine();
$laws = $engine->query("SELECT domain, name, formula FROM laws");

$trivial = 0;
foreach ($laws as $law) {
    $f = $law['formula'];

    // Feature reference alone: x0, x1, x2...
    if (preg_match('/^x\d+$/', $f)) {
        $trivial++;
        echo "TRIVIAL: {$law['domain']}::{$law['name']} — '{$f}' (bare feature)\n";
        continue;
    }

    // Constant alone: K1, K2.5, K-3...
    if (preg_match('/^K-?\d+(\.\d+)?$/', $f)) {
        $trivial++;
        echo "TRIVIAL: {$law['domain']}::{$law['name']} — '{$f}' (bare constant)\n";
        continue;
    }

    // Algebraic identity patterns (from AtomRegistry::isTrivial)
    $patterns = [
        '/^[+−]\([^,]+,\s*0\)$/'         => '+0/−0',
        '/^[×*]\([^,]+,\s*1\)$/'         => '×1',
        '/^[÷\/]\([^,]+,\s*1\)$/'        => '/1',
        '/^neg\(neg\(.+\)\)$/'           => 'neg(neg)',
        '/^inv\(inv\(.+\)\)$/'           => 'inv(inv)',
        '/^abs\(abs\(.+\)\)$/'           => 'abs(abs)',
        '/^sq\(sqrt\(.+\)\)$/'           => 'sq(sqrt)',
    ];

    $matched = false;
    foreach ($patterns as $pattern => $label) {
        if (preg_match($pattern, $f)) {
            $trivial++;
            echo "TRIVIAL: {$law['domain']}::{$law['name']} — '{$f}' ({$label})\n";
            $matched = true;
            break;
        }
    }
}

$pass = $trivial === 0;
echo "Laws checked: " . count($laws) . "\n";
echo "Trivial: {$trivial}\n";
echo $pass ? "PASS: No trivial laws\n" : "FAIL: {$trivial} trivial laws\n";
exit($pass ? 0 : 1);
