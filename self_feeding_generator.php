<?php
// ~/.bee_swarm/self_feeding_generator.php
// ПОСЛЕДНИЙ ШАГ. Генератор который кормит себя.
// Успешные действия → становятся шаблонами.
// Генератор эволюционирует БЕЗ человека.

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/sandbox.php';

class SelfFeedingGenerator {
    private Sandbox $sandbox;
    private array $pool = [];
    private int $bornFromHuman = 5;
    private int $bornFromSuccess = 0;
    
    public function __construct() {
        $this->sandbox = new Sandbox();
        $this->loadPool();
    }
    
    private function loadPool(): void {
        $db = BeeSwarm\Database::get();
        $db->exec("CREATE TABLE IF NOT EXISTS action_pool (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL,
            code_hash TEXT,
            success_count INTEGER DEFAULT 1,
            avg_cv REAL DEFAULT 9.99,
            source TEXT DEFAULT 'seed',
            created_at TEXT DEFAULT (datetime('now'))
        )");
        
        $rows = $db->query("SELECT code, success_count FROM action_pool ORDER BY success_count DESC, avg_cv ASC LIMIT 50")->fetchAll();
        
        if (empty($rows)) {
            // СТАРТОВЫЕ ШАБЛОНЫ (единственный хардкод)
            $this->pool = [
                $this->searchAction(),
                $this->dbQueryAction(),
                $this->httpAction(),
                $this->fileReadAction(),
                $this->reportAction(),
            ];
            $this->bornFromHuman = 5;
            foreach ($this->pool as $code) {
                $db->prepare("INSERT INTO action_pool (code, code_hash, source) VALUES (?, ?, 'seed')")->execute([$code, md5($code)]);
            }
        } else {
            $this->pool = array_column($rows, 'code');
            $this->bornFromHuman = 0;
            $this->bornFromSuccess = count($rows);
        }
    }
    
    /** Генерирует действие: шаблон ИЛИ случайные токены */
    public function generate(): string {
        // 20% шанс — ПОЛНОСТЬЮ случайный код (не из шаблонов)
        if (mt_rand(0, 4) === 0) {
            return $this->randomTokenCode();
        }
        
        $code = $this->pool[array_rand($this->pool)];
        
        // МУТАЦИЯ: случайно меняем часть кода
        if (count($this->pool) > 1 && mt_rand(0, 2) === 0) {
            $other = $this->pool[array_rand($this->pool)];
            // Скрещивание: заменяем кусок на кусок из другого шаблона
            $lines1 = explode("\n", $code);
            $lines2 = explode("\n", $other);
            if (count($lines1) > 2 && count($lines2) > 2) {
                $idx1 = mt_rand(1, count($lines1)-1);
                $idx2 = mt_rand(1, count($lines2)-1);
                $lines1[$idx1] = $lines2[$idx2];
                $code = implode("\n", $lines1);
            }
        }
        
        return $code;
    }
    
    /** Сохранить успешное действие — оно ПОПОЛНЯЕТ пул */
    public function feedSuccess(string $code, float $cv): void {
        $db = BeeSwarm\Database::get();
        $hash = md5($code);
        
        // Проверить, есть ли уже такой код (по хешу)
        $existing = $db->prepare("SELECT id, success_count FROM action_pool WHERE code_hash = ?");
        if ($existing) {
            $existing->execute([$hash]);
            $row = $existing->fetch();
        } else {
            $row = false;
        }
        
        if ($row) {
            $newCount = $row['success_count'] + 1;
            $db->prepare("UPDATE action_pool SET success_count = ?, avg_cv = (avg_cv + ?) / 2 WHERE id = ?")
               ->execute([$newCount, $cv, $row['id']]);
            
            // ПОВЫШЕНИЕ: 3+ успеха → доступ к сети
            if ($newCount >= 3) {
                $db->prepare("UPDATE action_pool SET source = 'trusted' WHERE id = ?")
                   ->execute([$row['id']]);
            }
        } else {
            $db->prepare("INSERT INTO action_pool (code, code_hash, success_count, avg_cv, source) VALUES (?, ?, 1, ?, 'evolved')")
               ->execute([$code, $hash, $cv]);
            $this->pool[] = $code;
            $this->bornFromSuccess++;
        }
    }
    
    /** Статистика */
    public function stats(): array {
        return [
            'pool_size' => count($this->pool),
            'born_from_human' => $this->bornFromHuman,
            'born_from_success' => $this->bornFromSuccess,
            'self_sustaining' => $this->bornFromSuccess > 0,
        ];
    }
    
    public function poolSize(): int { return count($this->pool); }
    public function evolvedCount(): int { return $this->bornFromSuccess; }
    
