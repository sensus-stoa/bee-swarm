<?php

declare(strict_types=1);

namespace BeeSwarm\Core;

class Search
{
    /** PARSIMONY: штраф за символ формулы (C) калибруемый, из gplearn */
    private const PARSIMONY_LAMBDA = 5e-5;

    public static function cv(array $vec, array $y, float $shift = 0.0): float
    {
        $n = count($vec);
        $exact = true;
        for ($i = 0; $i < $n; $i++) {
            // NaN/INF — артефакт, не закон (бейзлайн 05.08: R× переполнение)
            if ($vec[$i] === null || ! is_finite($vec[$i]) || abs($vec[$i] - $y[$i]) > 0.0001) {
                $exact = false;
                break;
            }
        }
        if ($exact) {
            return 0.0;
        }

        // AFFINE-LAWS (ЭКСП-012): при знакопеременной цели y (переход через 0)
        // ratio = pred/y не определён (деление на 0, знакопеременный CV→∞).
        // Сдвиг: ratio = (pred−shift)/(y−shift), shift = min(y)−1 → знаменатель > 0.
        $ratio = [];
        for ($i = 0; $i < $n; $i++) {
            $ratio[] = ($vec[$i] - $shift) / ($y[$i] - $shift + 1e-8);
        }
        $mean = array_sum($ratio) / $n;
        if (abs($mean) < 1e-8) {
            return 9.99;
        }
        $variance = 0;
        foreach ($ratio as $r) {
            $variance += ($r - $mean) ** 2;
        }
        return sqrt($variance / $n) / abs($mean);
    }

