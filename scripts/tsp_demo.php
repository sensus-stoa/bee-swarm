<?php
declare(strict_types=1);

/**
 * TSP DEMO — эволюционный фреймворк (тот же класс, что bee swarm):
 * геном = перестановка городов, фитнес = длина маршрута, отбор + мутации + поколения.
 *
 * Показывает: механизмы роя (популяция, мутация, отбор, элитизм) решают
 * комбинаторную задачу, если заменить геном/фитнес.
 */

const N_CITIES = 20;
const POP_SIZE = 60;
const GENERATIONS = 2000;
const ELITE = 5;
const LOCAL_SEARCH_EVERY = 25; // меметика: 2-opt к элите каждые N поколений

// --- Генерация городов (детерминированно) ---
$cities = [];
mt_srand(42);
for ($i = 0; $i < N_CITIES; $i++) {
    $cities[$i] = [mt_rand(0, 1000) / 10, mt_rand(0, 1000) / 10];
}

function dist(array $a, array $b): float
{
    return sqrt(($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2);
}

function routeLength(array $route, array $cities): float
{
    $len = 0.0;
    $n = count($route);
    for ($i = 0; $i < $n; $i++) {
        $len += dist($cities[$route[$i]], $cities[$route[($i + 1) % $n]]);
    }
    return $len;
}

// --- Инициализация: случайные маршруты + жадный (nearest neighbor) ---
function randomRoute(): array
{
    $r = range(0, N_CITIES - 1);
    shuffle($r);
    return $r;
}

function greedyRoute(array $cities): array
{
    $n = count($cities);
    $route = [mt_rand(0, $n - 1)];
    $visited = [$route[0] => true];
    while (count($route) < $n) {
        $last = $route[count($route) - 1];
        $best = null;
        $bestD = PHP_FLOAT_MAX;
        for ($i = 0; $i < $n; $i++) {
            if (isset($visited[$i])) {
                continue;
            }
            $d = dist($cities[$last], $cities[$i]);
            if ($d < $bestD) {
                $bestD = $d;
                $best = $i;
            }
        }
        $route[] = $best;
        $visited[$best] = true;
    }
    return $route;
}

// --- Мутации ---
function mutateSwap(array $r): array
{
    [$a, $b] = [mt_rand(0, N_CITIES - 1), mt_rand(0, N_CITIES - 1)];
    [$r[$a], $r[$b]] = [$r[$b], $r[$a]];
    return $r;
}

function mutate2opt(array $r): array
{
    $a = mt_rand(0, N_CITIES - 2);
    $b = mt_rand($a + 1, N_CITIES - 1);
    $seg = array_slice($r, $a, $b - $a + 1);
    $r = array_merge(array_slice($r, 0, $a), array_reverse($seg), array_slice($r, $b + 1));
    return $r;
}

function mutateInsert(array $r): array
{
    $a = mt_rand(0, N_CITIES - 1);
    $b = mt_rand(0, N_CITIES - 1);
    if ($a === $b) {
        $b = ($b + 1) % N_CITIES;
    }
    $city = $r[$a];
    array_splice($r, $a, 1);
    array_splice($r, $b, 0, [$city]);
    return $r;
}

// --- Кроссовер: Order Crossover (OX1) ---
function crossoverOX1(array $p1, array $p2): array
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
        $used[$city] = true;
    }
    return $child;
}

// --- 2-opt локальный поиск (меметика): улучшаем пока есть выигрыш ---
function localSearch2opt(array $route, array $cities): array
{
    $n = count($route);
    $improved = true;
    while ($improved) {
        $improved = false;
        for ($i = 0; $i < $n - 1; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                // Рёбра (i, i+1) и (j, j+1) → (i, j) и (i+1, j+1)
                $a = $route[$i];
                $b = $route[($i + 1) % $n];
                $c = $route[$j];
                $d = $route[($j + 1) % $n];
                $before = dist($cities[$a], $cities[$b]) + dist($cities[$c], $cities[$d]);
                $after = dist($cities[$a], $cities[$c]) + dist($cities[$b], $cities[$d]);
                if ($after + 1e-9 < $before) {
                    // Инверсия сегмента (i+1..j)
                    $seg = array_slice($route, $i + 1, $j - $i);
                    $route = array_merge(
                        array_slice($route, 0, $i + 1),
                        array_reverse($seg),
                        array_slice($route, $j + 1)
                    );
                    $improved = true;
                }
            }
        }
    }
    return $route;
}

