<?php

declare(strict_types=1);

namespace BeeSwarm\Certification;

use BeeSwarm\Core\ExpressionEvaluator;
use BeeSwarm\Core\Grammar;
use BeeSwarm\Core\LawShape;
use BeeSwarm\Core\Search;
use BeeSwarm\Infra\RngIsolation;

/**
 * V0.11 (§1.9 v1.7-draft): perturbed ensemble certification.
 *
 * Поднимает сертификат с точечного на структурный: истинный закон
 * переоткрывается при возмущении задачи (bootstrap строк + гейт-джиттер),
 * эксплойт геометрии одной выборки — нет (Demo Audit #2: дегенерат −65MW).
 *
 * Движок НЕ трогается (§0.2): члены = обычные Search::find на бутстрэпах.
 * Формула члена -> LawShape -> ShapeTally -> консенсус. Гейты:
 *  (a) рецидив консенсус-формы >= 80% от foundN (ЗНАМЕНАТЕЛЬ = foundN —
 *      члены, вернувшие кандидата, НЕ K; hindsight-фикс runner'а);
 *  (b) ensemble held-out CV консенсус-представителя на НЕТРОНУТОМ хвосте
 *      <= CV_H_MAX (хвост никогда не ресемплится);
 *  (c) null-gate: рецидив на реальных > max рецидива null-ансамблей
 *      (y-перестановки Фишера-Йетса, seed base 2000+p).
 *
 * Вердикты: ENSEMBLE_CERT | UNSTABLE_CERTIFICATE (rec < UNSTABLE_GATE либо
 * консенсус не обобщается/нулевого уровня) | NO_CONSENSUS.
 *
 * Контракт seed'ов — от runner Demo #3: member k = seed 1000+k; null
 * perm p = 2000+p; null member seed = (2000+p)*100+k. Гейт-сетка циклится
 * по номеру члена в фиксированном порядке (детерминизм прогона).
 */
final class EnsembleCertifier
{
    /**
     * Прод-гейт-сетка θ (порядок фиксирован, цикл по члену).
     */
    public const GATE_GRID = [0.05, 0.075, 0.10, 0.125, 0.15];

    private const RECURRENCE_GATE = 0.80;

    private const UNSTABLE_GATE = 0.50;

    private const CV_H_MAX = 0.10;

    private const NULL_SEED_BASE = 2000;

    /**
     * F2 (agent-review deleg_258f3c12): демоушен обязан ПОТРЕБЛЯТЬСЯ.
     * Гейт записи кандидата как закона: UNSTABLE/NO_CONSENSUS не проходят
     * (CCPP-защита Demo #2 — дегенерат не должен становиться законом
     * despite пройденных single-run гейтов). Кандидат без ensemble_verdict
     * (ENSEMBLE off) проходит — поведение v1.6.
     *
     * @param array<string, mixed> $candidate
     */
    public static function shouldRecordCandidate(array $candidate): bool
    {
        $verdict = $candidate['ensemble_verdict'] ?? null;

        return ! in_array($verdict, ['UNSTABLE_CERTIFICATE', 'NO_CONSENSUS'], true);
    }

    /**
     * Включение WU-4: ENSEMBLE_K > 0 включает сертификацию (default 0 =
     * off, поведение v1.6); NO_ENSEMBLE=1 — скоростной обход (сильнее K).
     * PHP-falsy-гвард: getenv строки '0' фальси — сравнение !== false.
     */
    public static function isEnabled(): bool
    {
        $no = getenv('NO_ENSEMBLE');
        if ($no !== false && $no !== '' && $no !== '0') {
            return false;
        }
        $k = getenv('ENSEMBLE_K');

        return $k !== false && $k !== '' && (int) $k > 0;
    }

