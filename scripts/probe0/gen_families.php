<?php

// PROBE-0: генератор семейств. v5 — класс sdr: y = (c0+c1)/(c0−c1).
//
// ДВИЖКОВЫЕ ОГРАНИЧЕНИЯ (DEV-инварианты 14.10, AMENDMENT A1-бис):
// 1) born-атомы = бинарные пары (2-колоночный binding) — 2 терминала;
// 2) L2-pool = первые 40 L1-форм ПО ПОРЯДКУ ВСТАВКИ (практически x0-блок) —
//    закон sdr достижим A только когда c0,c1 отображаются в ранние колонки;
// 3) sdr НЕ имеет L1-шортката (нет тождества с квадратами) — B-форма через
//    атом появляется на L1, тогда как A на LATE-раскладках структурно
//    блокирована (DEPTH) при любом бюджете.
//
// Зоны:
//   EARLY-пермутации (c0,c1 → колонки 0..2): A сертифицирует (~5-7s, depth-2) —
//     discovery (законы → компрессор → атом) + DEV-sanity.
//   LATE-пермутации (c0,c1 → колонки 3..4): факторы закона вне пула —
//     A структурно отрицательна — HR headroom.
//
//   dev.json        — 4 EARLY (sanity A) + 4 LATE (headroom A) sdr-задач
//   discovery.json  — 4 реплики × (F1,F2 sdr EARLY)
//   h_sealed.json   — H1,H2 sdr LATE (HR) + H3 flux, H4 ratio, H5 composition,
//                     H6 pureprod (HI) + HN1,HN2 nulls, × 4 реплики

$outDir = $argv[1] ?? '';
if ($outDir === '') { fwrite(STDERR, "usage: gen_families.php <outDir>\n"); exit(2); }
@mkdir($outDir, 0777, true);

$seedOf = fn (string $key): int => (int) hexdec(substr(md5($key), 0, 7));
// c0,c1 → КОЛОНКИ 0,1 СТРОГО (− и / некоммутативны: (x1−x0) вне пула;
// некоммутативная пара достижима только как (x0 op x1) с rk=x0)
$early = [[0, 1, 2, 3, 4], [0, 1, 2, 4, 3], [0, 1, 3, 2, 4], [0, 1, 4, 3, 2]];
// c0,c1 → колонки 3..4 (LATE)
$late = [[3, 4, 0, 1, 2], [4, 3, 2, 1, 0], [2, 3, 4, 0, 1], [3, 2, 1, 4, 0]];
$allPerms = array_merge($early, $late);

// DEV: 4 EARLY (sanity: A решает) + 4 LATE (headroom: A структурно отрицательна)
$dev = [];
foreach ($early as $i => $perm) {
    $dev[] = ['id' => 'DEVE' . ($i + 1), 'class' => 'sdr', 'zone' => 'early',
        'seed' => $seedOf("PROBE0DEVE{$i}"), 'perm' => $perm, 'scale' => [1, 1, 1, 1, 1], 'noise' => 0.0];
}
foreach ($late as $i => $perm) {
    $dev[] = ['id' => 'DEVH' . ($i + 1), 'class' => 'sdr', 'zone' => 'late',
        'seed' => $seedOf("PROBE0DEVH{$i}"), 'perm' => $perm, 'scale' => [1, 1, 1, 1, 1], 'noise' => 0.0];
}
file_put_contents("$outDir/dev.json", json_encode(['replicates' => ['1' => $dev]], JSON_PRETTY_PRINT));

// Discovery: EARLY-раскладки (законы сертифицируемы → атом)
$discovery = ['replicates' => []];
for ($r = 1; $r <= 4; $r++) {
    $tasks = [];
    foreach ([0, 1] as $i) {
        $tasks[] = ['id' => 'F' . ($i + 1), 'class' => 'sdr',
            'seed' => $seedOf("PROBE0F{$r}{$i}"), 'perm' => $early[($r + $i) % 4],
            'scale' => [1, 1, 1, 1, 1], 'noise' => 0.0];
    }
    $discovery['replicates'][(string) $r] = $tasks;
}
file_put_contents("$outDir/discovery.json", json_encode($discovery, JSON_PRETTY_PRINT));

// H (sealed): HR = sdr LATE; HI = нерелевантные классы; nulls
$h = ['replicates' => []];
for ($r = 1; $r <= 4; $r++) {
    $tasks = [];
    foreach ([0, 1] as $i) {
        $tasks[] = ['id' => 'H' . ($i + 1), 'class' => 'sdr', 'zone' => 'late',
            'seed' => $seedOf("PROBE0H{$r}{$i}"), 'perm' => $late[($i + $r) % 4],
            'scale' => [1, 1, 1, 1, 1], 'noise' => 0.0, 'null_shuffle' => false];
    }
    foreach (['flux', 'ratio', 'composition', 'pureprod'] as $i => $class) {
        $tasks[] = ['id' => 'H' . ($i + 3), 'class' => $class,
            'seed' => $seedOf("PROBE0H{$r}" . ($i + 2)), 'perm' => $allPerms[($i + 2 + $r) % 8],
            'scale' => [1, 1, 1, 1, 1], 'noise' => 0.0, 'null_shuffle' => false];
    }
    foreach (['sdr', 'flux'] as $i => $class) {
        $tasks[] = ['id' => 'HN' . ($i + 1), 'class' => $class,
            'seed' => $seedOf("PROBE0HN{$r}{$i}"), 'perm' => $allPerms[($i + $r) % 8],
            'scale' => [1, 1, 1, 1, 1], 'noise' => 0.0, 'null_shuffle' => true];
    }
    $h['replicates'][(string) $r] = $tasks;
}
$hPath = "$outDir/h_sealed.json";
file_put_contents($hPath, json_encode($h, JSON_PRETTY_PRINT));
echo 'dev.json + discovery.json written; h_sealed.json written (SHA256 ' . hash_file('sha256', $hPath) . ")\n";
