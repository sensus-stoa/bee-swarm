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
            // SCALE-INVARIANCE (EXP-036 2.5, 29.08): eps ОТНОСИТЕЛЬНЫЙ —
            // abs-eps 1e-4 отвергал точный закон 10·f(x) c остатком ≤1e-3
            // (K3 kill-test). 1e-4·max(1,|y_i|) инвариантен к масштабу y.
            if ($vec[$i] === null || ! is_finite($vec[$i]) || abs($vec[$i] - $y[$i]) > 0.0001 * max(1.0, abs($y[$i]))) {
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
        // SCALE-INVARIANCE (EXP-036 фаза 2.5, 29.08): сдвиг min(y)−1 —
        // АБСОЛЮТНЫЙ якорь: при rescale цели y→a·y он не масштабируется,
        // знаменатель в строке минимума остаётся ~1.0 → ratio взрывается →
        // валидная пропорциональная форма получает CV=17.75 → no_find
        // (heat K3: 0/20). Пропорциональный канал (shift=0, ratio pred/y)
        // математически ИНВАРИНТЕН к масштабу: cv(v, a·y) = cv(v, y),
        // и на heat-данных не взрывается (guard 1e-8 кусает |y|≲1e-7,
        // min|y|=0.148). Решение: min-канал — кандидат проходит, если
        // ЛЮБОЙ из каналов видит закон. Точные пропорциональные — через
        // shift=0, аффинно-приближённые — через канал со сдвигом
        // (EXP-012 сохранён: ноль-пересечения приближённых форм больше
        // не взрываются). K3-контракт: cv(v,a·y,s) ≤ cv(v,y,s) всегда.
        $cvShift = self::cvSingle($vec, $y, $shift);
        if ($shift != 0.0) {
            $cvPlain = self::cvSingle($vec, $y, 0.0);
            return min($cvShift, $cvPlain);
        }
        return $cvShift;
    }

    /** Один ratio-канал без min-логики (внутренний). */
    private static function cvSingle(array $vec, array $y, float $shift): float
    {
        $n = count($vec);
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

    /** §3.3 порог NOISE (С): «CV > 0.5 для всех испробованных» — прямо
     *  в тексте критерия само-модели незнания. */
    private const NOISE_CV_FLOOR = 0.5;
    /** §1.2 tMin base = 10 — Hive-маршрутизация вычисляет
     *  max(10, nFeat×5) и передаёт в find() (pre-filter на уровне
     *  маршрутизации, §1.2). */
    public const T_MIN_BASE = 10;

    /**
     * §3.3 Само-модель незнания: диагноз причины отказа.
     *
     * Приоритет категорий (Е): DATA > DEPTH > GRAMMAR > NOISE — диагноз
     * по наименее дорогой валидации §3.3. NOISE — вердикт-исключение:
     * только когда глубина исчерпана (depth=3) и сигнал не виден.
     *
     *  NOISE   — лучший cv всех испробованных выражений > NOISE_CV_FLOOR
     *            (протокол: «CV > 0.5 для всех испробованных»).
     *  DEPTH   — depth < 3: валидация §3.3 «увеличить глубину → решено»
     *            (dot-класс: depth=2 fail, depth=3 PASS через slot-каскад).
     *  GRAMMAR — сигнал есть (cv < NOISE_CV_FLOOR), глубина исчерпана,
     *            точный закон не достигнут (класс не покрыт грамматикой).
     *
     * TIMEOUT не переименовывается (обратная совместимость потребителей);
     * расширение бюджета/глубины — путь валидации TIMEOUT-случаев.
     */
    private static function diagnoseFailure(float $bestCvSeen, int $depth): string
    {
        if ($depth < 3) {
            return 'DEPTH';
        }
        // R2 (review deleg_cf353090): INF/NaN = «ничего не оценено» —
        // пустое пространство перебора, не шум (GRAMMAR-территория).
        if (is_finite($bestCvSeen) && $bestCvSeen > self::NOISE_CV_FLOOR) {
            return 'NOISE';
        }

        return 'GRAMMAR';
    }

    /**
     * @param float $budgetSec Полный wall-clock бюджет (включая подготовку фич!),
     *   0.0 = без лимита. По истечении: [false, 9.99, 'none', 9.99, 'TIMEOUT'].
     *   'TIMEOUT' и 'none' оба = «нет результата» — потребители обязаны
     *   проверять $res[0] (found) ДО чтения score/expr (9.99 = sentinel!).
     *   Семантика: бюджет = тотальный wall-clock (как timeout PySR 30s).
     */
    public static function find(array $X, array $y, Grammar $grammar, int $depth = 2, ?array $colLabels = null, float $testRatio = 0.0, float $cvTrainMax = 0.15, float $budgetSec = 0.0, ?int $tMin = null): array
    {
        // ЭКСП-018b: микро-профиль Search (SEARCH_PROFILE=1)
        SearchProfiler::registerShutdown();
        $p0 = microtime(true);
        // EXP-036 ревью deleg_1408a6cc BLOCK-фикс: static $mul2Cache
        // persists между вызовами find() → вектор задачи A подмешивается
        // в задачу B (тихая порча, cacheKey не содержит данных). Кэш
        // сбрасывается на КАЖДЫЙ вызов — внутри одного вызова дедуп
        // работает, между задачами — ноль риска.
        self::$mul2Cache = [];
        self::$__prof = [['START', $p0]];
        $deadline = $budgetSec > 0.0 ? $p0 + $budgetSec : INF;
        if (microtime(true) > $deadline) {
            return [false, 9.99, 'none', 9.99, 'TIMEOUT', $depth < 3 ? 'DEPTH' : 'TIMEOUT'];
        }
        $n = count($y);
        if ($n === 0 || empty($X) || empty($X[0])) {
            return [false, 9.99, 'none', 9.99, 'NONE', 'DATA'];
        }
        $nFeat = count($X[0]);
        // §3.3 DATA-диагноз: tMin передаёт ВЫЗЫВАЮЩИЙ (Hive-маршрутизация,
        // §1.2 pre-filter на уровне маршрутизации). null = гейт выключен
        // (legacy-вызовы и unit-тесты механики).
        if ($tMin !== null && $n < $tMin) {
            return [false, 9.99, 'none', 9.99, 'NONE', 'DATA'];
        }
        $bestCvSeen = INF; // §3.3: лучший cv из ВСЕХ испробованных выражений

        // Feature naming: colLabels[0]='price' → feature key 'price' instead of 'x0'
        $featName = fn (int $i): string => $colLabels[$i] ?? "x{$i}";

        // L0: Features
        $feats = [];
        for ($i = 0; $i < $nFeat; $i++) {
            if (microtime(true) > $deadline) {
                return [false, 9.99, 'none', 9.99, 'TIMEOUT', $depth < 3 ? 'DEPTH' : 'TIMEOUT'];
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
                return [false, 9.99, 'none', 9.99, 'TIMEOUT', $depth < 3 ? 'DEPTH' : 'TIMEOUT'];
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
        // EXP-036: сырые фичи x0..xN — единое определение для L2L1
        // (B-формы) и CHUNK-DIRECT. Определяется ОДИН раз в начале.
        $rawAll = array_values(array_filter($featKeys, fn ($k) => preg_match('/^x\d+$/', $k) === 1));
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
                    ORDER BY CASE WHEN name LIKE \'BW%\' THEN 0 ELSE 1 END,
                        CASE WHEN status = \'active\' THEN 0 ELSE 1 END, length(definition), MIN(id)
                    LIMIT ' . $bCap;
                $stmt = \BeeSwarm\Infra\Database::get()->prepare($sql);
                // EXP-035 (28.08): definition может не содержать x0
                // (напр. (x1−x2) для heat) — фильтр LIKE '%x0%' AND '%x1%'
                // отсеивал валидные атомы. Требование: хотя бы 2 терминала
                // xN (любые), проверяем LIKE-фильтром по общему префиксу 'x'.
                $stmt->execute(['birth', '%x%', '%x%']);
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
                    if (getenv('SEARCH_DEBUG') === '1' && $name === '(x1BPf29ex2)') {
                        fwrite(STDERR, '[SD-BK] def=' . $bbDef . ' va[0..1]='
                            . json_encode(array_slice($va, 0, 2)) . ' vb[0..1]='
                            . json_encode(array_slice($vb, 0, 2)) . ' vec[0..1]='
                            . json_encode(array_slice($vec, 0, 2)) . PHP_EOL);
                    }
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
        // A3 SLOT-SNAPSHOT: ×-слоты (имя+вектор) снимаются СРАЗУ после
        // pairwise — до beam/заморозок. Каскад работает с ПОЛНЫМ L1-набором:
        // частичные суммы (cv≈2.7) ниже по потоку замораживаются, но
        // материал для сборки обязан сохраниться. Вектора в снапшоте:
        // $exprs-записи ниже по потоку могут быть unset.
        $slotMulKeys = [];
        foreach ($exprs as $k => $vec) {
            // (?:²)? — группировка обязательна: ² в байтовом PCRE = 2 байта,
            // '²?' делает опциональным только последний байт → raw×raw
            // формы отфильтровывались (регрессия dot, найдена в A3).
            // Группы на ОБОИХ операндах: sq-first формы (x0²×x1) — те же
            // слоты, что raw-first (x1×x0²), коммутативно.
            if (preg_match('/^\(x\d+(?:²)?×x\d+(?:²)?\)$/', $k) === 1) {
                $slotMulKeys[$k] = $vec;
            }
        }

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
            return [false, 9.99, 'none', 9.99, 'TIMEOUT', $depth < 3 ? 'DEPTH' : 'TIMEOUT'];
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
                        return [false, 9.99, 'none', 9.99, 'TIMEOUT', $depth < 3 ? 'DEPTH' : 'TIMEOUT'];
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
                    if (getenv('SEARCH_PROFILE') === '1') { self::$__prof[] = ['L2PAIRS', microtime(true)]; }
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

        // RESOURCE→KNOWLEDGE (27.08, принцип юзера): после L2-pairs —
        // beam-отбор l2Keys. Формы без пользы (CV плох, B внутри нет)
        // НЕ получают памяти на следующий уровень — заморозка/смерть.
        // B-формы всегда живут (chunk-кандидаты, гейт не по CV).
        $l2BeamK = (int) (getenv('L2_BEAM_K') ?: '40');
        if ($l2BeamK > 0 && count($l2Keys) > $l2BeamK) {
            $scored2 = [];
            foreach ($l2Keys as $pname) {
                $pv = $exprs[$pname];
                $cvQ = self::cv(array_slice($pv, 0, min($n, 30)), array_slice($y, 0, min($n, 30)), 0.0);
                $scored2[] = ['n' => $pname, 'cv' => $cvQ,
                    'b' => preg_match('/B[A-Za-z0-9]+/', $pname) === 1];
            }
            usort($scored2, function (array $a, array $b): int {
                // B-формы всегда впереди (chunk-капитал), внутри группы — по CV
                if ($a['b'] !== $b['b']) return $a['b'] ? -1 : 1;
                return $a['cv'] <=> $b['cv'];
            });
            $kept = array_slice($scored2, 0, $l2BeamK);
            $keptNames = array_map(fn (array $x): string => $x['n'], $kept);
            // Заморозка: удаляем неперспективные векторы из памяти (RAM)
            $keepSet = array_flip($keptNames);
            foreach ($l2Keys as $pname) {
                if (! isset($keepSet[$pname])) {
                    unset($exprs[$pname]);
                }
            }
            $l2Keys = $keptNames;
        }

        if (getenv('SEARCH_DEBUG') === '1') {
            fwrite(STDERR, '[SD-BEAM] l2Keys after beam=' . count($l2Keys)
                . ' exprs=' . count($exprs)
                . ' mem=' . round(memory_get_usage(true) / 1048576) . 'MB'
                . ' featKeys=' . count($featKeys) . PHP_EOL);
        }
                if (getenv('SEARCH_PROFILE') === '1') { self::$__prof[] = ['L2L1', microtime(true)]; }
        // SEARCH-L2L1 (09.08): L3 = L1 op Фича — композиции второго уровня.
        // (x0+x1) — L1-уровень; без L1×фича (x0+x1)×x2 невыразим →
        // transfer-тест невалиден (ЭКСП-022d). top-30 L1 × фичи × ops.
        if ($depth >= 3 && ! empty($l1Keys)) {
            // B-AS-ARGUMENT: B-формы (B7a7aee×x2) в L2L1 — без них атомы
            // не участвуют в двухуровневых композициях
            // EXP-035: bKeys ВПЕРЕДИ и cap 200 (105 B-форм > старый slice-30 —
            // (x0BPf474x1) отрезался → целевая L2L1-форма не создавалась)
            $l1Top = array_slice(array_merge($bKeys ?? [], $l1Keys), 0, 200);
            // L2L1: $ops = ВСЯ грамматика (прод: 3562 ops!) → 30×12×3562×n —
            // вечность на проде. Cap до top-50 (как beam) + проверка бюджета.
            $l2l1Ops = array_slice($ops, 0, 50);
            $l2l1Count = 0;
            foreach ($l1Top as $l1name) {
                if ((++$l2l1Count & 15) === 0 && microtime(true) > $deadline) {
                    return [false, 9.99, 'none', 9.99, 'TIMEOUT', $depth < 3 ? 'DEPTH' : 'TIMEOUT'];
                }
                // EXP-036 ф1 (29.08): B-формы комбинируются ТОЛЬКО с сырыми
                // фичами (heat-семантика: chunk×κ×A/d — все raw). R-производные
                // в паре с B-атомом = мусор × 65 лишних колонок = 87% времени
                // L2L1. Не-B формы (R-законы) — полные featKeys.
                // Ревью deleg_1408a6cc: 'B' в имени фичи (RB..., x0B...)
                // молча меняет диспетчер → regex привязан к позиции:
                // B-имя строго после первого термина в скобках.
                $l1IsB = preg_match('/^\(x\d+B[A-Za-z0-9]+x\d+\)$/', $l1name) === 1;
                $companionKeys = $l1IsB ? $rawAll : $featKeys;
                foreach ($companionKeys as $fname) {
                    foreach ($l2l1Ops as $op) {
                        $vec = [];
                        $valid = true;
                        for ($i = 0; $i < $n; $i++) {
                            $la = $exprs[$l1name][$i] ?? null;
                            $lb = $exprs[$fname][$i] ?? null;
                            if ($la === null || $lb === null) { $valid = false; break; }
                            $r = $grammar->apply($la, $lb, $op);
                            $vec[] = $r ?? 0.0;
                        }
                        if (! $valid) continue;
                        $name = "({$l1name}$op{$fname})";
                        $exprs[$name] = $vec;
                        $l2Keys[] = $name;
                    }
                }
            }
        }

                if (getenv('SEARCH_PROFILE') === '1') { self::$__prof[] = ['L3', microtime(true)]; }
        // L3: L2 / constant (для MIN = (...)/2)
        if (microtime(true) > $deadline) {
            return [false, 9.99, 'none', 9.99, 'TIMEOUT', $depth < 3 ? 'DEPTH' : 'TIMEOUT'];
        }
        if (getenv('SEARCH_DEBUG') === '1') {
            $bvec = '(x0BPf474x1)';
            fwrite(STDERR, '[SD-POST-L2L1] bvec exprs: ' . (isset($exprs[$bvec]) ? 'YES' : 'NO')
                . ' target: ' . (isset($exprs['((x0BPf474x1)×x2)']) ? 'YES' : 'NO')
                . ' l2Keys=' . count($l2Keys) . PHP_EOL);
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
            // РЕСУРС→ЗНАНИЕ (27.08): L3 строим из beam-top l2Keys (жёстко).
            // Отбор: B-формы приоритет (chunk-капитал), внутри — по CV.
            // Никаких «все B-формы живут» — L2L1 плодит 12К B-форм (30 l1Top
            // bKeys × 70 фич × 6 ops), все они конкурируют за память.
            $l3BeamK = (int) (getenv('L2_BEAM_K') ?: '40');
            if (count($l2Keys) > $l3BeamK) {
                $scored3 = [];
                foreach ($l2Keys as $pname) {
                    $pv = $exprs[$pname];
                    $cvQ = self::cv(array_slice($pv, 0, min($n, 30)), array_slice($y, 0, min($n, 30)), 0.0);
                    $scored3[] = ['n' => $pname, 'cv' => $cvQ,
                        'b' => preg_match('/B[A-Za-z0-9]+/', $pname) === 1];
                }
                usort($scored3, function (array $a, array $b): int {
                    if ($a['b'] !== $b['b']) return $a['b'] ? -1 : 1;
                    return $a['cv'] <=> $b['cv'];
                });
                $kept3 = array_slice($scored3, 0, $l3BeamK);
                $keepSet = array_flip(array_map(fn (array $x): string => $x['n'], $kept3));
                $bKeySet = array_flip($bKeys ?? []);
                foreach ($l2Keys as $pname) {
                    if (! isset($keepSet[$pname]) && ! isset($bKeySet[$pname])) {
                        unset($exprs[$pname]); // заморозка; bKeys не трогаем (chunk-капитал)
                    }
                }
                $l2Keys = array_map(fn (array $x): string => $x['n'], $kept3);
            }

                    if (getenv('SEARCH_PROFILE') === '1') { self::$__prof[] = ['CHUNK', microtime(true)]; }
        // CHUNK-DIRECT (27.08, принцип «ресурс→знание»): heat-цепочка
            // κ(chunk×A)/d строится ПРЯМО из chunk-форм (bKeys), минуя
            // cv-beam: cv у частичного chunk плох ДО полной цепочки —
            // beam-отбор убивал правильную мысль (урок 3b: TARGET cv=2.2
            // отсекался). Ресурс: bKeys(1051) × сырые(5) × сырые(4) ≈ 21К
            // векторов, только finite — контролируемо.
            $chunkBudget = (int) (getenv('CHUNK_BUDGET') ?: '3000');
            // Приоритет: chunk-формы с РАЗНЫМИ фичами (x0BPfx1) впереди
            // квадратов (x0BPfx0²) — квадраты редко дают законы,
            // а бюджет цепочек ограничен.
            $chunkKeys = ($bKeys ?? []);
            usort($chunkKeys, fn ($a, $b) => (int) str_contains($a, '²') <=> (int) str_contains($b, '²'));
            $chunkChains = 0;
            if (getenv('SEARCH_DEBUG') === '1') {
                fwrite(STDERR, '[SD-CHUNK] bKeys=' . count($chunkKeys)
                    . ' rawAll=' . count($rawAll)
                    . ' exprs=' . count($exprs) . PHP_EOL);
                $hit = array_filter($chunkKeys, fn ($k) => str_contains($k, 'BPf29ex2'));
                fwrite(STDERR, '[SD-CHUNK] target chunk in bKeys: ' . (count($hit) ? 'YES ' . json_encode(array_values($hit)) : 'NO') . PHP_EOL);
            }
            $targetChain = '((((x1BPf29ex2)×x0)×x3)/x4)';
            $chainCreated = false;
            foreach ($chunkKeys as $ck) {
                if (! isset($exprs[$ck]) || $chunkChains > $chunkBudget) break;
                if (getenv('SEARCH_DEBUG') === '1' && $ck === '(x1BPf29ex2)') {
                    fwrite(STDERR, '[SD-CHUNK] ck vec[0..2]: ' . json_encode(array_slice($exprs[$ck], 0, 3)) . PHP_EOL);
                }
                foreach ($rawAll as $fk1) {
                    $mulVec = [];
                    $mulOk = true;
                    for ($i = 0; $i < $n; $i++) {
                        $r = $exprs[$ck][$i] * $feats[$fk1][$i];
                        if (! is_finite($r)) { $mulOk = false; break; }
                        $mulVec[] = $r;
                    }
                    if (! $mulOk) continue;
                    $mulName = "({$ck}×{$fk1})";
                    $exprs[$mulName] = $mulVec;
                    $l2Keys[] = $mulName;
                    foreach ($rawAll as $fk2) {
                        if ($fk2 === $fk1 || str_contains($ck, $fk2)) continue;
                        // Уровень A: (chunk×fk1)/fk2
                        $vec = [];
                        for ($i = 0; $i < $n; $i++) {
                            $denom = $feats[$fk2][$i];
                            $vec[] = (abs($denom) < 1e-12) ? null : ($mulVec[$i] / $denom);
                        }
                        $exprs["({$mulName}/{$fk2})"] = $vec;
                        // Уровень B (EXP-035 heat): (chunk×fk1×fk2)/fk3 —
                        // κ(chunk)·A·k/d — ДВА умножения после chunk.
                        // EXP-036 ф1 (29.08): КЭШ ПРОСТРАНСТВА ПОИСКА.
                        // mul2 зависит только от (ck, fk1, fk2) — вычисляем
                        // ОДИН раз (раньше пересчитывался ×|fk3| — 742s).
                        $cacheKey = $mulName . '|' . $fk2;
                        if (isset(self::$mul2Cache[$cacheKey])) {
                            $mul2 = self::$mul2Cache[$cacheKey];
                            $mul2Name = "({$mulName}×{$fk2})";
                        } else {
                            $mul2 = [];
                            $mul2Ok = true;
                            for ($i = 0; $i < $n; $i++) {
                                $r = $mulVec[$i] * $feats[$fk2][$i];
                                if (! is_finite($r)) { $mul2Ok = false; break; }
                                $mul2[] = $r;
                            }
                            if (! $mul2Ok) continue;
                            $mul2Name = "({$mulName}×{$fk2})";
                            self::$mul2Cache[$cacheKey] = $mul2;
                            self::$mul2Computations++;
                            self::$mul2Unique[$mul2Name] = true;
                        }
                        // EXP-036 ф1: cv-скрининг mul2 — отбраковка мусора
                        // ДО vec2-генерации (×|fk4| экономия). Порог мягкий:
                        // цели (cv=0) проходят всегда, мусор отсекается.
                        // Риск (ревью deleg_1408a6cc): (a×b)/c с плохим a×b,
                        // но хорошим делением — редок, логируется.
                        $mul2Cv = self::cv($mul2, $y, 0.0);
                        if ($mul2Cv > self::MUL2_CV_SCREEN_MAX) {
                            self::$mul2Screened++;
                            continue;
                        }
                        {
                            $chunkChains++;
                            $exprs[$mul2Name] = self::$mul2Cache[$cacheKey];
                            if (str_contains($mul2Name, 'BPf29ex2') && str_contains($mul2Name, '×x0')) {
                                fwrite(STDERR, '[SD-CHUNK] mul2 built: ' . $mul2Name . PHP_EOL);
                            }
                            if (getenv('SEARCH_DEBUG') === '1' && str_contains($mul2Name, 'BPf29ex2')) {
                                static $dbgPrinted = false;
                                if (! $dbgPrinted) {
                                    fwrite(STDERR, '[SD-CHUNK] sample mul2: ' . $mul2Name . PHP_EOL);
                                    $dbgPrinted = true;
                                }
                            }
                            foreach ($rawAll as $fk4) {
                                // EXP-036: fk3-цикл удалён (mul2 не зависит от
                                // fk3 — пересчитывался ×|fk3| впустую).
                                // fk4(делитель) ≠ fk2(множитель): иначе
                                // ((chunk×fk1)×fk2)/fk2 вырождается в chunk×fk1
                                if ($fk4 === $fk2 || str_contains($ck, $fk4) || $fk4 === $fk1) continue;
                                $vec2 = [];
                                for ($i = 0; $i < $n; $i++) {
                                    $denom = $feats[$fk4][$i];
                                    $vec2[] = (abs($denom) < 1e-12) ? null : ($mul2[$i] / $denom);
                                }
                                $vec2Name = "({$mul2Name}/{$fk4})";
                                $exprs[$vec2Name] = $vec2;
                                if ($vec2Name === $targetChain) {
                                    $chainCreated = true;
                                    fwrite(STDERR, '[SD-CHUNK] TARGET CHAIN BUILT' . PHP_EOL);
                                }
                            }
                        }
                    }
                }
            }
            if (getenv('SEARCH_PROFILE') === '1') {
                self::$__prof[] = ['CV_END', microtime(true)];
                $prev = self::$__prof[0][1] ?? 0;
                foreach (self::$__prof as $pp) {
                    fwrite(STDERR, '[PROF] ' . $pp[0] . ' +' . round(($pp[1] - $prev) * 1000) . 'ms' . PHP_EOL);
                    $prev = $pp[1];
                }
            }
            if (getenv('SEARCH_DEBUG') === '1') {
                fwrite(STDERR, '[SD-CHUNK] target chain created: ' . ($chainCreated ? 'YES' : 'NO')
                    . ' chains=' . $chunkChains . PHP_EOL);
                if (isset($exprs[$targetChain])) {
                    $tcv = self::cv($exprs[$targetChain], $y, 0.0);
                    $nulls = count(array_filter($exprs[$targetChain], fn ($v) => $v === null));
                    fwrite(STDERR, '[SD-CHUNK] target cv=' . number_format($tcv, 6)
                        . ' nulls=' . $nulls . '/' . count($exprs[$targetChain]) . PHP_EOL);
                } else {
                    fwrite(STDERR, '[SD-CHUNK] target NOT in exprs?!' . PHP_EOL);
                }
            }
            foreach ($l2Keys as $l2name) {
                // РЕСУРС→ЗНАНИЕ: L3-/фича — только beam-top или chunk-пути.
                // l2Keys после CHUNK-DIRECT ~15К; всем память не даём.
                static $l3FilterK = null;
                if ($l3FilterK === null) {
                    $l3FilterK = (int) (getenv('L2_BEAM_K') ?: '40');
                }
                $l3idx = $l3Count;
                // ЖЁСТКО: L3-/фича только beam-top (chunk-пути уже покрыты
                // CHUNK-DIRECT — не дублируем память на 15К b-форм).
                if ($l3idx >= $l3FilterK) {
                    continue;
                }
                if ((++$l3Count & 31) === 0 && microtime(true) > $deadline) {
                    return [false, 9.99, 'none', 9.99, 'TIMEOUT', $depth < 3 ? 'DEPTH' : 'TIMEOUT'];
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
                    $l2vec = $exprs[$l2name];
                    // EXP-035 фикс: BPf474-имена (partial birth, буква P после B)
                    // не матчатся hex-классом [0-9a-f] → hasB=false → chunk-ветки
                    // скипались. Универсальный класс: B + [A-Za-z0-9]+
                    $hasB = preg_match('/B[A-Za-z0-9]+/', $l2name) === 1;
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
                    // EXP-035 L3b (27.08): (l2 × фича) / фича — heat-цепочка
                    // κ(T2−T1)A/d требует ДВЕ операции после chunk.
                    // ГВАРДА: только l2, УЖЕ содержащие ×-композицию с B
                    // (формат ((xBPyz)×xN)) — не все B-формы (OOM 27.08).
                    if ($hasB && str_contains($l2name, '×')) {
                        // РЕСУРС→ЗНАНИЕ: L3b только по СЫРЫМ фичам x0..xN
                        // (heat: ×κ×A/d — переменные, не R-статистики).
                        // 70 производных ключей × 69 = 96К векторов на l2 = RAM-взрыв.
                        $rawKeys = array_filter($featKeys, fn ($k) => preg_match('/^x\d+$/', $k) === 1);
                        foreach ($rawKeys as $fk1) {
                            $mulVec = [];
                            $mulOk = true;
                            for ($i = 0; $i < $n; $i++) {
                                $r = $exprs[$l2name][$i] * $feats[$fk1][$i];
                                if (! is_finite($r)) { $mulOk = false; break; }
                                $mulVec[] = $r;
                            }
                            if (! $mulOk) continue;
                            $mulName = "({$l2name}×{$fk1})";
                            $exprs[$mulName] = $mulVec;
                            foreach ($rawKeys as $fk2) {
                                if ($fk2 === $fk1 || str_contains($l2name, $fk2)) continue;
                                $vec = [];
                                for ($i = 0; $i < $n; $i++) {
                                    $denom = $feats[$fk2][$i];
                                    $vec[] = (abs($denom) < 1e-12) ? null : ($mulVec[$i] / $denom);
                                }
                                $exprs["({$mulName}/{$fk2})"] = $vec;
                            }
                        }
                    }
                }
            }
        }

        // A2 SLOT-CASCADE (pysr-rematch): SUM-каркасы из ×-пар L1.
        // Частичные суммы dot имеют cv≈2.7 — beam их убивает, закон
        // проявляется ТОЛЬКО на финальном узле. Тройки ×-форм сырых
        // фич перебираются БЕЗ beam (прототип: 0.01s, FPR=0/1365);
        // критерий сборки — cv→0 факта (тот же cvTrainMax, суд общий).
        // Гейт: depth>=3 (dot на depth-2 невыразим) и >=3 сырых фич.
        // Ресурс: SLOT_BUDGET (cap попыток, единственная ручка).
        // affineShift ДО гейта: assemble вычисляется ПЕРЕД вычислением
        // общего affineShift ниже — считаем заранее (review п.2:
        // гейт и суд обязаны иметь одну семантику сдвига).
        $slotShift = 0.0;
        if ($depth >= 3 && count($rawAll) >= 3) {
            $slotMinY = min($y);
            $slotMaxY = max($y);
            $slotShift = ($slotMinY < 0 && $slotMaxY > 0) ? $slotMinY - 1.0 : 0.0;
            if (count($slotMulKeys) >= 3) {
                $slot = \BeeSwarm\Core\SlotCascade::assemble(
                    $slotMulKeys,
                    $y,
                    fn (array $vec, array $target, float $sh): float => self::cv($vec, $target, $sh),
                    $cvTrainMax,
                    $slotShift
                );
                if ($slot !== null && ! isset($exprs[$slot[0]])) {
                    $exprs[$slot[0]] = $slot[1];
                    $l2Keys[] = $slot[0];
                    if (getenv('SEARCH_DEBUG') === '1') {
                        fwrite(STDERR, '[SD-SLOT] assembled: ' . $slot[0] . PHP_EOL);
                    }
                }
            }
        }

                if (getenv('SEARCH_PROFILE') === '1') { self::$__prof[] = ['CV', microtime(true)]; }
        // Evaluate FEATURES first (fast path)
        $bestExact = null; // COMPRESSION-CRITERION: кратчайший exact (10.08: было после — undefined в features-цикле!)
        foreach ($feats as $name => $vec) {
            $exact = true;
            for ($i = 0; $i < $n; $i++) {
                // NaN/INF не могут быть законом (артефакт переполнения R×)
                // EXP-035: null (ноль-делитель в L2/фича) — не точный закон
                if ($vec[$i] === null || ! is_finite($vec[$i]) || abs($vec[$i] - $y[$i]) > 0.0001 * max(1.0, abs($y[$i]))) {
                    $exact = false;
                    break;
                }
            }
            if ($exact) {
                self::preregisterExact($name);
                // COMPRESSION-CRITERION (09.08): exact-путь выбирает
                // КРАТЧАЙШУЮ формулу — иначе add-форма (раньше в порядке)
                // всегда выигрывает у B1-формы → атом не используется
                // FLOOR-EMERGENCE M1.5: при равном дереве (exact оба) BW-форма
                // (языковое слово) предпочтительна сырой — reuse активирует
                // candidate→active, сжатие растёт. Имя длиннее ≠ дерево длиннее.
                // РЕВЬЮ deleg_1b903868 BLOCK: класс ВЫШЕ длины — иначе короткая
                // сырая форма (второй операнд strlen<) откатывает BW-победу.
                $nameIsBw = preg_match('/BW[0-9a-f]+/', $name) === 1;
                $bestIsBw = $bestExact !== null && preg_match('/BW[0-9a-f]+/', $bestExact) === 1;
                if ($bestExact === null
                    || ($nameIsBw && ! $bestIsBw)
                    || ($nameIsBw === $bestIsBw && strlen($name) < strlen($bestExact))) {
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
                if ($vec[$i] === null || ! is_finite($vec[$i]) || abs($vec[$i] - $y[$i]) > 0.0001 * max(1.0, abs($y[$i]))) {
                    $exact = false;
                    break;
                }
            }
            if ($exact) {
                self::preregisterExact($name);
                // COMPRESSION-CRITERION (09.08): exact-путь выбирает
                // КРАТЧАЙШУЮ формулу — иначе add-форма (раньше в порядке)
                // всегда выигрывает у B1-формы → атом не используется
                // FLOOR-EMERGENCE M1.5: при равном дереве (exact оба) BW-форма
                // (языковое слово) предпочтительна сырой — reuse активирует
                // candidate→active, сжатие растёт. Имя длиннее ≠ дерево длиннее.
                // РЕВЬЮ deleg_1b903868 BLOCK: класс ВЫШЕ длины — иначе короткая
                // сырая форма (второй операнд strlen<) откатывает BW-победу.
                $nameIsBw = preg_match('/BW[0-9a-f]+/', $name) === 1;
                $bestIsBw = $bestExact !== null && preg_match('/BW[0-9a-f]+/', $bestExact) === 1;
                if ($bestExact === null
                    || ($nameIsBw && ! $bestIsBw)
                    || ($nameIsBw === $bestIsBw && strlen($name) < strlen($bestExact))) {
                    $bestExact = $name;
                }//exact
            }

            $std = self::stddev($vec);
            if ($std < 1e-6) {
                continue;
            }
            $cv = self::cv($vec, $y, $affineShift);
            if ($cv < $bestCvSeen) {
                $bestCvSeen = $cv;
            }
            if ($cv < $cvTrainMax) {
                $plausible[] = ['cv' => $cv, 'name' => $name];
            }
        }

        if ($bestExact !== null) {
            // REUSE-TOUCH-ATOM (10.08): применение в точке использования!
            // Имя атома известно в момент победы — регистрируем reuse.
            if (preg_match('/(BW[0-9a-f]+|B\d+)/', (string) $bestExact, $m) === 1) {
                \BeeSwarm\Core\Grammar::registerReuse($m[0], 'search');
            }
            return [true, 0.0, $bestExact, 0.0, 'EMPIRICAL', null];
        }

        if (getenv('SEARCH_DEBUG') === '1') {
            fwrite(STDERR, '[SD] exprs=' . count($exprs) . ' plausible=' . count($plausible) . PHP_EOL);

            $top = array_slice($plausible, 0, 3);
            foreach ($top as $t) fwrite(STDERR, '[SD] top: ' . $t['name'] . ' cv=' . number_format($t['cv'], 4) . PHP_EOL);
            // L3b-имя цели
            $l3b = '((((x0BPf474x1)×x2)×x3)/x4)';
            fwrite(STDERR, '[SD] L3b exists: ' . (isset($exprs[$l3b]) ? 'YES' : 'NO') . PHP_EOL);
            $hits = [];
            foreach (array_keys($exprs) as $k) {
                if (str_contains($k, 'BPf474') && str_contains($k, '×x2')) $hits[] = $k;
                if (count($hits) >= 5) break;
            }
            fwrite(STDERR, '[SD] BPf474×x2 hits: ' . json_encode($hits) . PHP_EOL);
            $bvec = '(x0BPf474x1)';
            fwrite(STDERR, '[SD] (x0BPf474x1) in exprs: ' . (isset($exprs[$bvec]) ? 'YES' : 'NO') . PHP_EOL);
            fwrite(STDERR, '[SD] bKeys count=' . count($bKeys ?? []) . ' first3=' . json_encode(array_slice($bKeys ?? [], 0, 3)) . PHP_EOL);
            if (isset($exprs[$l3b])) {
                $cvL3b = self::cv($exprs[$l3b], $y, $affineShift);
                fwrite(STDERR, '[SD] L3b cv=' . number_format($cvL3b, 6) . PHP_EOL);
            }
            // Сколько l2Keys с ×?
            $mulCount = 0;
            foreach ($l2Keys as $k) if (str_contains($k, '×')) $mulCount++;
            fwrite(STDERR, '[SD] l2Keys total=' . count($l2Keys) . ' with×=' . $mulCount
                . ' exprs=' . count($exprs)
                . ' beamK=' . (int) (getenv('L2_BEAM_K') ?: '40') . PHP_EOL);
            // EXP-035: прямой cv целевой формы
            $target = '((x0BPf474x1)×x2)';
            if (isset($exprs[$target])) {
                $cvT = self::cv($exprs[$target], $y, $affineShift);
                fwrite(STDERR, '[SD] TARGET ' . $target . ' cv=' . number_format($cvT, 5) . PHP_EOL);
                fwrite(STDERR, '[SD] TARGET vec[0..4]: ' . json_encode(array_slice($exprs[$target], 0, 5)) . PHP_EOL);
                $bvec = '(x0BPf474x1)';
                if (isset($exprs[$bvec])) {
                    fwrite(STDERR, '[SD] B-vec[0..4]: ' . json_encode(array_slice($exprs[$bvec], 0, 5)) . PHP_EOL);
                }
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
                // FLOOR-EMERGENCE M1.5 (rev deleg_1b903868 п.3): scalar-tie-break
                // здесь dead-path (score===bestScore требует идентичных cv+len).
                // BW-приоритет живёт в exact-классе и usort($plausible).
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
                    // trainStd — мёртвый параметр testCv (не используется в теле), 1.0 placeholder
                    $t = self::testCv($cand['name'], $X_test, $y_test, 1.0, $n, $colLabels, $X_train_cv, array_keys($bornBinary), $bornBinary);
                    if (is_finite($t) && $t < $cvTrainMax) {
                        if (! isset($nullCvCache[$cand['name']])) {
                            $nullCvCache[$cand['name']] = \BeeSwarm\Core\NonConstancyFilter::nullMedianCv(
                                $cand['name'], $X_test, $y_test, 1.0, $n,
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
        // §3.3 Само-модель незнания: при неудаче — диагноз причины
        $diagnosis = null;
        if (! $found) {
            $diagnosis = self::diagnoseFailure($bestCvSeen, $depth);
        }
        // REUSE-TOUCH-ATOM (10.08): победитель с B-именем → touchAtom
        if ($found && is_string($bestName) && preg_match('/(BW[0-9a-f]+|B\d+)/', $bestName, $m) === 1) {
            \BeeSwarm\Core\Grammar::registerReuse($m[0], 'search');
        }
        return [$found, $cv_train, $bestName ?? 'none', $cv_test, $class, $diagnosis];
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

    /** EXP-036: профиль этапов find. */
/** @var list<array{0: string, 1: float}> */
    private static array $__prof = [];

    /** EXP-036: порог cv-скрининга mul2 (ревью: named const, не magic). */
    public const MUL2_CV_SCREEN_MAX = 100.0;

    /** EXP-036: число скипнутых скринингом (регрессия покрытия видна). */
    private static int $mul2Screened = 0;

    /** EXP-036 фаза 1: телеметрия кэша mul2 (ChunkCacheTest). */
    private static int $mul2Computations = 0;
    private static array $mul2Cache = [];
    private static array $mul2Unique = [];

    public static function resetMul2Counter(): void
    {
        self::$mul2Computations = 0;
        self::$mul2Screened = 0;
        self::$mul2Cache = [];
        self::$mul2Unique = [];
    }

    public static function getMul2Counter(): int
    {
        return self::$mul2Computations;
    }

    public static function getMul2UniqueKeys(): int
    {
        return count(self::$mul2Unique);
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
