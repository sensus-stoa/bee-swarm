<?php
// ~/.bee_swarm/sandbox.php
// Песочница: рой может пробовать ЛЮБОЙ PHP-код.
// Безопасность: timeout, memory limit, no dangerous functions, изолированная ФС.

class Sandbox {
    private string $tmpDir;
    
    public function __construct() {
        $this->tmpDir = sys_get_temp_dir() . '/roe_sb_' . getmypid() . '_' . bin2hex(random_bytes(4));
        @mkdir($this->tmpDir, 0700, true);
    }
    
    /**
     * Выполнить PHP-код в песочнице.
     * @return array [ok, output, error, elapsed_ms, memory_kb]
     */
    /**
     * Выполнить PHP-код в песочнице.
     * @param bool $trusted — если true, сеть разрешена
     */
    public function run(string $phpCode, array $data = [], bool $trusted = false): array {
        // Создаём уникальную директорию на каждый запуск
        $this->tmpDir = sys_get_temp_dir() . '/roe_sb_' . uniqid('', true);
        @mkdir($this->tmpDir, 0700, true);
        
        $codeFile = $this->tmpDir . '/code.php';
        $dataFile = $this->tmpDir . '/data.json';
        $outFile  = $this->tmpDir . '/output.json';
        
        // Оборачиваем код в безопасный контекст
        $wrapped = $this->wrap($phpCode);
        file_put_contents($codeFile, $wrapped);
        file_put_contents($dataFile, json_encode($data));
        
        // Загрузка данных для НЕЗАВИСИМОЙ валидации
        $X = array_map(fn($r) => array_slice($r, 0, -1), $data);
        $y = array_column($data, count($data[0]) - 1);
        
        // БАЗОВЫЕ ограничения (всегда)
        $baseDisabled = "exec,shell_exec,system,passthru,popen,proc_open,pcntl_exec,link,symlink,mail,ini_set,error_reporting";
        
        // TRUSTED: убираем ограничение на curl + файловый доступ
        if ($trusted) {
            $disabled = $baseDisabled; // curl_exec разрешён!
            $basedir = $this->tmpDir;  // только песочница
        } else {
            $disabled = $baseDisabled . ",curl_exec,curl_init,file_put_contents,file_get_contents,fopen,fread,fwrite,fclose";
            $basedir = $this->tmpDir;
        }
        
        $start = microtime(true);
        
        // Запускаем PHP с ограничениями
        $bin = 'php';
        $cmd = "timeout 5 $bin -d memory_limit=50M -d disable_functions=\"{$disabled}\" -d open_basedir={$basedir} {$codeFile} {$dataFile} {$outFile} 2>&1";
        
        $output = shell_exec($cmd);
        $elapsed = round((microtime(true) - $start) * 1000, 2);
        
        $result = file_exists($outFile) ? json_decode(file_get_contents($outFile), true) : null;
        
        $ok = $result['ok'] ?? false;
        $cv  = $result['cv'] ?? 9.99;
        $found = $result['formula'] ?? null;
        
        // 🔥 НЕЗАВИСИМАЯ ВАЛИДАЦИЯ: проверяем формулу на реальных данных
        if ($found && $cv < 0.5 && !empty($data)) {
            $realCv = $this->validateFormula($found, $data);
            if ($realCv > 0.5) {
                // Код соврал про CV — штраф
                $ok = false;
                $cv = 9.99;
            } else {
                $cv = $realCv; // используем реальный CV
            }
        }
        
        // Очистка
        $this->cleanup();
        
        return [
            'ok' => $ok,
            'cv' => $cv,
            'formula' => $found,
            'output' => $output,
            'elapsed_ms' => $elapsed,
            'error' => $result['error'] ?? null,
        ];
    }
    
    private function wrap(string $userCode): string {
        $code = <<<'PHP'
<?php
// Sandbox wrapper — безопасное окружение
declare(strict_types=1);

$dataFile = $argv[1] ?? '';
$outFile  = $argv[2] ?? '';
$data = $dataFile && file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : [];

$result = ['ok' => false, 'cv' => 9.99, 'formula' => null, 'error' => null];

try {
    // КОД РОЯ (песочница)
    $cv = 9.99;
    $formula = null;
    
PHP;
        $code .= "\n    " . str_replace("\n", "\n    ", $userCode) . "\n";
        $code .= <<<'PHP'
    
    $result = ['ok' => ($cv < 0.1), 'cv' => $cv, 'formula' => $formula];
} catch (\Throwable $e) {
    $result = ['ok' => false, 'cv' => 9.99, 'formula' => null, 'error' => $e->getMessage()];
}

file_put_contents($outFile, json_encode($result));
PHP;
        return $code;
    }
    
