<?php
// build_features.php — сбор MOEX ISS + признаки для FIN-EXP-001
// Запуск: php build_features.php (в ~/.bee_swarm или /opt/bee_swarm)
// Требует: PHP curl. Выход: data/exp/fin/train/*.csv и oos/*.csv

$tickers = ['SBER', 'GAZP', 'LKOH', 'ROSN', 'VTBR', 'NLMK', 'GMKN', 'SNGS'];

function moexFetch(string $url, int $attempts = 4): ?string
{
    $ctx = stream_context_create(['http' => ['timeout' => 30, 'header' => "User-Agent: fin-exp/1.0\r\n"]]);
    for ($a = 0; $a < $attempts; $a++) {
        $json = @file_get_contents($url, false, $ctx);
        if ($json !== false) {
            return $json;
        }
        usleep(500000 * ($a + 1)); // 0.5, 1.0, 1.5s между ретраями
    }
    return null;
}

function moexHistory(string $ticker, string $from, string $till): array
{
    $rows = [];
    $start = 0;
    while (true) {
        $url = 'https://iss.moex.com/iss/history/engines/stock/markets/shares/boards/TQBR/securities/'
            . $ticker . '.json?iss.meta=off&from=' . $from . '&till=' . $till
            . '&history.columns=SECID,TRADEDATE,CLOSE,VOLUME&start=' . $start . '&limit=100';
        $json = moexFetch($url);
        if ($json === null) {
            // 09.08: молчаливый break резал данные (GAZP: 1695→420 строк).
            // Теперь обрыв виден — и файл не пишется (проверка полноты ниже).
            fwrite(STDERR, "ОБРЫВ $ticker на start=$start (4 попытки, нет ответа)\n");
            break;
        }
        $data = json_decode($json, true);
        $page = $data['history']['data'] ?? [];
        if (empty($page)) {
            break;
        }
        foreach ($page as $r) {
            if (isset($r[2]) && $r[2] !== null) {
                $rows[] = ['date' => $r[1], 'close' => (float) $r[2], 'vol' => (float) ($r[3] ?? 0)];
            }
        }
        $total = (int) ($data['history.cursor']['data'][0][1] ?? 0);
        $start += count($page);
        if (($total > 0 && $start >= $total) || count($page) < 100) {
            break;
        }
        usleep(200000); // вежливость к API
    }
    usort($rows, fn ($a, $b) => strcmp($a['date'], $b['date']));
    $rows = array_values(array_unique(array_map('serialize', $rows)));
    $rows = array_map('unserialize', $rows);
    return $rows;
}

function features(array $rows, int $i, int $horizonDays = 20): ?array
{
    $n = count($rows);
    if ($i < 61 || $i + $horizonDays >= $n) {
        return null;
    }
    $c = fn (int $k): float => $rows[$k]['close'];
    $ret = fn (int $k): float => $c($k) / $c($k - 1) - 1.0;
    $vol = 0.0;
    $rets20 = [];
    for ($k = $i - 19; $k <= $i; $k++) {
        $rets20[] = $ret($k);
    }
    $m = array_sum($rets20) / 20;
    foreach ($rets20 as $r) {
        $vol += ($r - $m) ** 2;
    }
    $vol = sqrt($vol / 20);
    $max52 = max(array_map($c, range(max(0, $i - 251), $i)));
    $min52 = min(array_map($c, range(max(0, $i - 251), $i)));
    $vol5 = array_sum(array_map($ret, range($i - 4, $i))) + 1.0;
    $vol5prev = array_sum(array_map($ret, range($i - 9, $i - 5))) + 1.0;
    $future = $c($i + $horizonDays) / $c($i) - 1.0;

    // КОНТРОЛЬНЫЕ ПРИЗНАКИ (хендофф 11.5/12.3 — обязательны):
    // weekday/dom — календарь (нулевая гипотеза), moon_phase — Луна (рой ДОЛЖЕН её похоронить),
    // rand_ctl_1/2 — чистый шум, детерминированный от даты (одинаков в train/oos, воспроизводим).
    $ts = strtotime($rows[$i]['date']);
    $weekday = (float) date('w', $ts);           // 0=вс .. 6=сб
    $dom = (float) date('j', $ts);               // 1..31
    $moonDays = ($ts - strtotime('2000-01-06')) / 86400.0;
    $moonPhase = fmod($moonDays, 29.53058867) / 29.53058867; // 0..1
    mt_srand(crc32($rows[$i]['date'] . ':ctl1'));
    $rand1 = mt_rand() / mt_getrandmax();
    mt_srand(crc32($rows[$i]['date'] . ':ctl2'));
    $rand2 = mt_rand() / mt_getrandmax();

    return [
        'date' => $rows[$i]['date'],
        'ret1' => $ret($i),
        'ret5' => $c($i) / $c($i - 5) - 1.0,
        'ret20' => $c($i) / $c($i - 20) - 1.0,
        // future_ret20 — 4-я позиция: попадает в первые 4 колонки forager'а
        // (целевая колонка «факторы, связанные с будущей доходностью»)
        'future_ret20' => $future,
        'ret60' => $c($i) / $c($i - 60) - 1.0,
        'vol20' => $vol,
        'vol5chg' => $vol5 / max(1e-9, $vol5prev),
        'dist52h' => $c($i) / $max52 - 1.0,
        'dist52l' => $c($i) / $min52 - 1.0,
        'vol_ratio' => $rows[$i]['vol'] / max(1e-9, $rows[$i - 5]['vol']),
        'weekday' => $weekday,
        'day_of_month' => $dom,
        'moon_phase' => $moonPhase,
        'rand_ctl_1' => $rand1,
        'rand_ctl_2' => $rand2,
    ];
}

$from = '2018-01-01';
$split = '2025-01-01';
$till = '2026-06-30';
@mkdir('data/exp/fin/train', 0777, true);
@mkdir('data/exp/fin/oos', 0777, true);

foreach ($tickers as $t) {
    $hist = moexHistory($t, $from, $till);
    if (count($hist) < 1500) {
        // 09.08: проверка полноты. 2018-2026 ≈ 2070 торговых дней; <1500 = обрыв API.
        fwrite(STDERR, "ПРОПУСК $t: мало данных (" . count($hist) . ") — файл НЕ перезаписан\n");
        continue;
    }
    $train = fopen("data/exp/fin/train/{$t}.csv", 'w');
    $oos = fopen("data/exp/fin/oos/{$t}.csv", 'w');
    $header = 'date,ret1,ret5,ret20,future_ret20,ret60,vol20,vol5chg,dist52h,dist52l,vol_ratio,weekday,day_of_month,moon_phase,rand_ctl_1,rand_ctl_2' . "\n";
    fwrite($train, $header);
    fwrite($oos, $header);
    foreach ($hist as $i => $row) {
        $f = features($hist, $i);
        if ($f === null) {
            continue;
        }
        $line = implode(',', [$f['date'], $f['ret1'], $f['ret5'], $f['ret20'], $f['future_ret20'],
            $f['ret60'], $f['vol20'], $f['vol5chg'], $f['dist52h'], $f['dist52l'], $f['vol_ratio'],
            $f['weekday'], $f['day_of_month'], $f['moon_phase'], $f['rand_ctl_1'], $f['rand_ctl_2']]) . "\n";
        if ($f['date'] < $split) {
            fwrite($train, $line);
        } else {
            fwrite($oos, $line);
        }
    }
    fclose($train);
    fclose($oos);
    echo "OK $t: train+oos\n";
}
echo "ГОТОВО: data/exp/fin/{train,oos}/*.csv\n";