    /**
     * @param float $budgetSec Полный wall-clock бюджет (включая подготовку фич!),
     *   0.0 = без лимита. По истечении: [false, 9.99, 'none', 9.99, 'TIMEOUT'].
     *   'TIMEOUT' и 'none' оба = «нет результата» — потребители обязаны
     *   проверять $res[0] (found) ДО чтения score/expr (9.99 = sentinel!).
     *   Семантика: бюджет = тотальный wall-clock (как timeout PySR 30s).
     */
    public static function find(array $X, array $y, Grammar $grammar, int $depth = 2, ?array $colLabels = null, float $testRatio = 0.0, float $cvTrainMax = 0.15, float $budgetSec = 0.0): array
    {
        // ЭКСП-018b: микро-профиль Search (SEARCH_PROFILE=1)
        SearchProfiler::registerShutdown();
        $p0 = microtime(true);
        $deadline = $budgetSec > 0.0 ? $p0 + $budgetSec : INF;
        if (microtime(true) > $deadline) {
            return [false, 9.99, 'none', 9.99, 'TIMEOUT'];
        }
        $n = count($y);
        if ($n === 0 || empty($X) || empty($X[0])) {
            return [false, 9.99, 'none', 9.99, 'NONE'];
        }
        $nFeat = count($X[0]);

        // Feature naming: colLabels[0]='price' → feature key 'price' instead of 'x0'
        $featName = fn (int $i): string => $colLabels[$i] ?? "x{$i}";

        // L0: Features
        $feats = [];
        for ($i = 0; $i < $nFeat; $i++) {
            if (microtime(true) > $deadline) {
                return [false, 9.99, 'none', 9.99, 'TIMEOUT'];
            }
            $col = array_column($X, $i);
            $fname = $featName($i);
            $feats[$fname] = $col;
            $feats["{$fname}²"] = array_map(fn ($v) => $v * $v, $col);
        }
        foreach ([1.0, 2.0] as $c) {
            $feats["K{$c}"] = array_fill(0, $n, $c);
        }
        foreach ($grammar->all() as $op) {
            if (preg_match('/^K[_-]?(\d+(\.\d+)?)$/', $op, $m)) {
                $feats[$op] = array_fill(0, $n, (float) $m[1]);
            }
        }

        // GlobalReduce: arity bridge (float[]→float) via Grammar::reduce
        // S1.9 Phase 2 — reduce on top-level feature columns
        // Constants (R+x0, R×x0, Rmaxx0, Rminx0) enter L1/L2 pool
        // Pointwise expressions enter expression pool directly
        $reduceAssoc = ['+', '×', 'max', 'min'];
        // Collect raw feature names (first $nFeat entries, before squared versions)
        $rawFeatNames = [];
        for ($i = 0; $i < $nFeat; $i++) {
            $rawFeatNames[$featName($i)] = true;
        }
        $rawFeatKeys = array_keys($feats);
        foreach ($rawFeatKeys as $fname) {
            if (microtime(true) > $deadline) {
                return [false, 9.99, 'none', 9.99, 'TIMEOUT'];
            }
            if (!isset($rawFeatNames[$fname])) continue; // only raw features
            $col = $feats[$fname];
            // Skip non-numeric columns (text data, labels, etc.)
            $allNumeric = true;
            foreach ($col as $v) {
                if (!is_float($v) && !is_int($v)) { $allNumeric = false; break; }
            }
            if (!$allNumeric) continue;
            foreach ($reduceAssoc as $rop) {
                $reduced = $grammar->reduce($rop, $col);
                if ($reduced === null || abs($reduced) < 1e-10) continue;

                // Reduce constant: enters L1/L2 pool
                $cname = "R{$rop}{$fname}";
                $feats[$cname] = array_fill(0, $n, $reduced);

                // Pointwise: (xj / reduce(op, xj)) — normalization
                $pnameDiv = "({$fname}/R{$rop}{$fname})";
                $vecDiv = [];
                for ($i = 0; $i < $n; $i++) {
                    $r = $grammar->apply((float)$col[$i], $reduced, '/');
                    $vecDiv[] = $r ?? 0.0;
                }
                $feats[$pnameDiv] = $vecDiv;

                // Pointwise: (xj - reduce(op, xj)) — centering
                // Only for min/max (subtracting sum/product is meaningless)
                if ($rop === 'min' || $rop === 'max') {
                    $pnameSub = "({$fname}-R{$rop}{$fname})";
                    $vecSub = [];
                    for ($i = 0; $i < $n; $i++) {
                        $r = $grammar->apply((float)$col[$i], $reduced, '−');
                        $vecSub[] = $r ?? 0.0;
                    }
                    $feats[$pnameSub] = $vecSub;
                }
            }

            // Range constant: (Rmaxxj - Rminxj) — useful for min-max normalization
            $rmax = $grammar->reduce('max', $col);
            $rmin = $grammar->reduce('min', $col);
            if ($rmax !== null && $rmin !== null && abs($rmax - $rmin) > 1e-10) {
                $range = $rmax - $rmin;
                $feats["Rrange{$fname}"] = array_fill(0, $n, $range);

                // Pointwise min-max norm: (xj - Rminxj) / range
                $pnameNorm = "(Rnorm{$fname})";
                $vecNorm = [];
                for ($i = 0; $i < $n; $i++) {
                    $num = $grammar->apply((float)$col[$i], $rmin, '−') ?? 0.0;
                    $den = $range;
                    $vecNorm[] = $den != 0 ? ($num / $den) : 0.0;
                }
                $feats[$pnameNorm] = $vecNorm;
            }
        }

        $exprs = $feats;
        $featKeys = array_keys($feats);
        $ops = $grammar->all();
        $bKeys = [];
        // B-AS-ARGUMENT (09.08): born-атомы читаем ДО L1 — B(фича,фича)
        // в L1-pairwise (как add). Раньше bornBinary применялся только к
        // парам L1-элементов (не фичам!) → (x0 B7a7aee x1) не генерировался.
        $bornBinary = [];
        if (getenv('NO_BIRTH') !== '1') {
            try {
                // EXP-035 DEBUG: bornBinary dump
                if (getenv('SEARCH_DEBUG') === '1') {
                    fwrite(STDERR, '[SD-B] before query: ' . microtime(true) . PHP_EOL);
                }
                // BINARY-B-CAP (09.08, ЭКСП-022o ноут): 30+ атомов × 1600 пар
                // в L2 = 48K evaluateFormula на тик (тик-бомба!). Cap 3.
                // ORDER BY length: КОРОТКИЕ определения (B11=(x0+x1) — 7
                // символов) впереди длинных (B13/B14) — иначе cap отрезает
                // нужный атом (022o: B11 не в первых 3 по id → add выиграл).
                $bCap = max(1, (int) (getenv('BINARY_B_CAP') ?: '3'));
                // REUSE-CRITERION-BIRTH (10.08): ACTIVE атомы приоритетнее
                // кандидатов (закреплённые культурой — впереди, при cap).
                // ДЕДУП по definition: атомы-дубли (BC1/B4 — один definition)
                // не забивают cap 3. ROW_NUMBER-вариант падал («no such
                // column: status» в подзапросе, 10.08) — GROUP BY + ORDER BY
                // (активный, короткий, первый по id — фактически детерминизм).
                $sql = 'SELECT name, definition FROM grammar_ops
                    WHERE source = ? AND definition LIKE ? AND definition LIKE ?
                    GROUP BY definition
                    ORDER BY CASE WHEN status = \'active\' THEN 0 ELSE 1 END, length(definition), MIN(id)
                    LIMIT ' . $bCap;
                $stmt = \BeeSwarm\Infra\Database::get()->prepare($sql);
                $stmt->execute(['birth', '%x0%', '%x1%']);
                foreach ($stmt->fetchAll() as $bb) {
                    $bornBinary[$bb['name']] = $bb['definition'];
                    if (getenv('SEARCH_DEBUG') === '1') {
                        fwrite(STDERR, '[SD-B] bornBinary: ' . $bb['name'] . ' = ' . $bb['definition'] . PHP_EOL);
                    }
                }
            } catch (\Throwable $e) {
                $bornBinary = [];
            }
        }

        $pGen = microtime(true);
        // L1: pairwise on features.
        // R-BLOAT-FIX (10.08): ВСЕ производные ключи ($featKeys) попадали
        // в pairwise → квадратичный раздув: 12 фич → 174K форм, 1.6GB
        // (вино!). Правило: raw×raw + raw×R-КОНСТАНТЫ (аффинные:
        // (x0−Rminx0)=x0−2, (x0/Rmaxx0) — РЕГРЕССИЯ без них:
        // AffineLawsTest!). R×R (константа op константа) — не генерируем.
        // R-BLOAT-FIX v3 (10.08): pairwise по ВСЕМ ключам (фичи, ², нормы
        // (x/Rsumx), R-константы, K-константы) КРОМЕ пар константа×константа
        // (R×R, K×K — мусор). Регрессии: (а) исключение производных убило
        // (x0/Rsumx0)+x1 (SearchTest::testFindReduceWithMultipleColumns!);
        // (б) исключение констант убило y=x−2 (AffineLawsTest). Итог:
        // const-фильтр только на ОБА операнда.
        $isConstKey = fn (string $k): bool =>
            str_starts_with($k, 'R') || str_starts_with($k, 'K');
        for ($a = 0; $a < count($featKeys); $a++) {
            if ($isConstKey($featKeys[$a])) {
                continue; // константа будет ПРАВЫМ операндом ниже
            }
            for ($b = $a + 1; $b < count($featKeys); $b++) {
                if ($isConstKey($featKeys[$b])) {
                    continue; // (const op const) — мусор
                }
                $va = $feats[$featKeys[$a]];
                $vb = $feats[$featKeys[$b]];
                foreach ($ops as $op) {
                    $vec = [];
                    for ($i = 0; $i < $n; $i++) {
                        $r = $grammar->apply($va[$i], $vb[$i], $op);
                        $vec[] = $r ?? 0.0;
                    }
                    $exprs["({$featKeys[$a]}$op{$featKeys[$b]})"] = $vec;
                }
                // B-AS-ARGUMENT: B(фича, фича) в L1-pairwise
                foreach ($bornBinary as $bbName => $bbDef) {
                    $vec = [];
                    for ($i = 0; $i < $n; $i++) {
                        $r = \BeeSwarm\Core\ExpressionEvaluator::evaluateFormula(
                            $bbDef, [[$va[$i], $vb[$i]]]
                        );
                        $vec[] = $r[0] ?? 0.0;
                    }
                    $name = "({$featKeys[$a]}$bbName{$featKeys[$b]})";
                    $exprs[$name] = $vec;
                    $bKeys[] = $name;
                }
            }
        }
        // (raw/нормы) × константы: (x0−Rminx0)=x0−2, (x0/K2), (x0×K2) —
        // аффинные. Однонаправленно: (не-const op const); (const op x) —
        // не нужен (коммутативные ops покрывают; − и / — не-закон).
        foreach ($featKeys as $rk) {
            if ($isConstKey($rk)) {
                continue;
            }
            $va = $feats[$rk];
            foreach ($featKeys as $ck) {
                if (! $isConstKey($ck)) {
                    continue;
                }
                $vb = $feats[$ck];
                foreach ($ops as $op) {
                    $vec = [];
                    for ($i = 0; $i < $n; $i++) {
                        $r = $grammar->apply($va[$i], $vb[$i], $op);
                        $vec[] = $r ?? 0.0;
                    }
                    $exprs["({$rk}$op{$ck})"] = $vec;
                }
            }
        }

        // L1 unary: apply unary ops to each feature
        $unaryOps = $grammar->getUnaryOps();
        foreach ($featKeys as $fname) {
            foreach ($unaryOps as $uname) {
                $vec = [];
                for ($i = 0; $i < $n; $i++) {
                    $r = $grammar->apply($feats[$fname][$i], 0.0, $uname);
                    $vec[] = $r ?? 0.0;
                }
                $exprs["({$fname}{$uname})"] = $vec;
            }
        }

        // Get L1 keys (everything added after features)
        $l1Keys = array_values(array_diff(array_keys($exprs), $featKeys));

        // L1 squared
        $l1Sq = [];
        foreach (array_slice($l1Keys, 0, 200) as $name) {
            $vec = $exprs[$name];
            $exprs["({$name})²"] = array_map(fn ($v) => $v * $v, $vec);
            $l1Sq[] = "({$name})²";
        }

        // 🔥 Unary on L1 results
        $l1Unary = [];
        foreach (array_slice($l1Keys, 0, 200) as $name) {
            foreach ($unaryOps as $uname) {
                $vec = [];
                $baseVec = $exprs[$name];
                for ($i = 0; $i < $n; $i++) {
                    $r = $grammar->apply($baseVec[$i], 0.0, $uname);
                    $vec[] = $r ?? 0.0;
                }
                $exprs["({$name}{$uname})"] = $vec;
                $l1Unary[] = "({$name}{$uname})";
            }
        }

        // L2: combinations of (L1 + L1² + L1-unary)
        if (microtime(true) > $deadline) {
            return [false, 9.99, 'none', 9.99, 'TIMEOUT'];
        }
        $l2Keys = [];
        if ($depth >= 2) {
            $checkCount = 0;
            $pool = array_merge(
                array_slice($l1Keys, 0, 40),
                array_slice($l1Sq, 0, 30),
                array_slice($l1Unary, 0, 30)
            );
            // SEARCH-BEAM-OPT (ЭКСП-019): мягкий beam — топ-K по быстрому
            // CV + random-хвост. Посредственные родители не теряются
            // (x0, x1 плохие, но (x0−x1) — закон).
            $beamK = (int) (getenv('SEARCH_BEAM_K') ?: '0');
            if ($beamK > 0) {
                $beamRand = (int) (getenv('SEARCH_BEAM_RANDOM') ?: '5');
                $quickN = min($n, max(4, (int) ($n * 0.25)));
                $scored = [];
                foreach ($pool as $pname) {
                    if ((++$checkCount & 31) === 0 && microtime(true) > $deadline) {
                        return [false, 9.99, 'none', 9.99, 'TIMEOUT'];
                    }
                    $pv = $exprs[$pname];
                    $r = [];
                    for ($i = 0; $i < $quickN; $i++) {
                        $den = $y[$i] + 1e-8;
                        $r[] = abs($pv[$i] / $den);
                    }
                    $m = array_sum($r) / $quickN;
                    $v = 0.0;
                    foreach ($r as $rr) { $v += ($rr - $m) ** 2; }
                    $scored[] = ['n' => $pname, 'cv' => sqrt($v / $quickN) / (abs($m) + 1e-8)];
                }
                // COMPRESSION-CRITERION (09.08): tie по CV → КОРОЧЕ выше
                // (B-форма (x0B4x1) короче add-формы → в top-K beam,
                // иначе exact-tie забивали слоты add-формами)
                usort($scored, function (array $a, array $b): int {
                    $cv = $a['cv'] <=> $b['cv'];
                    if ($cv !== 0) {
                        return $cv;
                    }
                    return strlen($a['n']) <=> strlen($b['n']);
                });
                $top = array_slice($scored, 0, $beamK);
                $rest = array_slice($scored, $beamK);
                shuffle($rest);
                $randTail = array_slice($rest, 0, $beamRand);
                $pool = array_map(fn (array $x): string => $x['n'], array_merge($top, $randTail));
            }
            // B-AS-ARGUMENT (09.08): bornBinary прочитан ДО L1 (см. выше) —
            // здесь только применение к парам pool-элементов
            $bornBinary = $bornBinary ?? [];
            for ($a = 0; $a < count($pool); $a++) {
                $va = $exprs[$pool[$a]];  // hoisted
                for ($b = $a + 1; $b < count($pool); $b++) {
                    $vb = $exprs[$pool[$b]];
                    foreach ($ops as $op) {
                        $vec = [];
                        for ($i = 0; $i < $n; $i++) {
                            $r = $grammar->apply($va[$i], $vb[$i], $op);
                            $vec[] = $r ?? 0.0;
                        }
                        $name = "({$pool[$a]}$op{$pool[$b]})";
                        $exprs[$name] = $vec;
                        $l2Keys[] = $name;
                    }
                    // Бинарные born-атомы: B(va, vb)
                    foreach ($bornBinary as $bbName => $bbDef) {
                        $vec = [];
                        for ($i = 0; $i < $n; $i++) {
                            $r = \BeeSwarm\Core\ExpressionEvaluator::evaluateFormula(
                                $bbDef, [[$va[$i], $vb[$i]]]
                            );
                            $vec[] = $r[0] ?? 0.0;
                        }
                        $name = "({$pool[$a]}$bbName{$pool[$b]})";
                        $exprs[$name] = $vec;
                        $l2Keys[] = $name;
                        $bKeys[] = $name; // B-AS-ARGUMENT: B-формы в L2L1
                    }
                }
            }
        }

        // SEARCH-L2L1 (09.08): L3 = L1 op Фича — композиции второго уровня.
        // (x0+x1) — L1-уровень; без L1×фича (x0+x1)×x2 невыразим →
        // transfer-тест невалиден (ЭКСП-022d). top-30 L1 × фичи × ops.
        if ($depth >= 3 && ! empty($l1Keys)) {
            // B-AS-ARGUMENT: B-формы (B7a7aee×x2) в L2L1 — без них атомы
            // не участвуют в двухуровневых композициях
            $l1Top = array_slice(array_merge($bKeys ?? [], $l1Keys), 0, 30); // bKeys ВПЕРЕДИ (иначе slice-30 отрезает B-формы — EXP-035)
            // L2L1: $ops = ВСЯ грамматика (прод: 3562 ops!) → 30×12×3562×n —
            // вечность на проде. Cap до top-50 (как beam) + проверка бюджета.
            $l2l1Ops = array_slice($ops, 0, 50);
            $l2l1Count = 0;
            foreach ($l1Top as $l1name) {
                if ((++$l2l1Count & 15) === 0 && microtime(true) > $deadline) {
                    return [false, 9.99, 'none', 9.99, 'TIMEOUT'];
                }
                foreach ($featKeys as $fname) {
                    foreach ($l2l1Ops as $op) {
                        $vec = [];
                        for ($i = 0; $i < $n; $i++) {
                            $r = $grammar->apply($exprs[$l1name][$i], $exprs[$fname][$i], $op);
                            $vec[] = $r ?? 0.0;
                        }
                        $name = "({$l1name}$op{$fname})";
                        $exprs[$name] = $vec;
                        $l2Keys[] = $name;
                    }
                }
            }
        }

        // L3: L2 / constant (для MIN = (...)/2)
        if (microtime(true) > $deadline) {
            return [false, 9.99, 'none', 9.99, 'TIMEOUT'];
        }
        if ($depth >= 3) {
            // EXP-035 (27.08): L2 ÷ ФИЧА — heat-законы κ(T2−T1)A/d требуют
            // деления на ПЕРЕМЕННУЮ (d), не константу. Old: только K*.
            $yMaxAbs = 1.0;
            foreach ($y as $yv) { $yMaxAbs = max($yMaxAbs, abs((float)$yv)); }
            // ОГРАНИЧЕНИЕ: только '/' (выразимость heat) — взрыв контролируем
            // делителем кол-ва L2.
            $constKeys = array_filter($featKeys, fn ($k) => str_starts_with($k, 'K'));
            $l3Count = 0;
            foreach ($l2Keys as $l2name) {
                if ((++$l3Count & 31) === 0 && microtime(true) > $deadline) {
                    return [false, 9.99, 'none', 9.99, 'TIMEOUT'];
                }
                foreach ($constKeys as $ck) {
                    $vec = [];
                    $cvec = $feats[$ck];
                    for ($i = 0; $i < $n; $i++) {
                        $r = $grammar->apply($exprs[$l2name][$i], $cvec[$i], '/');
                        $vec[] = $r ?? 0.0;
                    }
                    $exprs["({$l2name}/{$ck})"] = $vec;
                }
                // EXP-035: L2 / фича — переменный делитель (heat /d).
                // Взрыв-гвард: time-based (deadline), не количественная —
                // иначе нужный l2 (B×x2 за топ-50) отрезается.
                if (microtime(true) < $deadline) {
                    // SEMANTIC GUARD v2 (EXP-035): L2/фича — только для
                    // ПЕРСПЕКТИВНЫХ l2: |corr(l2,y)|>0.3 или l2name имеет B-атом.
                    // Фильтр по полезности, не по имени — работает и для
                    // B-имён, и для B-колонок (урок фазы 3b: chunk вшит в данные).
                    $l2vec = $exprs[$l2name];
                    $hasB = preg_match('/B[0-9a-f]{2,}/', $l2name) === 1;
                    if (! $hasB) {
                        $corr = self::quickCorr($l2vec, $y);
                        if (abs($corr) < 0.3) {
                            continue;
                        }
                    }
                    foreach ($featKeys as $fk) {
                        if ($fk === $l2name) continue;
                        $vec = [];
                        for ($i = 0; $i < $n; $i++) {
                            $denom = $feats[$fk][$i];
                            $vec[] = (abs($denom) < 1e-12) ? null : ($exprs[$l2name][$i] / $denom);
                        }
                        $exprs["({$l2name}/{$fk})"] = $vec;
                    }
                }
            }
        }

        // Evaluate FEATURES first (fast path)
        $bestExact = null; // COMPRESSION-CRITERION: кратчайший exact (10.08: было после — undefined в features-цикле!)
        foreach ($feats as $name => $vec) {
            $exact = true;
            for ($i = 0; $i < $n; $i++) {
                // NaN/INF не могут быть законом (артефакт переполнения R×)
                // EXP-035: null (ноль-делитель в L2/фича) — не точный закон
                if ($vec[$i] === null || ! is_finite($vec[$i]) || abs($vec[$i] - $y[$i]) > 0.0001) {
                    $exact = false;
                    break;
                }
            }
            if ($exact) {
                self::preregisterExact($name);
                // COMPRESSION-CRITERION (09.08): exact-путь выбирает
                // КРАТЧАЙШУЮ формулу — иначе add-форма (раньше в порядке)
                // всегда выигрывает у B1-формы → атом не используется
                if ($bestExact === null || strlen($name) < strlen($bestExact)) {
                    $bestExact = $name;
                }//exact
            }
        }
        // AFFINE-LAWS (ЭКСП-012): сдвиг для знакопеременных целей
        $minY = min($y);
        $maxY = max($y);
        $affineShift = ($minY < 0 && $maxY > 0) ? $minY - 1.0 : 0.0;

        // Evaluate all expressions — top-K кандидатов (SEARCH-TOP-K, ЭКСП-009):
        // на шуме лучший по train часто R-подгонка (CV_test=9.99), а закон
        // (2-я кандидатка, CV_test=0.004) терялся. Храним K лучших по train,
        // выбор — по held-out.
        // SEARCH-TOP-K (ЭКСП-009 + CONCERNS deleg_1ebc06b4): на шуме train-CV
        // не отличает закон от R-подгонок (все ~0.03-0.05), held-out отличает
        // (закон 0.004, подгонки 9.99). top-K по train теряет закон (2x вне
        // top-30). Решение: ВСЕ правдоподобные (CV_train < cvTrainMax),
        SearchProfiler::add(microtime(true) - $pGen, 0.0, 0.0);
        $pCv = microtime(true);
        // testCv каждого (дёшево), лучший по тесту = закон.
        $plausible = [];
        $bestExact = null; // COMPRESSION-CRITERION: кратчайший exact
        foreach ($exprs as $name => $vec) {
            $exact = true;
            for ($i = 0; $i < $n; $i++) {
                // NaN/INF не могут быть законом (артефакт переполнения R×)
                if ($vec[$i] === null || ! is_finite($vec[$i]) || abs($vec[$i] - $y[$i]) > 0.0001) {
                    $exact = false;
                    break;
                }
            }
            if ($exact) {
                self::preregisterExact($name);
                // COMPRESSION-CRITERION (09.08): exact-путь выбирает
                // КРАТЧАЙШУЮ формулу — иначе add-форма (раньше в порядке)
                // всегда выигрывает у B1-формы → атом не используется
                if ($bestExact === null || strlen($name) < strlen($bestExact)) {
                    $bestExact = $name;
                }//exact
            }

            $std = self::stddev($vec);
            if ($std < 1e-6) {
                continue;
            }
            $cv = self::cv($vec, $y, $affineShift);
            if ($cv < $cvTrainMax) {
                $plausible[] = ['cv' => $cv, 'name' => $name];
            }
        }

        if ($bestExact !== null) {
            // REUSE-TOUCH-ATOM (10.08): применение в точке использования!
            // Имя атома известно в момент победы — регистрируем reuse.
            if (preg_match('/B\d+/', (string) $bestExact, $m) === 1) {
                \BeeSwarm\Core\Grammar::registerReuse($m[0], 'search');
            }
            return [true, 0.0, $bestExact, 0.0, 'EMPIRICAL'];
        }

        if (getenv('SEARCH_DEBUG') === '1') {
            fwrite(STDERR, '[SD] exprs=' . count($exprs) . ' plausible=' . count($plausible) . PHP_EOL);

            $top = array_slice($plausible, 0, 3);
            foreach ($top as $t) fwrite(STDERR, '[SD] top: ' . $t['name'] . ' cv=' . number_format($t['cv'], 4) . PHP_EOL);
            // EXP-035: прямой cv целевой формы
            $target = '((x0BPf474x1)×x2)';
            if (isset($exprs[$target])) {
                $cvT = self::cv($exprs[$target], $y, $affineShift);
                fwrite(STDERR, '[SD] TARGET ' . $target . ' cv=' . number_format($cvT, 5) . PHP_EOL);
                $t2 = '(((' . 'x0BPf474x1)×x2)/x3)';
                if (isset($exprs[$t2])) {
                    $cvT2 = self::cv($exprs[$t2], $y, $affineShift);
                    fwrite(STDERR, '[SD] TARGET-L3 ' . $t2 . ' cv=' . number_format($cvT2, 5) . PHP_EOL);
                } else {
                    fwrite(STDERR, '[SD] TARGET-L3 отсутствует в exprs' . PHP_EOL);
                }
            } else {
                fwrite(STDERR, '[SD] TARGET-L2 отсутствует' . PHP_EOL);
            }
        }
        usort($plausible, fn (array $a, array $b): int => $a['cv'] <=> $b['cv']);
        SearchProfiler::add(0.0, microtime(true) - $pCv, 0.0);
        $pTest = microtime(true);

        // PARSIMONY: при testRatio=0 (без теста) выбираем короткую среди
        // равных по cv — score = cv + λ·len. При testRatio>0 — в test-блоке.
        if ($testRatio <= 0.0 && ! empty($plausible)) {
            $lambda = self::PARSIMONY_LAMBDA;
            $bestScore = 9.99;
            foreach ($plausible as $cand) {
                $score = $cand['cv'] + $lambda * strlen($cand['name']);
                if ($score < $bestScore) {
                    $bestScore = $score;
                    $bestCv = $cand['cv'];
                    $bestName = $cand['name'];
                }
            }
        } else {
            $bestCv = $plausible[0]['cv'] ?? 9.99;
            $bestName = $plausible[0]['name'] ?? null;
        }
        $cv_train = $bestCv < $cvTrainMax ? $bestCv : 9.99;
        $cv_test = $cv_train;

        // S2.1 PREREGISTRATION (08.08): ФАЗА 1 — гипотезы фиксируются
        // PENDING ДО held-out (HARKing-защита: формула + cv_train + tick
        // записаны ДО того, как тест увиден). UPDATE статуса — после.
        $preregIds = [];
        // PREREG (08.08): транзакция + топ-30 (сотни INSERT вне транзакции =
        // fsync на каждый = тик ×N; статистике хватает топ-30).
        // SEARCH_NO_PREREG=1 — полное отключение для скоростных прогонов.
        if (getenv('SEARCH_NO_PREREG') !== '1'
            && $testRatio > 0.0 && $n > 10 && ! empty($plausible)) {
            $prDb = \BeeSwarm\Infra\Database::get();
            $prDb->beginTransaction();
            try {
                $prIns = $prDb->prepare(
                    'INSERT INTO preregistrations (formula, domain, cv_predicted, tick, status)
                     VALUES (?,?,?,?,?)'
                );
                foreach (array_slice($plausible, 0, 30) as $prCand) {
                    $prIns->execute([$prCand['name'], '', $prCand['cv'], 0, 'PENDING']);
                    $preregIds[$prCand['name']] = (int) $prDb->lastInsertId();
                }
                $prDb->commit();
            } catch (\Throwable $e) {
                if ($prDb->inTransaction()) {
                    $prDb->rollBack();
                }
            }
        }

        // V0.8.5 + SEARCH-TOP-K: out-of-sample — лучший по ТЕСТУ среди ВСЕХ
        // правдоподобных (R-подгонки с test=9.99 отсеиваются, закон проходит)
        if ($testRatio > 0.0 && $n > 10 && ! empty($plausible)) {
            $splitIdx = (int) ($n * (1.0 - $testRatio));
            if ($splitIdx > 5 && $splitIdx < $n - 1) {
                $X_test = array_slice($X, $splitIdx);
                $y_test = array_slice($y, $splitIdx);
                // PARSIMONY-SELECTION (P0): score = testCv + λ·len —
                // сложные R-тени (len~60) штрафуются при равном testCv
                $lambda = self::PARSIMONY_LAMBDA; // (C) калибруемый: 0.00005/символ
                $bestTestScore = 9.99;
                $bestTestCv = 9.99;
                $bestTestName = null;
                $bestTestTrainCv = 9.99;
                $X_train_cv = array_slice($X, 0, $splitIdx);
                // NON-CONSTANCY (10.08, ЭКСП-026/MOEX): константные псевдозаконы
                // проходят CV через shift: знакопеременный y → shift=min(y)−1 →
                // ratio сглаживается → (x0/R+x0) на ШУМЕ CV=0.028 < 0.15.
                // NULL-ФИЛЬТР (вариант B стори): CV формулы на ЦИКЛИЧЕСКИ
                // сдвинутом y (детерминированно!) — если сигнал НЕ лучше
                // шума (cvReal >= nullCv − margin) — REFUTED (нет информации).
                // NULL-SIGNAL-RATIO (10.08, «обратный Парето»): сигнал должен
                // держать ≤80% от шума (20% диссипации). Параметр для
                // эволюции: пчела может мутировать порог сжатия.
                // Калибровка 10.08 (итерация 3): псевдозакон t/null≈0.79,
                // слабые реальные (FullPipeline) 0.52-0.65 (флуктуация 1/3
                // прогонов резала при 0.6!). Порог 0.55: псевдо (0.79)
                // режется стабильно, слабые реальные (≤0.55) проходят.
                $nullRatio = (float) (getenv('NULL_SIGNAL_RATIO') ?: '0.55');
                $nullCvCache = [];
                foreach ($plausible as $cand) {
                    $t = self::testCv($cand['name'], $X_test, $y_test, $bestStd ?? 1.0, $n, $colLabels, $X_train_cv, array_keys($bornBinary), $bornBinary);
                    if (is_finite($t) && $t < $cvTrainMax) {
                        if (! isset($nullCvCache[$cand['name']])) {
                            $nullCvCache[$cand['name']] = \BeeSwarm\Core\NonConstancyFilter::nullMedianCv(
                                $cand['name'], $X_test, $y_test, $bestStd ?? 1.0, $n,
                                $colLabels, $X_train_cv, array_keys($bornBinary), $bornBinary
                            );
                        }
                        // Относительный критерий: сигнал на 20% лучше шума
                        if (\BeeSwarm\Core\NonConstancyFilter::rejects($t, $nullCvCache[$cand['name']], $nullRatio)) {
                            $t = 9.99; // сигнал не лучше шума — псевдозакон
                        }
                    }
                    // S2.1 ФАЗА 2 (все кандидаты): статус по held-out
                    if (isset($preregIds[$cand['name']])) {
                        $prUpdAll = $prDb->prepare(
                            'UPDATE preregistrations SET status = ? WHERE id = ?'
                        );
                        $prUpdAll->execute([$t < $cvTrainMax ? 'CONFIRMED' : 'REFUTED',
                            $preregIds[$cand['name']]]);
                    }
                    $score = $t + $lambda * strlen($cand['name']);
                    if ($score < $bestTestScore) {
                        $bestTestScore = $score;
                        $bestTestCv = $t;
                        $bestTestName = $cand['name'];
                        $bestTestTrainCv = $cand['cv'];
                    }
                }
                if ($bestTestName !== null) {
                    $bestName = $bestTestName;
                    $cv_test = $bestTestCv;
                    // CONCERNS (deleg_68f5709e + deleg_6ee92a50): cv_train
                    // ПОБЕДИТЕЛЯ — обновлять И $bestCv, И $cv_train
                    $bestCv = $bestTestTrainCv;
                    $cv_train = $bestTestTrainCv;
                } else {
                    // CONCERNS (deleg_1ebc06b4): ни один правдоподобный не
                    // прошёл held-out (все R-подгонки/шум) — честный отказ
                    $bestName = null;
                    $cv_test = 9.99;
                }
            }
        }

        // S2.1 ФАЗА 2: статус по held-out (порог = cvTrainMax, параметр)
        if (! empty($preregIds) && isset($bestName) && isset($preregIds[$bestName])) {
            $prDb = \BeeSwarm\Infra\Database::get();
            $status = $cv_test < $cvTrainMax ? 'CONFIRMED' : 'REFUTED';
            $prUpd = $prDb->prepare(
                'UPDATE preregistrations SET status = ? WHERE id = ?'
            );
            $prUpd->execute([$status, $preregIds[$bestName]]);
        }

        $found = $bestCv < $cvTrainMax && isset($bestName) && $cv_test < $cvTrainMax;
        if (! $found) {
            $cv_train = 9.99;
            $cv_test = 9.99;
        }
        
        // ЭКСП-018b: TEST-секция (heldout/выбор) аккумулируется при выходе
        SearchProfiler::add(0.0, 0.0, microtime(true) - $pTest);

        $class = $found ? self::classify($cv_train, $cv_test) : 'NONE';
        // REUSE-TOUCH-ATOM (10.08): победитель с B-именем → touchAtom
        if ($found && is_string($bestName) && preg_match('/B\d+/', $bestName, $m) === 1) {
            \BeeSwarm\Core\Grammar::registerReuse($m[0], 'search');
        }
        return [$found, $cv_train, $bestName ?? 'none', $cv_test, $class];
    }