// --- Основной цикл ---
$pop = [];
foreach (range(0, POP_SIZE - 2) as $_) {
    $pop[] = randomRoute();
}
$pop[] = greedyRoute($cities);

$bestEver = PHP_FLOAT_MAX;
$bestRoute = null;
$stagnation = 0;

echo "TSP DEMO: " . N_CITIES . " городов, pop=" . POP_SIZE . ", gens=" . GENERATIONS . "\n";
echo str_repeat('-', 60) . "\n";
printf("%-10s | %-14s | %-14s | %s\n", "Gen", "Best", "Mean", "Greedy");
echo str_repeat('-', 60) . "\n";

$greedyLen = routeLength(greedyRoute($cities), $cities);
printf("%-10s | %-14s | %-14s | %.2f\n", "start", "—", "—", $greedyLen);

for ($gen = 0; $gen <= GENERATIONS; $gen++) {
    // Фитнес
    $fitness = array_map(fn (array $r): float => routeLength($r, $cities), $pop);
    $bestIdx = array_search(min($fitness), $fitness);
    $bestLen = $fitness[$bestIdx];
    $mean = array_sum($fitness) / count($fitness);

    if ($bestLen < $bestEver) {
        $bestEver = $bestLen;
        $bestRoute = $pop[$bestIdx];
        $stagnation = 0;
    } else {
        $stagnation++;
    }

    // Рестарт при стагнации: 100 поколений без улучшения → свежая популяция
    // (элита сохраняется, жадный + мутанты как посев)
    if ($stagnation > 100) {
        $stagnation = 0;
        $newPop = [];
        for ($i = 0; $i < ELITE; $i++) {
            $newPop[] = $pop[$i];
        }
        $newPop[] = greedyRoute($cities);
        while (count($newPop) < POP_SIZE) {
            $newPop[] = mutate2opt(mutateSwap(greedyRoute($cities)));
        }
        $pop = $newPop;
        if ($gen % 50 === 0) {
            printf("%-10s | %-14.2f | %-14s | %.2f (restart)\n", "R", $bestEver, "—", $greedyLen);
        }
        continue;
    }

    if ($gen % 50 === 0 || $gen === GENERATIONS) {
        printf("%-10d | %-14.2f | %-14.2f | %.2f\n", $gen, $bestLen, $mean, $greedyLen);
    }

    // Отбор: элитизм + турнир
    usort($pop, fn ($a, $b) => routeLength($a, $cities) <=> routeLength($b, $cities));

    // Меметика: 2-opt локальный поиск на элите
    if ($gen > 0 && $gen % LOCAL_SEARCH_EVERY === 0) {
        for ($i = 0; $i < ELITE; $i++) {
            $pop[$i] = localSearch2opt($pop[$i], $cities);
        }
    }

    $newPop = [];
    for ($i = 0; $i < ELITE; $i++) {
        $newPop[] = $pop[$i];
    }
    while (count($newPop) < POP_SIZE) {
        // Турнир из 3
        $t1 = $pop[mt_rand(0, POP_SIZE - 1)];
        $t2 = $pop[mt_rand(0, POP_SIZE - 1)];
        $t3 = $pop[mt_rand(0, POP_SIZE - 1)];
        $parent1 = routeLength($t1, $cities) < routeLength($t2, $cities)
            ? (routeLength($t1, $cities) < routeLength($t3, $cities) ? $t1 : $t3)
            : (routeLength($t2, $cities) < routeLength($t3, $cities) ? $t2 : $t3);
        $parent2 = $pop[mt_rand(0, POP_SIZE - 1)];

        $child = mt_rand(0, 100) < 70 ? crossoverOX1($parent1, $parent2) : $parent1;

        // Мутация
        $roll = mt_rand(0, 100);
        if ($roll < 40) {
            $child = mutate2opt($child);
        } elseif ($roll < 70) {
            $child = mutateSwap($child);
        } elseif ($roll < 90) {
            $child = mutateInsert($child);
        }
        $newPop[] = $child;
    }
    $pop = $newPop;
}

echo str_repeat('-', 60) . "\n";
printf("ИТОГ: best=%.2f (жадный: %.2f, случайный≈%.2f)\n",
    $bestEver, $greedyLen, routeLength(randomRoute(), $cities));
echo "Маршрут: " . implode('-', $bestRoute) . "\n";