    /**
     * WU-4 wiring: сертификация кандидатов после успешного find (живой
     * путь DiscoveryEngine::discover). Дописывает в каждого кандидата
     * `ensemble_verdict`; при ENSEMBLE_CERT — shape + ensemble_anchor
     * (§1.9 п.6: INVARIANT(ensemble) + ENSEMBLE_ANCHOR(m̂, CI)). UNSTABLE
     * понижает кандидата despite пройденных single-run гейтов (CCPP).
     *
     * X/y null-безопасны: IdleDreamer зовёт recordDiscovery без данных —
     * пустые данные = ранний выход без исключения.
     *
     * @param list<array<string, mixed>> $candidates
     * @param array<string, mixed>|null $config
     */
    public static function runEnsembleCertification(array &$candidates, array $X, array $y, ?array $config = null): void
    {
        if (! self::isEnabled() || $candidates === [] || $X === [] || $y === []) {
            return;
        }
        $cfg = self::normalizeConfig($config ?? []);
        $envK = getenv('ENSEMBLE_K');
        if ($envK !== false && $envK !== '' && (int) $envK > 0) {
            $cfg['k'] = (int) $envK;
        }
        // Anchor-свидетель: одна строка данных задаёт масштаб m̂ = median(y/pred)
        // по хвосту для всех кандидатов (m̂ — свойство ДОМЕН-форма, не кандидата).
        $out = self::certify($X, $y, $cfg);
        self::stampCandidates($candidates, $out);
        self::log($cfg, 'INVARIANT(ensemble) ' . $out['verdict'] . ' rec=' . round($out['recurrence'], 3)
            . ' shape=' . ($out['shape'] ?? '-'));
    }

    /**
     * Штамп кандидатов вердиктом ансамбля. Сверка ФОРМЫ: вердикт относится
     * к консенсус-форме, не к любому кандидату (дефект пойман
     * EnsembleWiringTest: (x0+x1) получал ENSEMBLE_CERT от ансамбля,
     * сертифицировавшего (x2×x2)). Кандидат чужой формы сертификат не
     * получает.
     *
     * @param list<array<string, mixed>> $candidates
     * @param array<string, mixed> $out
     */
    private static function stampCandidates(array &$candidates, array $out): void
    {
        foreach ($candidates as &$c) {
            if (isset($c['ensemble_verdict'])) {
                continue;
            }
            $cShape = isset($c['atom']) && is_string($c['atom']) && $c['atom'] !== ''
                ? LawShape::of($c['atom']) : null;
            $c['ensemble_verdict'] = match (true) {
                $out['verdict'] === 'ENSEMBLE_CERT' && $cShape !== null && $cShape === $out['shape'] => 'ENSEMBLE_CERT',
                $out['verdict'] === 'NO_CONSENSUS' => 'NO_CONSENSUS',
                default => 'UNSTABLE_CERTIFICATE',
            };
            if ($c['ensemble_verdict'] === 'ENSEMBLE_CERT') {
                $c['ensemble_shape'] = $out['shape'];
                if ($out['ensemble_anchor'] !== null) {
                    $c['ensemble_anchor'] = $out['ensemble_anchor']['m_hat'];
                    $c['ensemble_anchor_ci'] = [$out['ensemble_anchor']['ci_lo'], $out['ensemble_anchor']['ci_hi']];
                }
            }
        }
        unset($c);
    }

    /**
     * Классификация по рецидиву, СОГЛАСОВАННАЯ с finalVerdict (F3,
     * agent-review deleg_258f3c12: rec∈[0.5,0.8) не может быть
     * ENSEMBLE_CERT — гейт (a) требует 0.80 от foundN). Публичный хелпер
     * для verdict-only сценариев без гейтов (b)/(c).
     */
    public static function verdictOf(float $recurrence, bool $singleRunFound): string
    {
        if (! $singleRunFound) {
            return 'NO_CONSENSUS';
        }
        if ($recurrence < self::UNSTABLE_GATE) {
            return 'UNSTABLE_CERTIFICATE';
        }
        if ($recurrence < self::RECURRENCE_GATE) {
            return 'NO_CONSENSUS';
        }

        return 'ENSEMBLE_CERT';
    }

