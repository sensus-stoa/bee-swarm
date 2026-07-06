<?php
// ~/.bee_swarm/test_ifr_alphabet.php v2
// ИКР: алфавит = PHP. С ob_start для подавления вывода.

$home = getenv('HOME');

echo "══════════════════════════════════════\n";
echo "  IFR: alphabet = PHP\n";
echo "══════════════════════════════════════\n\n";

// ═══ ФИЛЬТР: числовые функции через ob ═══
$allInternal = get_defined_functions()['internal'];
echo "Total: " . count($allInternal) . " functions\n";

$unary = []; $binary = [];
$skip = ['set_','ini_','header','session','ob_','error_report','trigger_error',
         'define','class_','function_','method_','trait_','interface_',
         'stream','socket','curl','exec','proc_','pcntl','posix',
         'mysql','pg_','oci_','odbc','sqlite','pdo','mongo',
         'image','gd_','exif','imagemagick','imagick',
         'openssl','hash','password','crypt','mcrypt',
         'xml','json_encode','json_decode','simplexml','dom_',
         'mb_','iconv','locale','date_default','timezone',
         'apache','fastcgi','php_ini','zend_','opcache','xdebug',
         'readline','ncurses','newt',
         'print','echo','printf','sprintf','vprintf','vsprintf',
         'var_dump','var_export','print_r','debug_','highlight_',
];

$testVals = [-5.0, -1.0, 0.0, 1.0, 3.0];
$testPairs = [[1.0,2.0], [-1.0,5.0], [3.0,-4.0]];

foreach ($allInternal as $fn) {
    $skipIt = false;
    foreach ($skip as $p) if (str_starts_with($fn, $p)) { $skipIt = true; break; }
    if ($skipIt) continue;
    
    // Унарный тест
    ob_start();
    $ok = true;
    foreach ($testVals as $v) {
        try { $r = @$fn($v); } catch (\Throwable $e) { $ok = false; break; }
        if ($r === false || $r === null || is_array($r) || is_object($r) || is_string($r) || is_bool($r)) {
            $ok = false; break;
        }
        if (!is_numeric($r)) { $ok = false; break; }
        $rf = (float)$r;
        if (is_nan($rf) || is_infinite($rf)) { $ok = false; break; }
    }
    ob_end_clean();
    if ($ok) { $unary[] = $fn; continue; }
    
    // Бинарный тест
    ob_start();
    $ok = true;
    foreach ($testPairs as [$a, $b]) {
        try { $r = @$fn($a, $b); } catch (\Throwable $e) { $ok = false; break; }
        if ($r === false || $r === null || is_array($r) || is_object($r) || is_string($r) || is_bool($r)) {
            $ok = false; break;
        }
        if (!is_numeric($r)) { $ok = false; break; }
        $rf = (float)$r;
        if (is_nan($rf) || is_infinite($rf)) { $ok = false; break; }
    }
    ob_end_clean();
    if ($ok) $binary[] = $fn;
}

echo "Unary:  " . count($unary) . "\n";
echo "Binary: " . count($binary) . "\n\n";

// Покажем что нашлось нового (не в стандартной математике)
$stdMath = ['abs','sqrt','sin','cos','tan','asin','acos','atan','sinh','cosh','tanh',
            'exp','log','log10','log1p','floor','ceil','round','deg2rad','rad2deg',
            'min','max','hypot','pow','fmod','pi','atan2','intdiv','mt_rand','rand'];
$newUnary = array_diff($unary, $stdMath);
$newBinary = array_diff($binary, $stdMath);
echo "New unary:  " . count($newUnary) . " — " . implode(', ', array_slice($newUnary, 0, 15)) . "\n";
echo "New binary: " . count($newBinary) . " — " . implode(', ', array_slice($newBinary, 0, 15)) . "\n\n";

// ═══ ТЕСТ НА РЕАЛЬНЫХ ДАННЫХ ═══
require_once __DIR__ . '/vendor/autoload.php';
use BeeSwarm\Core\AtomRegistry;

// Файлы
$files = [];
$count = 0;
foreach (new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($home . '/Documents/the_lair', RecursiveDirectoryIterator::SKIP_DOTS)
) as $f) {
    if ($count >= 30) break;
    $path = $f->getPathname();
    if (str_contains($path, '.git/') || str_contains($path, 'venv/')) continue;
    $files[] = [
        'size' => $f->getSize(),
        'age'  => time() - $f->getMTime(),
        'depth'=> substr_count(str_replace($home.'/Documents/the_lair/', '', $path), '/'),
    ];
    $count++;
}

// Задачи
$tasks = [
    ['name'=>'size→age',  'X'=>array_map(fn($f)=>[(float)$f['size']], $files), 
                          'y'=>array_map(fn($f)=>(float)$f['age'], $files)],
    ['name'=>'depth→size','X'=>array_map(fn($f)=>[(float)$f['depth']], $files), 
                          'y'=>array_map(fn($f)=>(float)$f['size']/1000, $files)],
];

$allAtoms = array_merge(
    array_map(fn($n)=>['name'=>$n,'arity'=>1], $unary),
    array_map(fn($n)=>['name'=>$n,'arity'=>2], $binary)
);

foreach ($tasks as $task) {
    echo "─── {$task['name']} (" . count($task['y']) . " pts) ───\n";
    $found = [];
    
    foreach ($allAtoms as $atom) {
        $vec = []; $valid = true;
        foreach ($task['X'] as $row) {
            try {
                if ($atom['arity']===2 && count($row)>=2)
                    $v = @($atom['name'])((float)$row[0], (float)$row[1]);
                else
                    $v = @($atom['name'])((float)$row[0]);
            } catch (\Throwable $e) { $valid=false; break; }
            
            if ($v===false||$v===null||is_array($v)||is_object($v)||is_string($v)||is_bool($v))
                { $valid=false; break; }
            $vf = (float)$v;
            if (is_nan($vf)||is_infinite($vf)) { $valid=false; break; }
            $vec[] = $vf;
        }
        if (!$valid) continue;
        
        $cv = AtomRegistry::cv($vec, $task['y']);
        if ($cv < 0.5) $found[] = ['atom'=>$atom['name'], 'cv'=>$cv];
    }
    
    usort($found, fn($a,$b)=>$a['cv']<=>$b['cv']);
    foreach (array_slice($found, 0, 8) as $f) {
        $icon = $f['cv']<0.01?'✅':($f['cv']<0.1?'🔍':'·');
        printf("  %s %-20s CV=%.4f\n", $icon, $f['atom'], $f['cv']);
    }
    echo "\n";
}

echo "Done. " . count($allAtoms) . " atoms from environment, 0 curated.\n";