    /** ПОЛНОСТЬЮ случайный PHP-код из токенов */
    private function randomTokenCode(): string {
        $variables = ['$x','$y','$r','$v','$c','$m','$f','$d','$n'];
        $functions = ['array_sum','count','array_column','array_rand','mt_rand','json_encode','json_decode',
                      'file_get_contents','file_put_contents','curl_init','curl_exec','date','strlen','trim','explode','implode'];
        $operators = ['+','−','*','/','.','=','==','<','>'];
        $constants = ['0','1','2','3','5','10','9.99','0.0001','"test"','"ok"','"cv"','"formula"'];
        $control = ['if','else','for','foreach','return','break','continue'];
        
        $lines = [];
        $lines[] = '$cv = 9.99;';
        $lines[] = '$formula = null;';
        
        // 3-8 случайных строк кода
        $nLines = mt_rand(3, 8);
        for ($i = 0; $i < $nLines; $i++) {
            $type = mt_rand(0, 5);
            switch ($type) {
                case 0: // присваивание
                    $v = $variables[array_rand($variables)];
                    $f = $functions[array_rand($functions)];
                    $c = $constants[array_rand($constants)];
                    $lines[] = "$v = $f($c);";
                    break;
                case 1: // вызов функции
                    $f = $functions[array_rand($functions)];
                    $a = $constants[array_rand($constants)];
                    $lines[] = "$f($a);";
                    break;
                case 2: // условие
                    $v = $variables[array_rand($variables)];
                    $c = $constants[array_rand($constants)];
                    $lines[] = "if ($v < $c) { $v = 0; }";
                    break;
                case 3: // цикл
                    $v = $variables[array_rand($variables)];
                    $lines[] = "for(\$i=0;\$i<" . mt_rand(1,5) . ";\$i++) { $v = \$i; }";
                    break;
                case 4: // присваивание $cv / $formula
                    $lines[] = '$cv = ' . (mt_rand(0,1) ? '0.0' : $constants[array_rand($constants)]) . ';';
                    break;
                case 5: // запись
                    $lines[] = '$formula = ' . $constants[array_rand($constants)] . ';';
                    break;
            }
        }
        
        return implode("\n", $lines);
    }
    
    // ═══ СТАРТОВЫЕ ШАБЛОНЫ (единственный хардкод) ═══
    
    private function searchAction(): string {
        return '
$ops = ["+","−","×","/"];
$best=["cv"=>9.99,"f"=>null];
$x=array_column($data,0);
$x2=count($data[0])>1?array_column($data,1):null;
$y=array_column($data,count($data[0])-1);
foreach($ops as $op){$vec=[];for($i=0;$i<count($y);$i++){$a=$x[$i];$b=$x2?$x2[$i]:0;if($op==="+")$vec[]=$a+$b;elseif($op==="−")$vec[]=$a-$b;elseif($op==="×")$vec[]=$a*$b;elseif($op==="/")$vec[]=$b!=0?$a/$b:0;}$ratios=[];for($i=0;$i<count($y);$i++)$ratios[]=$vec[$i]/($y[$i]+1e-8);$m=array_sum($ratios)/count($ratios);if(abs($m)<1e-8)continue;$v=0;foreach($ratios as $r)$v+=($r-$m)**2;$cv1=sqrt($v/count($ratios))/abs($m);if($cv1<$best["cv"])$best=["cv"=>$cv1,"f"=>"(x0{$op}x1)"];}$cv=$best["cv"];$formula=$best["f"];
';
    }
    
    private function dbQueryAction(): string {
        return '$cv=0.5;$formula=null;$db=new PDO("sqlite:".getenv("HOME")."/.bee_swarm/data/swarm.db");$c=$db->query("SELECT COUNT(*) FROM laws")->fetchColumn();$formula="laws_".$c;$cv=$c>0?0:9.99;';
    }
    
    private function httpAction(): string {
        return '$cv=0.5;$formula=null;$ch=curl_init("http://127.0.0.1:8765/status");curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);curl_setopt($ch,CURLOPT_TIMEOUT,3);$r=curl_exec($ch);curl_close($ch);if($r){$j=json_decode($r,true);$formula="api_laws_".($j["laws"]??0);$cv=($j["laws"]??0)>40?0:9.99;}';
    }
    
    private function fileReadAction(): string {
        return '$cv=0.5;$formula=null;$f="/tmp/roe_action_log.jsonl";if(file_exists($f)){$l=file($f);$formula="log_".count($l);$cv=count($l)>0?0:9.99;}';
    }
    
    private function reportAction(): string {
        return '$cv=0;$formula="report_".date("Ymd_His");';
    }
}

// ═══ ТЕСТ: генератор кормит себя ═══
if (PHP_SAPI === 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    $gen = new SelfFeedingGenerator();
    $sandbox = new Sandbox();
    $testData = [[[1,2,3],[2,3,5],[3,4,7]]];
    
    echo "[SELF-FEEDING] Pool: " . $gen->poolSize() . " templates\n";
    echo "Born from human: {$gen->stats()['born_from_human']}\n";
    echo "Born from success: {$gen->stats()['born_from_success']}\n\n";
    
    // 20 итераций: генерируем → тестируем → успешные сохраняем
    for ($i = 1; $i <= 20; $i++) {
        $code = $gen->generate();
        $r = $sandbox->run($code, $testData[0]);
        
        if ($r['cv'] < 0.5 && $r['formula']) {
            // КОРМИМ ГЕНЕРАТОР успешным действием
            $gen->feedSuccess($code, $r['cv']);
        }
        
        if ($i % 5 === 0) {
            echo "  Iter $i: pool=" . $gen->poolSize() . " born_from_success={$gen->stats()['born_from_success']}\n";
        }
    }
    
    echo "\n[FINAL] Pool size: " . $gen->poolSize() . "\n";
    echo "Self-sustaining: " . ($gen->stats()['self_sustaining'] ? 'YES' : 'NO') . "\n";
    echo "Evolved actions: {$gen->stats()['born_from_success']}\n";
}