    /**
     * @param array<string, mixed> $config k, depth, test_ratio, budget_sec,
     *   gate_grid, bootstrap_frac, split_seed, log_file, null_ensembles,
     *   null_k, null_seed_base
     * @return array{verdict: string, shape: ?string, recurrence: float,
     *   members: list<array<string, mixed>>, ensemble_cv_h: ?float,
     *   null_max_recurrence: ?float, cv_h_median: ?float,
     *   ensemble_anchor: null|array{m_hat: float, ci_lo: float, ci_hi: float, n: int},
     *   found_n: int, k: int, top_count: int}
     */
    public static function certify(array $X, array $y, array $config): array
    {
        // F4/F8 (agent-review deleg_258f3c12): прямой вызов certify пустыми
        // данными → warnings/undefined keys; runEnsembleCertification гвардит,
        // certify обязан гвардить сам (публичная точка входа).
        if (count($y) < 2 || $X === [] || count($X) !== count($y)) {
            return self::result('NO_CONSENSUS', null, 0.0, [], null, null, null, null, 0, (int) ($config['k'] ?? 25), 0);
        }
        $cfg = self::normalizeConfig($config);
        [$trainIdx, $tailX, $tailY] = self::splitRows($X, $y, $cfg['test_ratio'], $cfg['split_seed']);
        $grammar = new Grammar();
        $members = self::runMembers($X, $y, $trainIdx, $grammar, $cfg, 1000, 'ENS');
        [$foundN, $topShape, $topCount] = self::tallyMembers($members);
        $recurrence = $foundN > 0 ? $topCount / $foundN : 0.0;
        $nullMax = self::nullGateIfMeaningful($X, $y, $trainIdx, $grammar, $cfg, $foundN, $topShape, $recurrence);
        $cvH = null;
        $cvMedian = null;
        if ($topShape !== null) {
            $cvH = self::tailCv(self::representative($members, $topShape), $tailX, $tailY, $X, $trainIdx);
            $cvMedian = self::membersTailCvMedian($members, $topShape, $tailX, $tailY, $X, $trainIdx);
        }
        $verdict = self::finalVerdict($recurrence, $foundN, $cvH, $nullMax, $topShape !== null);
        $anchor = $topShape !== null
            ? self::ensembleAnchor(self::representative($members, $topShape), $tailX, $tailY, $X, $trainIdx)
            : null;
        self::logSummary($cfg, $verdict, $foundN, $cfg['k'], $topCount, $topShape, $cvH, $nullMax, $anchor);
        return self::result($verdict, $topShape, $recurrence, $members, $cvH, $cvMedian, $nullMax, $anchor, $foundN, $cfg['k'], $topCount);
    }

