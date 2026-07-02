<?php
// ~/.bee_swarm/test_ifr3.php
// ИКР³: 0 строк хардкода. Среда = PHP. Отбор = смерть процесса.

$home = getenv('HOME');

// ═══ ДАННЫЕ ═══
$files = []; $c = 0;
foreach (new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($home . '/Documents/the_lair', RecursiveDirectoryIterator::SKIP_DOTS)
) as $f) {
    if ($c++ >= 20) break;
    $p = $f->getPathname();
    if (str_contains($p, '.git/') || str_contains($p, 'venv/')) continue;
    $files[] = [(float)$f->getSize(), (float)(time() - $f->getMTime())];
}

$X = array_map(fn($r) => [$r[0]], $files); // [size]
$y = array_column($files, 1);              // age

// ═══ НИ ФИЛЬТРА, НИ ОБЁРТКИ ═══
$all = get_defined_functions()['internal'];
$safe = [];   // выжившие функции
$dead = [];   // убившие процесс
$found = [];  // CV=0 открытия

$trials = 200;
$cvFn = function($v, $y) {
    $n = count($v);
    for ($i=0; $i<$n; $i++) { if (abs($v[$i]-$y[$i])>0.001) break; if ($i==$n-1) return 0.0; }
    $r=[]; for($i=0;$i<$n;$i++) $r[]=$v[$i]/($y[$i]+1e-8);
    $m=array_sum($r)/$n; if(abs($m)<1e-8) return 9.99;
    $q=0; foreach($r as $x) $q+=($x-$m)**2;
    return sqrt($q/$n)/abs($m);
};

echo "══════════════════════════════════════\n";
echo "  IFR³: no filter, no try/catch, no class\n";
echo "  Selection = process death\n";
echo "══════════════════════════════════════\n\n";

$testX = $X[0][0]; // одно значение для проверки «убьёт или нет»

for ($i = 0; $i < $trials; $i++) {
    $fn = $all[array_rand($all)];
    if (isset($dead[$fn]) || isset($safe[$fn])) continue;
    
    // Вызываем в отдельном процессе — если умрёт, мы выживем
    $code = '<?php $r = @' . $fn . '(' . $testX . '); echo is_numeric($r) ? "1" : "0";';
    $cmd = 'timeout 1 php -r ' . escapeshellarg($code) . ' 2>/dev/null';
    $out = shell_exec($cmd);
    
    if ($out === null || trim($out) === '') {
        $dead[$fn] = true; // убило процесс
        continue;
    }
    
    if (trim($out) === '1') {
        $safe[$fn] = true; // выжила и вернула число
        
        // Тест на всех данных
        $vec = [];
        foreach ($X as $row) {
            $r = @$fn($row[0]);
            if ($r === false || $r === null || is_array($r) || is_object($r) || is_string($r) || is_bool($r)) {
                $vec = null; break;
            }
            $vec[] = (float)$r;
        }
        if ($vec === null) continue;
        
        $cv = $cvFn($vec, $y);
        if ($cv < 0.5) {
            $found[] = ['fn' => $fn, 'cv' => $cv];
        }
    }
}

echo "Trials: $trials\n";
echo "Safe:   " . count($safe) . "\n";
echo "Dead:   " . count($dead) . "\n";
echo "Found:  " . count($found) . "\n\n";

usort($found, fn($a,$b) => $a['cv'] <=> $b['cv']);
echo "Top atoms (CV<0.5):\n";
foreach (array_slice($found, 0, 12) as $f) {
    $icon = $f['cv'] < 0.01 ? '✅' : ($f['cv'] < 0.1 ? '🔍' : '·');
    printf("  %s %-25s CV=%.4f\n", $icon, $f['fn'], $f['cv']);
}

echo "\nNo AtomRegistry. No curated lists. No ob_start. No try/catch.\n";
echo "Selection: process dies or lives. Environment IS the filter.\n";
