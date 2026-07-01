<?php
// ~/.bee_swarm/self_replace.php
// Рой spawn → benchmark → убивает себя если потомок лучше.
require_once __DIR__ . '/vendor/autoload.php';

$spawner = new BeeSwarm\SwarmSpawner();
$testTasks = [
    ['task'=>'AND','data'=>[[0,0,0],[0,1,0],[1,0,0],[1,1,1]]],
    ['task'=>'OR','data'=>[[0,0,0],[0,1,1],[1,0,1],[1,1,1]]],
    ['task'=>'MIN','data'=>[[0,0,0],[2,3,2],[5,1,1],[4,4,4]]],
];

// Тест родителя
$parent = $spawner->spawn(['search_depth'=>3,'bees'=>2,'grammar_ops'=>['+','−','×','/','abs'],'port'=>29001]);
$parentBench = $spawner->benchmark($parent, $testTasks);
$parentScore = score($parentBench);
exec("rm -rf {$parent['path']} 2>/dev/null");

// Мутации для потомков
$mutations = [
    ['name'=>'features_first','apply'=>fn($c)=>str_replace('// Evaluate all expressions',"foreach(\$feats as \$n=>\$v){\$e=true;for(\$i=0;\$i<\$n;\$i++)if(abs(\$v[\$i]-\$y[\$i])>0.001){\$e=false;break;}if(\$e)return[true,0.0,\$n];}\n        // Evaluate all expressions",$c)],
    ['name'=>'smaller_slices','apply'=>fn($c)=>preg_replace('/array_slice\(\$l1\w+,\s*\d+,\s*\K\d+/','30',$c)],
];

$bestChild = null; $bestScore = -1;
foreach ($mutations as $i => $mut) {
    $child = $spawner->spawn(['search_depth'=>3,'bees'=>2,'grammar_ops'=>['+','−','×','/','abs'],'port'=>29002+$i]);
    $searchPath = $child['path'] . '/src/Search.php';
    file_put_contents($searchPath, $mut['apply'](file_get_contents($searchPath)));
    $bench = $spawner->benchmark($child, $testTasks);
    $score = score($bench);
    exec("rm -rf {$child['path']} 2>/dev/null");
    if ($score > $bestScore) { $bestScore = $score; $bestChild = $mut; }
}

echo "Parent: score=$parentScore | best_child: score=$bestScore\n";

if ($bestScore > $parentScore && $bestChild) {
    $realSearch = '~/.bee_swarm/src/Search.php';
    file_put_contents($realSearch, $bestChild['apply'](file_get_contents($realSearch)));
    echo "✅ SELF-REPLACED: {$bestChild['name']} applied. Restarting...\n";
    // Убиваем текущий RoadRunner и перезапускаем
    exec('kill $(lsof -t -i:8765) 2>/dev/null; sleep 1');
    exec('cd ~/.bee_swarm && ./rr serve > /dev/null 2>&1 &');
} else {
    echo "❌ No improvement. Parent survives.\n";
}

function score(array $b): float {
    $parts = explode('/', $b['tasks_solved']);
    $s = (int)($parts[0]??0); $t = (int)($parts[1]??1);
    return ($t>0?($s/$t)*100:0) + (1.0/($b['elapsed_sec']+0.01));
}