    /**
     * F6 (agent-review deleg_258f3c12, перф): null-gate только когда вердикт
     * ещё не предопределён (есть консенсус, рецидив >= UNSTABLE). Иначе
     * вердикт уже UNSTABLE/NO_CONSENSUS независимо от null — боевой default
     * 5×25×300s ≈ 10.4ч сжигаемого бюджета на шумном домене.
     */
    private static function nullGateIfMeaningful(array $X, array $y, array $trainIdx, Grammar $grammar, array $cfg, int $foundN, ?string $topShape, float $recurrence): ?float
    {
        if ($topShape === null || $foundN === 0 || $recurrence < self::UNSTABLE_GATE) {
            return null;
        }

        return self::runNullGate($X, $y, $trainIdx, $grammar, $cfg);
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeConfig(array $config): array
    {
        $k = max(1, (int) ($config['k'] ?? 25));
        // Операторский override бюджета (env, как ENSEMBLE_K): боевой default
        // 300s/член; тесты/wiring ставят ENSEMBLE_BUDGET_SEC (wall-clock-класс:
        // null/члены на шуме жгут ВЕСЬ budget_sec — питфолл SelfDiagnosis).
        $budget = (float) ($config['budget_sec'] ?? 300.0);
        if (! isset($config['budget_sec']) && ($envB = getenv('ENSEMBLE_BUDGET_SEC')) !== false && $envB !== '' && is_numeric($envB)) {
            $budget = (float) $envB;
        }
        // F8 (agent-review deleg_258f3c12): пустая gate_grid → modulo by zero.
        $gateGrid = $config['gate_grid'] ?? self::GATE_GRID;
        if ($gateGrid === [] || ! is_array($gateGrid)) {
            throw new \InvalidArgumentException('gate_grid не может быть пустым');
        }

        return [
            'k' => $k,
            'depth' => (int) ($config['depth'] ?? 3),
            'test_ratio' => (float) ($config['test_ratio'] ?? 0.2),
            'budget_sec' => $budget,
            'gate_grid' => $gateGrid,
            'bootstrap_frac' => (float) ($config['bootstrap_frac'] ?? 0.8),
            'split_seed' => (int) ($config['split_seed'] ?? 42),
            'log_file' => (string) ($config['log_file'] ?? ''),
            'null_ensembles' => (int) ($config['null_ensembles'] ?? 5),
            'null_k' => (int) ($config['null_k'] ?? $k),
            'null_seed_base' => (int) ($config['null_seed_base'] ?? self::NULL_SEED_BASE),
        ];
    }

    /**
     * Сплит train/хвост: порядок перемешан от split_seed, хвост — последние
     * test_ratio строк. Хвост живёт только здесь (гейт b), члены его не видят.
     *
     * @return array{0: array<int>, 1: array, 2: array}
     */
    private static function splitRows(array $X, array $y, float $testRatio, int $splitSeed): array
    {
        $n = count($y);
        // F4 (agent-review deleg_258f3c12, пойман пробой: test_ratio=0 →
        // DivisionByZeroError в tailCv на пустом хвосте): валидация сплита.
        if ($n < 2 || $testRatio <= 0.0 || $testRatio >= 1.0) {
            throw new \InvalidArgumentException(
                'certify требует n>=2 и testRatio в (0,1), got n=' . $n . ' ratio=' . $testRatio,
            );
        }
        $guard = RngIsolation::deterministicSeed($splitSeed);
        mt_srand($splitSeed);
        $order = range(0, $n - 1);
        for ($i = $n - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
        }
        $guard->restore();
        $splitRow = (int) floor($n * (1.0 - $testRatio));
        $trainIdx = array_slice($order, 0, $splitRow);
        $tailX = [];
        $tailY = [];
        foreach (array_slice($order, $splitRow) as $oi) {
            $tailX[] = $X[$oi];
            $tailY[] = $y[$oi];
        }

        return [$trainIdx, $tailX, $tailY];
    }

    /**
     * K членов последовательно (RAM: максимум 2 php-процесса на ноуте).
     *
     * @param array<int> $trainIdx
     * @return array<int, array<string, mixed>>
     */
    private static function runMembers(array $X, array $y, array $trainIdx, Grammar $grammar, array $cfg, int $seedBase, string $tag): array
    {
        $members = [];
        for ($k = 1; $k <= $cfg['k']; $k++) {
            $members[] = self::runOneMember($X, $y, $trainIdx, $grammar, $cfg, $seedBase + $k, $k, $tag);
        }

        return $members;
    }

    /**
     * Один член: bootstrap 80% train (with replacement, детерминированный
     * seed через RngIsolation) + гейт θ из сетки (цикл) + find.
     *
     * @param array<int> $trainIdx
     * @return array<string, mixed>
     */
    private static function runOneMember(array $X, array $y, array $trainIdx, Grammar $grammar, array $cfg, int $seed, int $memberNo, string $tag): array
    {
        $guard = RngIsolation::deterministicSeed($seed);
        mt_srand($seed);
        $cnt = count($trainIdx);
        $boot = [];
        $nBoot = (int) round($cnt * $cfg['bootstrap_frac']);
        for ($i = 0; $i < $nBoot; $i++) {
            $boot[] = $trainIdx[mt_rand(0, $cnt - 1)];
        }
        $theta = $cfg['gate_grid'][($memberNo - 1) % count($cfg['gate_grid'])];
        $Xb = [];
        $yb = [];
        foreach ($boot as $oi) {
            $Xb[] = $X[$oi];
            $yb[] = $y[$oi];
        }
        $t0 = microtime(true);
        $res = Search::find($Xb, $yb, $grammar, $cfg['depth'], null, 0.0, $theta, $cfg['budget_sec'], null);
        $guard->restore();
        return self::memberRecord($memberNo, $seed, $theta, $Xb, $res, (int) round((microtime(true) - $t0) * 10) / 10, $cfg, $tag);
    }

    /**
     * Запись результата члена (массив + лог).
     *
     * @param array<int> $Xb
     * @param array{0: mixed, 1: mixed, 2: mixed, 3: mixed, 4: mixed, 5: mixed} $res
     * @param array<string, mixed> $cfg
     * @return array<string, mixed>
     */
    private static function memberRecord(int $memberNo, int $seed, float $theta, array $Xb, array $res, float $wall, array $cfg, string $tag): array
    {
        $found = (bool) $res[0];
        $formula = $found ? (string) $res[2] : null;
        $member = [
            'member' => $memberNo,
            'seed' => $seed,
            'gate_theta' => $theta,
            'boot_n' => count($Xb),
            'found' => $found,
            'cv_train' => $res[1],
            'formula' => $formula,
            'wall_sec' => $wall,
            'shape' => $formula !== null ? LawShape::of($formula) : null,
        ];
        self::log($cfg, "{$tag} m{$memberNo} theta={$theta} found=" . var_export($found, true)
            . " cv={$member['cv_train']} shape=" . ($member['shape'] ?? '-'));

        return $member;
    }

    /**
     * @param array<int, array<string, mixed>> $members
     * @return array{0: int, 1: ?string, 2: int} foundN, topShape, topCount
     */
    private static function tallyMembers(array $members): array
    {
        $tally = new ShapeTally();
        $foundN = 0;
        foreach ($members as $m) {
            if ($m['found'] && is_string($m['formula'])) {
                $foundN++;
                $tally->add($m['formula']);
            }
        }
        $table = $tally->tally();
        $topShape = array_key_first($table);

        return [$foundN, $topShape !== null ? $topShape : null, $topShape !== null ? $table[$topShape] : 0];
    }

    /**
     * Null-gate (c): p = 1..null_ensembles, y переставляется в ИСХОДНОМ
     * пространстве строк (включая хвост — null модель обнуляет всё),
     * члены аналогичны реальным. Возвращает max рецидива по ансамблям
     * (доля от null-foundN) или null, если null_ensembles=0.
     */
    private static function runNullGate(array $X, array $y, array $trainIdx, Grammar $grammar, array $cfg): ?float
    {
        if ($cfg['null_ensembles'] <= 0 || $cfg['null_k'] <= 0) {
            return null;
        }
        $maxRec = 0.0;
        // F1 (agent-review deleg_258f3c12): null_k был мёртв — runMembers
        // циклил по cfg['k'], null-ансамбли всегда гоняли K членов. Копия
        // конфига с k=null_k: null-ансамбль = null_k членов (спека §1.9:
        // "null ансамбли x K members", null_k независимый параметр).
        $nullCfg = $cfg;
        $nullCfg['k'] = $cfg['null_k'];
        for ($p = 1; $p <= $cfg['null_ensembles']; $p++) {
            $permSeed = $cfg['null_seed_base'] + $p;
            $yNull = self::permuteY($y, $permSeed);
            $members = self::runMembers($X, $yNull, $trainIdx, $grammar, $nullCfg, $permSeed * 100, 'NENS' . $p);
            [$foundN, , $topCount] = self::tallyMembers($members);
            $rec = $foundN > 0 ? $topCount / $foundN : 0.0;
            $maxRec = max($maxRec, $rec);
        }

        return $maxRec;
    }

    /**
     * Fisher-Yates над y в исходном пространстве строк (null модель).
     */
    private static function permuteY(array $y, int $permSeed): array
    {
        $guard = RngIsolation::deterministicSeed($permSeed);
        mt_srand($permSeed);
        $perm = range(0, count($y) - 1);
        for ($i = count($y) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$perm[$i], $perm[$j]] = [$perm[$j], $perm[$i]];
        }
        $guard->restore();
        $out = [];
        foreach ($perm as $oi) {
            $out[] = $y[$oi];
        }

        return $out;
    }

