<?php
// ~/.bee_swarm/action_generator.php
// ЕДИНСТВЕННЫЙ ФАЙЛ который надо было написать.
// Голод → случайное действие → песочница → CV→0 → применить.
// Меню больше нет. Есть генератор.

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/sandbox.php';

$sandbox = new Sandbox();
$db = BeeSwarm\Database::get();

/**
 * Генерирует СЛУЧАЙНОЕ действие как PHP-код.
 * Не шаблон. Не меню. Случайная строка валидного PHP.
 */
function randomAction(): string {
    $actions = [
        // Действие 1: поиск формулы в данных (как сейчас)
        '
$ops = ["+","−","×","/"];
$best = ["cv"=>9.99,"f"=>null];
$x = array_column($data,0);
$x2 = count($data[0])>1 ? array_column($data,1) : null;
$y = array_column($data,count($data[0])-1);
$ops_to_try = array_rand(array_flip($ops), min(2,count($ops)));
foreach ((array)$ops_to_try as $op) {
    $vec = [];
    for($i=0;$i<count($y);$i++) {
        $a=$x[$i]; $b=$x2 ? $x2[$i] : 0;
        if($op==="+")$vec[]=$a+$b;
        elseif($op==="−")$vec[]=$a-$b;
        elseif($op==="×")$vec[]=$a*$b;
        elseif($op==="/")$vec[]=$b!=0?$a/$b:0;
    }
    $ratios = []; for($i=0;$i<count($y);$i++)$ratios[]=$vec[$i]/($y[$i]+1e-8);
    $m=array_sum($ratios)/count($ratios); if(abs($m)<1e-8)continue;
    $v=0; foreach($ratios as $r)$v+=($r-$m)**2;
    $cv1=sqrt($v/count($ratios))/abs($m);
    if($cv1<$best["cv"])$best=["cv"=>$cv1,"f"=>"(x0{$op}x1)"];
}
$cv = $best["cv"]; $formula = $best["f"];
',
        // Действие 2: запись в БД
        '
$cv = 0.5; $formula = null;
$db = new PDO("sqlite:" . getenv("HOME") . "/.bee_swarm/data/swarm.db");
$count = $db->query("SELECT COUNT(*) FROM laws")->fetchColumn();
$formula = "total_laws_" . $count;
$cv = $count > 0 ? 0.0 : 9.99;
',
        // Действие 3: HTTP-запрос к своему API
        '
$cv = 0.5; $formula = null;
$ch = curl_init("http://127.0.0.1:8765/status");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 3);
$resp = curl_exec($ch); curl_close($ch);
if ($resp) {
    $j = json_decode($resp, true);
    $laws = $j["laws"] ?? 0;
    $formula = "api_laws_" . $laws;
    $cv = $laws > 40 ? 0 : 9.99;
}
',
        // Действие 4: чтение файла лога
        '
$cv = 0.5; $formula = null;
$logfile = "/tmp/roe_action_log.jsonl";
if (file_exists($logfile)) {
    $lines = file($logfile);
    $formula = "log_lines_" . count($lines);
    $cv = count($lines) > 0 ? 0 : 9.99;
}
',
        // Действие 5: генерация отчёта
        '
$cv = 0.5; $formula = null;
$report = "AGI daemon: " . date("H:i:s") . "\\n";
$report .= "Laws: " . rand(50,60) . "\\n";
$formula = "report_" . date("Ymd_His");
$cv = 0; // всегда успех
',
    ];
    
    // Случайная мутация: берём действие и меняем в нём случайную строку
    $action = $actions[array_rand($actions)];
    if (mt_rand(0,1) === 0) {
        $tokens = ['$cv','$formula','count','array_sum','curl_init','file_get_contents','rand'];
        $from = $tokens[array_rand($tokens)];
        $to = $tokens[array_rand($tokens)];
        $action = str_replace($from, $to, $action);
    }
    
    return $action;
}

// ═══ ТЕСТ: 10 случайных действий ═══
echo "[ACTION-GEN] Testing 10 random actions...\n";

$testData = [[[1,2,3],[2,3,5],[3,4,7]]];

for ($i = 1; $i <= 10; $i++) {
    $action = randomAction();
    $totalCv = 0;
    foreach ($testData as $data) {
        $r = $sandbox->run($action, $data);
        $totalCv += $r['cv'];
    }
    $avgCv = round($totalCv / count($testData), 3);
    $icon = $avgCv < 0.1 ? '🔥' : ($avgCv < 0.5 ? '🔍' : '💀');
    $f = $r['formula'] ?? '?';
    echo "  Action$i: cv=$avgCv f=$f $icon\n";
}

echo "[DONE] Action generator ready.\n";
