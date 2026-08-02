#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * verify_0_7.php — Compression Superiority (§1.7)
 *
 * Дух критерия: сложная формула должна окупать свою сложность
 * лучшим предсказанием. Метафора: закон — это сжатие данных.
 * Если CV высокий А формула сложная — это не сжатие, а переусложнённый шум.
 *
 * Проверка: для каждого закона — если complexity > 5 И CV > 0.2 → FAIL.
 * Законы с CV ≤ 0.2 окупают любую разумную сложность.
 * Законы с complexity ≤ 5 (простые) допустимы даже при высоком CV —
 *   это может быть слабый но реальный паттерн.
 *
 * Пороги (E): complexity=5, CV=0.2. Произвольны, но объявлены.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use BeeSwarm\Core\AtomRegistry;
use BeeSwarm\Core\QueryEngine;

$engine = new QueryEngine();
$laws = $engine->query("SELECT domain, name, formula, cv FROM laws WHERE cv IS NOT NULL");

$failures = 0;
$total = count($laws);

foreach ($laws as $law) {
    $complexity = AtomRegistry::atomComplexity($law['formula']);
    $cv = (float) $law['cv'];

    // Дух критерия: сложная формула с плохим CV — не закон
    if ($complexity > 5 && $cv > 0.2) {
        $failures++;
        echo "COMPRESSION_FAIL: {$law['domain']}::{$law['name']} — '{$law['formula']}' complexity={$complexity} cv=" . round($cv, 4) . "\n";
    }
}

$pass = $failures === 0;
echo "Laws checked: {$total}\n";
echo "Compression failures: {$failures}\n";
echo $pass ? "PASS: All laws earn their complexity\n" : "FAIL: {$failures} laws are complex but inaccurate\n";
exit($pass ? 0 : 1);
