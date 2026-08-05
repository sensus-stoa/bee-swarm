<?php
declare(strict_types=1);

/**
 * GA vs Greedy+2opt на 10 инстансах TSP (20 городов, разные seed).
 * Цель: найти инстанс, где эволюция (популяция + кроссовер + меметика)
 * находит бассейн ЛУЧШЕ, чем 2-opt от жадного старта.
 */

const N = 20;
const POP = 40;
const GENS = 300;
const ELITE = 4;

function mkCities(int $seed): array
{
    mt_srand($seed);
    $c = [];
    for ($i = 0; $i < N; $i++) {
        $c[$i] = [mt_rand(0, 1000) / 10, mt_rand(0, 1000) / 10];
    }
    return $c;
}

function dist(array $a, array $b): float
{
    return sqrt(($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2);
}

function len(array $r, array $c): float
{
    $s = 0.0;
    $n = count($r);
    for ($i = 0; $i < $n; $i++) {
        $s += dist($c[$r[$i]], $c[$r[($i + 1) % $n]]);
    }
    return $s;
}

function greedy(array $c): array
{
    $n = count($c);
    $r = [mt_rand(0, $n - 1)];
    $v = [$r[0] => true];
    while (count($r) < $n) {
        $last = $r[count($r) - 1];
        $best = null;
        $bd = 1e9;
        for ($i = 0; $i < $n; $i++) {
            if (isset($v[$i])) {
                continue;
            }
            $d = dist($c[$last], $c[$i]);
            if ($d < $bd) {
                $bd = $d;
                $best = $i;
            }
        }
        $r[] = $best;
        $v[$best] = true;
    }
    return $r;
}

function opt2(array $r, array $c): array
{
    $n = count($r);
    $imp = true;
    while ($imp) {
        $imp = false;
        for ($i = 0; $i < $n - 1; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $r[$i];
                $b = $r[($i + 1) % $n];
                $cc = $r[$j];
                $d = $r[($j + 1) % $n];
                if (dist($c[$a], $c[$cc]) + dist($c[$b], $c[$d]) + 1e-9
                    < dist($c[$a], $c[$b]) + dist($c[$cc], $c[$d])) {
                    $seg = array_slice($r, $i + 1, $j - $i);
                    $r = array_merge(array_slice($r, 0, $i + 1), array_reverse($seg), array_slice($r, $j + 1));
                    $imp = true;
                }
            }
        }
    }
    return $r;
}

function ox1(array $p1, array $p2): array
{
    $n = count($p1);
    $a = mt_rand(0, $n - 2);
    $b = mt_rand($a + 1, $n - 1);
    $child = array_fill(0, $n, -1);
    for ($i = $a; $i <= $b; $i++) {
        $child[$i] = $p1[$i];
    }
    $used = array_flip(array_slice($child, $a, $b - $a + 1));
    $pos = 0;
    foreach ($p2 as $city) {
        if (isset($used[$city])) {
            continue;
        }
        while ($child[$pos] !== -1) {
            $pos++;
        }
        $child[$pos] = $city;
    }
    return $child;
}

function mut(array $r): array
{
    $roll = mt_rand(0, 100);
    if ($roll < 45) {
        $a = mt_rand(0, N - 2);
        $b = mt_rand($a + 1, N - 1);
        $seg = array_slice($r, $a, $b - $a + 1);
        $r = array_merge(array_slice($r, 0, $a), array_reverse($seg), array_slice($r, $b + 1));
    } elseif ($roll < 75) {
        [$a, $b] = [mt_rand(0, N - 1), mt_rand(0, N - 1)];
        [$r[$a], $r[$b]] = [$r[$b], $r[$a]];
    } else {
        $a = mt_rand(0, N - 1);
        $b = mt_rand(0, N - 1);
        if ($a === $b) {
            $b = ($b + 1) % N;
        }
        $city = $r[$a];
        array_splice($r, $a, 1);
        array_splice($r, $b, 0, [$city]);
    }
    return $r;
}

function gaMemetic(array $c, int $seed): float
{
    mt_srand($seed);
    $pop = [];
    foreach (range(0, POP - 2) as $_) {
        $r = range(0, N - 1);
        shuffle($r);
        $pop[] = $r;
    }
    $pop[] = greedy($c); // жадный в стартовой популяции

    $best = 1e9;
    for ($g = 0; $g < GENS; $g++) {
        usort($pop, fn ($a, $b) => len($a, $c) <=> len($b, $c));
        if ($g % 20 === 0) {
            for ($i = 0; $i < ELITE; $i++) {
                $pop[$i] = opt2($pop[$i], $c);
            }
        }
        if (len($pop[0], $c) < $best) {
            $best = len($pop[0], $c);
        }
        $new = [];
        for ($i = 0; $i < ELITE; $i++) {
            $new[] = $pop[$i];
        }
        while (count($new) < POP) {
            $p1 = $pop[mt_rand(0, POP - 1)];
            $p2 = $pop[mt_rand(0, POP - 1)];
            $p3 = $pop[mt_rand(0, POP - 1)];
            $parent = len($p1, $c) < len($p2, $c) ? (len($p1, $c) < len($p3, $c) ? $p1 : $p3)
                : (len($p2, $c) < len($p3, $c) ? $p2 : $p3);
            $child = mt_rand(0, 100) < 70 ? ox1($parent, $pop[mt_rand(0, POP - 1)]) : $parent;
            $child = mut($child);
            $new[] = $child;
        }
        $pop = $new;
    }
    // Финальный 2-opt на лучшем
    usort($pop, fn ($a, $b) => len($a, $c) <=> len($b, $c));
    return len(opt2($pop[0], $c), $c);
}

echo "seed | greedy | 2opt(greedy) | GA+2opt | GA < 2opt? | win\n";
echo str_repeat('-', 60) . "\n";
$gaWins = 0;
for ($seed = 1; $seed <= 10; $seed++) {
    $c = mkCities($seed);
    $g = len(greedy($c), $c);
    $o = len(opt2(greedy($c), $c), $c);
    // Лучший из 3 GA-запусков (разные seed популяции)
    $gaBest = 1e9;
    for ($t = 0; $t < 3; $t++) {
        $gaBest = min($gaBest, gaMemetic($c, 1000 + $seed * 10 + $t));
    }
    $win = $gaBest < $o - 0.01 ? "GA WINS" : ($gaBest < $o + 0.01 ? "=" : "2opt");
    if ($win === "GA WINS") {
        $gaWins++;
    }
    printf("%-4d | %6.2f | %6.2f      | %6.2f    | %s | %s\n",
        $seed, $g, $o, $gaBest, $gaBest < $o ? "yes" : "no", $win);
}
echo str_repeat('-', 60) . "\n";
echo "GA побед: $gaWins/10\n";
