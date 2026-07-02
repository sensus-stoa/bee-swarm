<?php
// ~/.bee_swarm/test_evolve_organism.php
// ОРГАНИЗМ: мутация параметров + отбор по fitness

// ═══ ФЕНОТИП ═══
$phenotype = [
    'task_regen_interval'   => 100,   // тиков
    'starvation_timeout'    => 600,   // секунд
    'forager_max_files'     => 30,
    'compose_min_grammar'   => 3,
    'discover_sample_rate'  => 1.0,   // % атомов тестировать
];
$fitnessHistory = [];
$currentFitness = 0;

// ═══ СИМУЛЯЦИЯ СРЕДЫ ═══
// Разные «вселенные» с разными оптимальными параметрами
function simulateUniverse(array $p, int $universeType): float {
    // Вселенная 1: много данных → частый forager выгоден
    // Вселенная 2: мало данных → редкий forager, больше compose
    // Вселенная 3: баланс
    
    $discoveries = 0;
    $starvations = 0;
    
    for ($tick = 0; $tick < 5000; $tick++) {
        // Реген задач
        if ($tick % max(10, (int)$p['task_regen_interval']) === 0) {
            if ($universeType === 1) $discoveries += mt_rand(1, 5);
            elseif ($universeType === 2) $discoveries += mt_rand(0, 2);
            else $discoveries += mt_rand(1, 3);
        }
        
        // Forager
        $forageInterval = (int)($p['starvation_timeout'] / 10);
        if ($tick % max(1, $forageInterval) === 0) {
            $files = min(50, (int)$p['forager_max_files']);
            if ($universeType === 1) $discoveries += (int)($files * 0.3);
            elseif ($universeType === 2) $discoveries += (int)($files * 0.05);
            else $discoveries += (int)($files * 0.15);
        }
        
        // Голод
        if ($tick > 100 && $tick % max(100, (int)$p['starvation_timeout']) === 0) {
            $starvations++;
        }
    }
    
    return $starvations > 0 ? $discoveries / $starvations : $discoveries;
}

// ═══ ЭВОЛЮЦИЯ ═══
echo "══════════════════════════════════════\n";
echo "  ORGANISM EVOLUTION\n";
echo "  Mutate phenotype → measure fitness → select\n";
echo "══════════════════════════════════════\n\n";

$universes = [
    1 => 'Data-rich (many files)',
    2 => 'Data-poor (few files)',
    3 => 'Balanced',
];

foreach ($universes as $type => $desc) {
    echo "─── Universe $type: $desc ───\n";
    
    // Сброс фенотипа
    $p = $phenotype;
    $params = array_keys($p);
    
    // Начальный фитнес
    $fitness = simulateUniverse($p, $type);
    printf("  Gen %3d: fitness=%.1f  regen=%d starve=%d files=%d\n", 
        0, $fitness, $p['task_regen_interval'], $p['starvation_timeout'], $p['forager_max_files']);
    
    // Эволюция: 20 поколений
    for ($gen = 1; $gen <= 20; $gen++) {
        // Мутация: случайный параметр ±30%
        $param = $params[array_rand($params)];
        $old = $p[$param];
        $p[$param] = max(1, (int)($old * (mt_rand(0,1) ? 1.3 : 0.7)));
        
        $newFitness = simulateUniverse($p, $type);
        
        if ($newFitness <= $fitness) {
            $p[$param] = $old; // откат
        } else {
            $fitness = $newFitness;
            if ($gen % 5 === 0 || $newFitness > $fitness * 1.3) {
                printf("  Gen %3d: fitness=%.1f  %s: %d→%d (+%.0f%%)\n", 
                    $gen, $fitness, $param, $old, $p[$param], 
                    ($newFitness/$fitness-1)*100);
            }
        }
    }
    
    printf("  Final: regen=%d starve=%d files=%d compose=%d\n\n",
        $p['task_regen_interval'], $p['starvation_timeout'], 
        $p['forager_max_files'], $p['compose_min_grammar']);
}

echo "Done. Organism adapts to environment through mutation + selection.\n";
