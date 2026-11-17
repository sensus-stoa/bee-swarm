<?php
require __DIR__ . '/vendor/autoload.php';
$g = new BeeSwarm\Forager\DataSelfGenerator();
$ts = $g->fromMetrics();
foreach($ts as $t) if($t["name"]=="sleep→energy"){$d=$t["data"];break;}
$X=array_map(fn($r)=>[$r[0]],$d); $y=array_column($d,1);
$gr=new BeeSwarm\Core\Grammar();
$min=1.0; $N=30;
for($i=0;$i<$N;$i++){$s=$y;shuffle($s);$r=BeeSwarm\Core\Search::find($X,$s,$gr,2);if($r&&$r["cv"]<$min)$min=$r["cv"];}
$real=BeeSwarm\Core\Search::find($X,$y,$gr,2);
echo "rows=" . count($d) . " trials=$N minCV=" . round($min,4) . "\n";
echo "realCV=" . round($real["cv"]??99,4) . " " . (($real["cv"]??1)<$min?"POSSIBLE":"noise") . "\n";
