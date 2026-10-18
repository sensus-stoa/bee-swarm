<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\Search;

// Проба A19: где именно константа/околоконстанта проходит гейт (EXP-040 wine-класс)
$cases = [
    'const-tr0' => ['const', 0.0],
    'const-tr02' => ['const', 0.2],
    'nearconst-tr02' => ['near', 0.2],
    'nearconst-tr0' => ['near', 0.0],
];
foreach ($cases as $name => [$kind, $tr]) {
    $X = [];
    $y = [];
    for ($i = 0; $i < 30; $i++) {
        if ($kind === 'const') {
            $X[] = [1.0];
        } else {
            $X[] = [1.0 + (($i % 2 === 0) ? 0.01 : -0.01)];
        }
        $y[] = 100.0 + (($i % 2 === 0) ? 3.0 : -3.0);
    }
    $g = Grammar::fromOps(Grammar::baseOpNames());
    [$found, $cv, $formula, $cvTest, $class, $diag] = Search::find($X, $y, $g, 1, null, $tr, 0.10);
    echo $name, ': found=', var_export($found, true), ' cv=', var_export($cv, true),
    ' cvTest=', var_export($cvTest, true), ' formula=', $formula, ' class=', $class, ' diag=', var_export($diag, true), PHP_EOL;
}