    /**
     * Формула первого члена с консенсус-формой (представитель).
     *
     * @param array<int, array<string, mixed>> $members
     */
    private static function representative(array $members, string $topShape): string
    {
        foreach ($members as $m) {
            if ($m['found'] && $m['shape'] === $topShape && is_string($m['formula'])) {
                return $m['formula'];
            }
        }

        return '';
    }

    /**
     * Гейт (b): ratio-CV представителя на нетронутом хвосте (канал shift=0,
     * та же метрика, что Search::cvSingle). null = формула не оценена.
     *
     * @param array<int> $trainIdx
     */
    private static function tailCv(string $formula, array $tailX, array $tailY, array $X, array $trainIdx): ?float
    {
        if ($formula === '') {
            return null;
        }
        $trainRows = [];
        foreach ($trainIdx as $oi) {
            $trainRows[] = $X[$oi];
        }
        $stats = ExpressionEvaluator::collectStats($formula, $trainRows, [], []);
        $pred = ExpressionEvaluator::evaluateFormula($formula, $tailX, $stats, [], []);
        if ($pred === null || count($pred) !== count($tailY)) {
            return null;
        }

        return self::ratioCv($pred, $tailY);
    }

    /**
     * Ratio-CV pred/y по представимым строкам (F7: tailY≈0 → skip;
     * mean≈0 → sentinel 9.99; <2 строк → sentinel).
     *
     * @param list<float> $pred
     * @param list<float> $tailY
     */
    private static function ratioCv(array $pred, array $tailY): float
    {
        $ratio = [];
        foreach ($pred as $i => $pv) {
            // F7 (agent-review deleg_258f3c12): tailY≈0 (центрированные данные)
            // — знаменатель 0/знакопеременный; строка непредставима, skip
            // (симметрично ensembleAnchor-гварду по pred).
            if (abs($tailY[$i]) < 1e-8) {
                continue;
            }
            $ratio[] = $pv / $tailY[$i];
        }
        if (count($ratio) < 2) {
            return 9.99;
        }
        $mean = array_sum($ratio) / count($ratio);
        if (abs($mean) < 1e-8) {
            return 9.99;
        }
        $var = 0.0;
        foreach ($ratio as $r) {
            $var += ($r - $mean) ** 2;
        }

        return sqrt($var / count($ratio)) / abs($mean);
    }

