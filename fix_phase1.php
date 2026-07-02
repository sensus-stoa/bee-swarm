<?php
$c = file_get_contents(__DIR__ . '/agenda.php');

// 1. Move $consecutiveNoDiscovery++ to always execute (before disabled fallback)
$c = str_replace(
    'if (!$foundAny) {' . "\n" . '        $consecutiveNoDiscovery++;',
    'if (!$foundAny) $consecutiveNoDiscovery++;
    if (false) {',
    $c
);

// 2. Disable compose
$c = str_replace(
    '// ═══ 2. COMPOSE ═══' . "\n" . '    if (!$foundAny',
    '// ═══ 2. COMPOSE (disabled) ═══' . "\n" . '    if (false',
    $c
);

// 3. Add plateau sleep
$c = str_replace(
    'usleep(200000); // base tick: 200ms',
    'if ($consecutiveNoDiscovery > 50) {
        if ($consecutiveNoDiscovery === 51) roeLog("🏔️ PLATEAU");
        usleep(10000000);
    } else {
        usleep(200000);
    }',
    $c
);

file_put_contents(__DIR__ . '/agenda.php', $c);
echo "OK\n";
