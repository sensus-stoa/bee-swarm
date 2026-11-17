<?php

declare(strict_types=1);

// WU DRIVER-CERT-V2 (01.11): единый источник cert-операций exp045.
// Вынесен из driver.php (тот без main-guard — require небезопасен).
// Канон v2 (owner 01.11, strict): сертификация = три независимых
// 60/40-сплита, ВСЕ ТРИ должны держать порог 0.10. Демонстрация
// уязвимости: E3 (partial channel, 1/5), E2 (found channel, 2/3 —
// prereg-тройка 0.0943/0.1062/0.1020 на 80/20; на 60/40 1/3).
// Калибровка: cert_forms_multisplit.php — 8/9 форм 5/5, boundary
// CV ≈ 0.289·noise (мультипликативный шум).

/**
 * Независимый 60/40 сплит по сиду (withheld 40% engine не видит).
 * Копия из driver.php — канон сплит-логики, изменения только здесь.
 */
function split_eval(array $X, array $y, int $seed): array
{
    $n = count($y);
    $idx = range(0, $n - 1);
    mt_srand($seed);
    for ($i = $n - 1; $i > 0; $i--) {
        $j = (int) floor(mt_rand() / (mt_getrandmax() + 1) * ($i + 1));
        [$idx[$i], $idx[$j]] = [$idx[$j], $idx[$i]];
    }
    $k = (int) floor($n * 0.6);
    $Xt = [];
    $yt = [];
    $Xe = [];
    $ye = [];
    foreach (array_slice($idx, 0, $k) as $i) {
        $Xt[] = $X[$i];
        $yt[] = $y[$i];
    }
    foreach (array_slice($idx, $k) as $i) {
        $Xe[] = $X[$i];
        $ye[] = $y[$i];
    }

    return [$Xt, $yt, $Xe, $ye];
}

function labels_to_x(string $formula): string
{
    $out = $formula;
    // RL-имена прячутся (sha1-гекс содержит 'c0'-подстроки), метки заменяются
    // ПРОСТОЙ заменой (склеенные 'c0minc2' конвертируются), имена возвращаются.
    $kept = [];
    $out = (string) preg_replace_callback('/RL[0-9a-f]{8}/', function ($m) use (&$kept) {
        $key = "\x00" . count($kept) . "\x00";
        $kept[$key] = $m[0];

        return $key;
    }, $out);
    // BUGFIX (0h no_eval, 01.11): range(0,4) — хардкод-пятёрка (тот же
    // класс, что labels-пятёрка 082997c): c5 не конвертился → смешанные
    // xN/cN → evalAtom('c5') → eval_fail у 25/30 found-форм. Width-
    // agnostic: конвертируем ЛЮБОЕ c<idx> (RL-имена защищены выше;
    // R-атомы пересобираются stats'ом уже по конверченным именам).
    $out = (string) preg_replace_callback('/c(\d+)/', fn ($m) => 'x' . $m[1], $out);

    return strtr($out, $kept);
}

/**
 * Независимая сертификация на withheld-срезе, x-пространство (строгий критерий, = exp044).
 */