    /**
     * Медиана tail-CV по членам консенсус-формы (разброс сертификата).
     *
     * @param array<int, array<string, mixed>> $members
     * @param array<int> $trainIdx
     */
    private static function membersTailCvMedian(array $members, string $topShape, array $tailX, array $tailY, array $X, array $trainIdx): ?float
    {
        $cvs = [];
        foreach ($members as $m) {
            if ($m['found'] && $m['shape'] === $topShape && is_string($m['formula'])) {
                $cv = self::tailCv($m['formula'], $tailX, $tailY, $X, $trainIdx);
                if ($cv !== null) {
                    $cvs[] = $cv;
                }
            }
        }
        if ($cvs === []) {
            return null;
        }
        sort($cvs);
        $mid = (int) floor(count($cvs) / 2);

        return count($cvs) % 2 === 1 ? $cvs[$mid] : ($cvs[$mid - 1] + $cvs[$mid]) / 2.0;
    }

    /**
     * Ensemble anchor (§1.9 п.6): если консенсус-форма не несёт масштаба
     * цели — m̂ = median(y/pred) по нетронутому хвосту. CI = квартили
     * ratio-распределения. Публикуется как статистика сертификационного
     * слоя, НЕ грамматик-константа — движок остаётся parameter-free.
     * null = формула не оценена (масштаб нечитаем — инконклюзив, не отказ).
     *
     * @return null|array{m_hat: float, ci_lo: float, ci_hi: float, n: int}
     */
    private static function ensembleAnchor(string $formula, array $tailX, array $tailY, array $X, array $trainIdx): ?array
    {
        if ($formula === '') {
            return null;
        }
        $trainRows = [];
        foreach ($trainIdx as $oi) {
            $trainRows[] = $X[$oi];
        }
        $stats = ExpressionEvaluator::collectStats($formula, $trainRows, [], []);
        $pred = ExpressionEvaluator::evaluateFormula($formula, $tailX, $stats, [], []);
        if ($pred === null || count($pred) !== count($tailY)) {
            return null;
        }
        $ratios = [];
        for ($i = 0; $i < count($pred); $i++) {
            if (abs($pred[$i]) < 1e-12) {
                continue; // деление на ~0: строка нечитаема, исключается
            }
            $ratios[] = $tailY[$i] / $pred[$i];
        }
        if (count($ratios) < 2) {
            return null;
        }
        sort($ratios);

        return self::anchorQuartiles($ratios);
    }