    private static function preregisterExact(string $formula): void
    {
        $db = \BeeSwarm\Infra\Database::get();
        $stmt = $db->prepare(
            'INSERT INTO preregistrations (formula, domain, cv_predicted, tick, status)
             VALUES (?,?,?,?,?)'
        );
        $stmt->execute([$formula, '', 0.0, 0, 'CONFIRMED']);
    }

    private static function classify(float $cv_train, float $cv_test): string
    {
        // IDENTITY: perfect on train, fails on test (R-tautology)
        if ($cv_train < 0.02 && $cv_test > 0.5) {
            return 'IDENTITY';
        }
        return 'EMPIRICAL';
    }

    public static function testCv(string $name, array $X_test, array $y_test, float $trainStd, int $n, ?array $colLabels = null, ?array $X_train = null, array $extraOps = [], array $opDefs = []): float
    {
        $nTest = count($y_test);
        if ($nTest < 2) {
            return 9.99;
        }

        // SEARCH-TOP-K (05.08): evaluateHeldout резал переданный тест ещё раз
        // (4 → 3+1) и exact-проверял 1 точку — зашумлённые данные всегда 9.99.
        // CV формулы напрямую по ВСЕМ тестовым точкам через ExpressionEvaluator.
        // Метки колонок (colLabels: 'feature') → xN: evaluator понимает x0..x3.
        if (! empty($colLabels)) {
            $map = [];
            foreach ($colLabels as $i => $label) {
                $map[(string) $label] = "x{$i}";
            }
            uksort($map, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
            $name = str_replace(array_keys($map), array_values($map), $name);
        }
        // CONCERNS (deleg_6ee92a50): R-статистики фиксируются по TRAIN
        // (константы модели), иначе R-подгонка пересчитывает их на тесте и
        // адаптируется к шуму теста (0.007956 vs 0.008010 — «побеждает»).
        $stats = $X_train !== null
            ? \BeeSwarm\Core\ExpressionEvaluator::collectStats($name, $X_train, $extraOps, $opDefs)
            : [];
        $vec = \BeeSwarm\Core\ExpressionEvaluator::evaluateFormula($name, $X_test, $stats, $extraOps, $opDefs);
        if ($vec === null || count($vec) !== $nTest) {
            return 9.99;
        }
        $std = self::stddev($vec);
        if ($std < 1e-6) {
            return 9.99;
        }

        // AFFINE-LAWS (CONCERNS deleg_9cf56711): тот же shift, что в train —
        // иначе знакопеременная цель в held-out даёт ложный отказ (found=false
        // при testRatio≥0.3, когда ноль попадает в тест)
        $minY = min($y_test);
        $maxY = max($y_test);
        $affineShift = ($minY < 0 && $maxY > 0) ? $minY - 1.0 : 0.0;

        return self::cv($vec, $y_test, $affineShift);
    }

    /** EXP-035: быстрый Пирсон corr для семантической гварды L2/фича. */
    private static function quickCorr(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n < 3) return 0.0;
        $sum = 0.0; $sa = 0.0; $sb = 0.0;
        $da2 = 0.0; $db2 = 0.0;
        for ($i = 0; $i < $n; $i++) {
            if ($a[$i] === null || $b[$i] === null) continue;
            $sum += $a[$i] * $b[$i];
            $sa += $a[$i]; $sb += $b[$i];
        }
        $ma = $sa / $n; $mb = $sb / $n;
        for ($i = 0; $i < $n; $i++) {
            if ($a[$i] === null || $b[$i] === null) continue;
            $da = $a[$i] - $ma; $db = $b[$i] - $mb;
            $da2 += $da * $da; $db2 += $db * $db;
        }
        if ($da2 < 1e-12 || $db2 < 1e-12) return 0.0;
        // corr = (E[ab]-E[a]E[b]) / (sd_a*sd_b) — через суммы:
        $cov = $sum / $n - $ma * $mb;
        return $cov / sqrt($da2 / $n * $db2 / $n);
    }

    public static function stddev(array $v): float
    {
        $n = count($v);
        $mean = array_sum($v) / $n;
        $sq = 0;
        foreach ($v as $x) {
            $sq += ($x - $mean) ** 2;
        }
        return sqrt($sq / $n);
    }
}