function cert_x(string $formulaX, array $Xe, array $ye, ?string &$reason = null): ?float
{
    $vals = BeeSwarm\Core\ExpressionEvaluator::evaluateFormula($formulaX, $Xe);
    if (! is_array($vals) || count($vals) !== count($ye)) {
        $reason = is_array($vals) ? 'eval_count_mismatch' : 'eval_fail'; // 0h: late-wiring кандидат
        return null;
    }
    // EXP-051 (07.11, owner word (B)): DUAL-CHANNEL gate, min-logic — зеркало
    // поискового AFFINE-LAWS min-канала (Search.php:62-66). Канал 1 = канон
    // ratio p/y (STD/|mean| построчных отношений) — TAIL-DRIVEN под аддитивным
    // шумом: строки с малым |y| (например x3×x4² гарантирует min|y|~1e-4)
    // взрывают cv, и ТОЧНАЯ форма не сертифицируется (EXP-051-B: 0/15
    // островных сертов при sigma>=0.002). Канал 2 = MAE/mean|y| — без хвоста,
    // scale-invariant, пропорциональная семантика сохранена (форма обязана
    // оставаться пропорциональным законом; MAE/mean|y| инвариантен к a·y).
    // Сертификация = ЛЮБОЙ канал <= порога (min), 3/3-сплиты cert_v2 не меняются.
    $sumAbsY = 0.0;
    foreach ($ye as $yv) {
        $sumAbsY += abs((float) $yv);
    }
    $meanAbsY = $sumAbsY / max(1, count($ye));
    $ratios = [];
    $ae = 0.0;
    $okRatio = true;
    $reasonRatio = null;
    $reasonMae = null;
    foreach ($vals as $i => $p) {
        if (! is_numeric($p) || ! is_finite((float) $p)) {
            $reasonRatio ??= 'non_finite'; // 0h: div/0 внутри формы (колонка ~0)
            $reasonMae ??= 'non_finite';
            $okRatio = false;
            break;
        }
        if ((float) $ye[$i] == 0.0) {
            $okRatio = false; // канал 1 неприменим на этой строке; канал 2 продолжает
            $reasonRatio ??= 'zero_y';
        } else {
            $ratios[] = (float) $p / (float) $ye[$i];
        }
        $ae += abs((float) $p - (float) $ye[$i]);
    }
    $cands = [];
    if ($okRatio && $ratios !== []) {
        $mean = array_sum($ratios) / count($ratios);
        if ($mean == 0.0) {
            $reasonRatio ??= 'zero_mean';
        } else {
            $var = 0.0;
            foreach ($ratios as $rv) {
                $var += ($rv - $mean) ** 2;
            }
            $cands[] = sqrt($var / count($ratios)) / abs($mean);
        }
    }
    if ($meanAbsY == 0.0) {
        $reasonMae ??= 'zero_mean_abs_y';
    } elseif ($okRatio || $reasonRatio !== 'non_finite') {
        $cands[] = ($ae / max(1, count($ye))) / $meanAbsY;
    }
    if ($cands === []) {
        $reason = $reasonRatio ?? $reasonMae ?? 'eval_fail';
        return null;
    }
    $reason = null;

    return min($cands);
}

function cv_eval(string $formulaC, array $Xe, array $ye): ?float
{
    return cert_x(labels_to_x($formulaC), $Xe, $ye);
}

/**
 * CANON V2 (owner strict 01.11): тройной независимый 60/40-сплит,
 * ВСЕ ТРИ CV ≤ 0.10. Сплайты детерминированы сидом задачи
 * (taskSeed + sp*104729, sp=0,1,2) — воспроизводимы независимо от
 * кампанийного сида и глобального mt_srand-состояния.
 *
 * Возвращает ['cvs' => [3 float|null], 'pass' => bool, 'reason' => ?string].
 * cvs[i] === null => сплит не оценился (reason заполнен первым отказом);
 * pass = true только если все три не-null и ≤ 0.10.
 */
function cert_v2(string $formulaX, array $Xall, array $yall, int $taskSeed, ?string &$reason = null): ?array
{
    $cvs = [];
    $reason = null;
    for ($sp = 0; $sp < 3; $sp++) {
        [, , $Xe, $ye] = split_eval($Xall, $yall, $taskSeed + $sp * 104729);
        $r = null;
        $cv = cert_x($formulaX, $Xe, $ye, $r);
        if ($cv === null && $reason === null) {
            $reason = $r ?? 'eval_fail';
        }
        $cvs[] = $cv;
    }
    $pass = count(array_filter($cvs, fn ($v) => $v !== null && $v <= 0.10)) === 3;

    return ['cvs' => $cvs, 'pass' => $pass, 'reason' => $reason];
}