    /** Квартили ratio-распределения (m̂ = медиана). @param list<float> $ratios */
    private static function anchorQuartiles(array $ratios): array
    {
        $cnt = count($ratios);
        $q = fn (float $p): float => $ratios[(int) min($cnt - 1, (int) floor($p * $cnt))];

        return ['m_hat' => $q(0.5), 'ci_lo' => $q(0.25), 'ci_hi' => $q(0.75), 'n' => $cnt];
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private static function logSummary(array $cfg, string $verdict, int $foundN, int $k, int $topCount, ?string $shape, ?float $cvH, ?float $nullMax, ?array $anchor): void
    {
        self::log($cfg, "SUMMARY verdict={$verdict} found={$foundN}/{$k} rec={$topCount}/{$foundN}"
            . ' shape=' . ($shape ?? '-') . ' cv_h=' . self::fmt($cvH) . ' null_max=' . self::fmt($nullMax)
            . ' anchor=' . ($anchor === null ? 'null' : (string) round($anchor['m_hat'], 4)));
    }

    /**
     * Иммутабельная сборка результата certify().
     *
     * @param list<array<string, mixed>> $members
     * @return array{verdict: string, shape: ?string, recurrence: float, members: list<array<string, mixed>>, ensemble_cv_h: ?float, null_max_recurrence: ?float, cv_h_median: ?float, ensemble_anchor: null|array{m_hat: float, ci_lo: float, ci_hi: float, n: int}, found_n: int, k: int, top_count: int}
     */
    private static function result(string $verdict, ?string $shape, float $recurrence, array $members, ?float $cvH, ?float $cvMedian, ?float $nullMax, ?array $anchor, int $foundN, int $k, int $topCount): array
    {
        return [
            'verdict' => $verdict,
            'shape' => $shape,
            'recurrence' => $recurrence,
            'members' => $members,
            'ensemble_cv_h' => $cvH,
            'null_max_recurrence' => $nullMax,
            'cv_h_median' => $cvMedian,
            'ensemble_anchor' => $anchor,
            'found_n' => $foundN,
            'k' => $k,
            'top_count' => $topCount,
        ];
    }

    /**
     * Финальный вердикт: комбинирует классификацию рецидива с гейтами (b)/(c).
     */
    private static function finalVerdict(float $recurrence, int $foundN, ?float $cvH, ?float $nullMax, bool $hasShape): string
    {
        if (! $hasShape || $foundN === 0) {
            return 'NO_CONSENSUS';
        }
        if ($recurrence < self::UNSTABLE_GATE) {
            return 'UNSTABLE_CERTIFICATE';
        }
        if ($recurrence < self::RECURRENCE_GATE) {
            return 'NO_CONSENSUS';
        }
        if ($cvH === null || $cvH > self::CV_H_MAX) {
            return 'UNSTABLE_CERTIFICATE';
        }
        if ($nullMax !== null && $recurrence <= $nullMax) {
            return 'UNSTABLE_CERTIFICATE';
        }

        return 'ENSEMBLE_CERT';
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private static function log(array $cfg, string $line): void
    {
        if ($cfg['log_file'] === '') {
            return;
        }
        file_put_contents($cfg['log_file'], '[' . gmdate('c') . '] ' . $line . PHP_EOL, FILE_APPEND);
    }

    private static function fmt(?float $v): string
    {
        return $v === null ? 'null' : (string) round($v, 4);
    }
}