    private function cleanup(): void {
        exec('rm -rf ' . escapeshellarg($this->tmpDir) . ' 2>/dev/null');
    }
    
    /** Проверить формулу на реальных данных */
    private function validateFormula(string $formula, array $data): float {
        if (!$formula) return 9.99;
        if (str_contains($formula, 'report_') || str_contains($formula, 'api_') 
            || str_contains($formula, 'laws_') || str_contains($formula, 'log_')) {
            return 9.99; // мусор
        }
        
        $x = array_column($data, 0);
        $x2 = count($data[0]) > 1 ? array_column($data, 1) : null;
        $y = array_column($data, count($data[0]) - 1);
        $n = count($y);
        
        $vec = null;
        if ($formula === '(x0+x1)' && $x2) { $vec = []; for($i=0;$i<$n;$i++) $vec[]=$x[$i]+$x2[$i]; }
        elseif ($formula === '(x0×x1)' && $x2) { $vec = []; for($i=0;$i<$n;$i++) $vec[]=$x[$i]*$x2[$i]; }
        elseif (preg_match('/^K([\d.]+)$/', $formula, $m)) { $k=(float)$m[1]; $vec=array_fill(0,$n,$k); }
        elseif (preg_match('/^x0×K([\d.]+)$/', $formula, $m)) { $k=(float)$m[1]; $vec=[]; for($i=0;$i<$n;$i++) $vec[]=$x[$i]*$k; }
        else { return 9.99; } // не можем проверить
        
        $exact = true;
        for($i=0;$i<$n;$i++) if(abs($vec[$i]-$y[$i])>0.001){$exact=false;break;}
        if($exact) return 0.0;
        
        $ratios=[]; for($i=0;$i<$n;$i++) $ratios[]=$vec[$i]/($y[$i]+1e-8);
        $mean=array_sum($ratios)/$n; if(abs($mean)<1e-8) return 9.99;
        $var=0; foreach($ratios as $r) $var+=($r-$mean)**2;
        return sqrt($var/$n)/abs($mean);
    }
}

// ═══ ТЕСТ ═══
if (PHP_SAPI === 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    $sandbox = new Sandbox();
    
    echo "Тест 1: правильный код\n";
    $r = $sandbox->run('$cv = 0.0; $formula = "x0+x1";', [[1,2,3],[2,3,5]]);
    echo "  ok={$r['ok']} cv={$r['cv']} f={$r['formula']} time={$r['elapsed_ms']}ms\n";
    
    echo "\nТест 2: код с ошибкой\n";
    $r = $sandbox->run('$x = undefined_function();', []);
    echo "  ok={$r['ok']} error={$r['error']}\n";
    
    echo "\nТест 3: генерация формулы через перебор\n";
    $code = <<<'PHP'
$ops = ['+','−','×'];
$x = array_column($data, 0);
$y = array_column($data, 2);
$best = ['cv'=>9.99, 'f'=>null];
foreach ($ops as $op) {
    $vec = [];
    for ($i=0;$i<count($y);$i++) {
        $a=$data[$i][0]; $b=$data[$i][1];
        if ($op === '+') $vec[] = $a+$b;
        elseif ($op === '−') $vec[] = $a-$b;
        elseif ($op === '×') $vec[] = $a*$b;
        else $vec[] = 0;
    }
    $ratios = []; foreach ($y as $i=>$v) $ratios[] = $vec[$i]/($v+1e-8);
    $mean = array_sum($ratios)/count($ratios);
    $var = 0; foreach($ratios as $r) $var += ($r-$mean)**2;
    $cv_now = sqrt($var/count($ratios))/abs($mean);
    if ($cv_now < $best['cv']) { $best = ['cv'=>$cv_now, 'f'=>"(x0{$op}x1)"]; }
}
$cv = $best['cv']; $formula = $best['f'];
PHP;
    
    $r = $sandbox->run($code, [[1,2,3],[2,3,5],[3,4,7]]);
    echo "  ok={$r['ok']} cv={$r['cv']} f={$r['formula']} time={$r['elapsed_ms']}ms\n";
}
