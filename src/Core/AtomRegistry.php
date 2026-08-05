<?php

declare(strict_types=1);

namespace BeeSwarm\Core;

use BeeSwarm\Math\CvCalculator;
use BeeSwarm\Validation\LawValidator;
use BeeSwarm\Validation\RetrospectiveValidator;

class AtomRegistry
{
    // Held-out validation (HONEST_CRITERIA §1.1)
    private const HO_MIN_POINTS = 3;

    private const HO_SPLIT_RATIO = 5;

    private const CV_TRAIN_MAX = 0.01;

    private const CV_HOLDOUT_MAX = 0.10;

    private const CV_EXACT_TOLERANCE = 0.0001;

    /**
     * Стоп-слова для text-atom label'ов (E1.3-fix, 04.08.2026).
     * Label — это кандидат в метрику («GI:», «sleep:»), а не любое слово
     * перед двоеточием. Числа, римские цифры и служебные слова — мусор.
     */
    private const TEXT_ATOM_STOPWORDS = [
        // русские
        'и', 'в', 'на', 'с', 'не', 'то', 'же', 'как', 'так', 'он', 'она', 'оно', 'они', 'мы', 'вы', 'ты',
        'это', 'этот', 'эта', 'эти', 'там', 'тут', 'ещё', 'уже', 'для', 'что', 'нет', 'или', 'да', 'но',
        'а', 'за', 'из', 'от', 'до', 'при', 'под', 'над', 'об', 'во', 'со', 'ко', 'по', 'бы', 'ли', 'ль',
        'б', 'про', 'без', 'у', 'к', 'о', 'же', 'вот', 'все', 'всё', 'всегда', 'иногда', 'потом', 'сейчас',
        'только', 'также', 'например', 'кстати', 'вообще', 'конечно', 'вероятно', 'возможно', 'нужно',
        'можно', 'нельзя', 'должен', 'должна', 'должны', 'будет', 'быть', 'есть', 'был', 'была', 'были',
        // английские
        'the', 'and', 'for', 'with', 'not', 'but', 'you', 'that', 'this', 'it', 'is', 'are', 'was',
        'were', 'has', 'have', 'had', 'will', 'would', 'can', 'could', 'should', 'may', 'might', 'must',
        'of', 'in', 'on', 'at', 'to', 'from', 'by', 'as', 'be', 'or', 'if', 'then', 'than', 'so', 'too',
        'very', 'just', 'only', 'also', 'still', 'even', 'well', 'now', 'here', 'there', 'when', 'where',
        'why', 'how', 'what', 'which', 'who', 'whom', 'whose', 'do', 'does', 'did', 'done', 'being',
        'been', 'about', 'into', 'over', 'under', 'again', 'further', 'once', 'all', 'any', 'both',
        'each', 'few', 'more', 'most', 'other', 'some', 'such', 'no', 'nor', 'own', 'same', 'than',
        'i', 'me', 'my', 'myself', 'we', 'our', 'ours', 'ourselves', 'your', 'yours', 'yourself',
        'yourselves', 'he', 'him', 'his', 'himself', 'she', 'her', 'hers', 'herself', 'its', 'itself',
        'they', 'them', 'their', 'theirs', 'themselves', 'these', 'those', 'am', 'having', 'because',
        'until', 'while', 'against', 'between', 'through', 'during', 'before', 'after', 'above', 'below',
        'up', 'down', 'out', 'off', 'first', 'second', 'third', 'last', 'next', 'left', 'right',
    ];

    private static bool $heldoutEnabled = true;

    private static ?array $envAtoms = null;

    // ═══ АЛФАВИТ ИЗ СРЕДЫ ═══

    /**
     * Загрузить все числовые PHP-функции (один раз, кэшируется)
     */
    public static function loadEnvironment(): array
    {
        if (self::$envAtoms !== null) {
            return self::$envAtoms;
        }

        $all = get_defined_functions()['internal'];
        $unary = [];
        $binary = [];

        $skip = AtomDefinitions::ENV_SKIP_PREFIXES;

        foreach ($all as $fn) {
            $skipIt = false;
            foreach ($skip as $p) {
                if (str_starts_with($fn, $p)) {
                    $skipIt = true;
                    break;
                }
            }
            if ($skipIt) {
                continue;
            }

            // Унарный тест (in-process)
            ob_start();
            try {
                $r = @$fn(-5.0);
            } catch (\Throwable $e) {
                $r = null;
            }
            ob_get_clean();
            if ($r !== null && $r !== false && ! is_array($r) && ! is_object($r) && ! is_string($r) && ! is_bool($r)) {
                $rf = (float) $r;
                if (! is_nan($rf) && ! is_infinite($rf)) {
                    $unary[] = $fn;
                    continue;
                }
            }

            // Бинарный тест
            ob_start();
            try {
                $r = @$fn(1.0, 2.0);
            } catch (\Throwable $e) {
                $r = null;
            }
            ob_get_clean();
            if ($r !== null && $r !== false && ! is_array($r) && ! is_object($r) && ! is_string($r) && ! is_bool($r)) {
                $rf = (float) $r;
                if (! is_nan($rf) && ! is_infinite($rf)) {
                    $binary[] = $fn;
                }
            }
        }

        self::$envAtoms = array_values(array_unique(array_merge($unary, $binary)));
        return self::$envAtoms;
    }

    public static function isEnvironmentLoaded(): bool
    {
        return self::$envAtoms !== null;
    }

    // ═══ РЕЕСТР ═══

    private const TEXT_ATOMS = ['preg_match', 'match_label', 'extract_col'];

    private static array $discoveredAtoms = [];

    public static function all(): array
    {
        $curated = array_merge(AtomDefinitions::UNARY, AtomDefinitions::BINARY, self::TEXT_ATOMS);
        $mathAtoms = array_merge(AtomDefinitions::UNARY, AtomDefinitions::BINARY);
        $collision = array_intersect($mathAtoms, self::TEXT_ATOMS);
        if ($collision) {
            throw new \RuntimeException('TEXT_ATOMS collision with math atoms: ' . implode(', ', $collision));
        }
        $discovered = array_keys(self::$discoveredAtoms);
        // §2.3 Phase 5c: читать открытые формулы из laws, не из grammar_ops
        try {
            $db = \BeeSwarm\Infra\Database::get();
            foreach ($db->query("SELECT DISTINCT formula FROM laws") as $r) {
                $discovered[] = $r['formula'];
            }
            // V0.8.5 fix: загружать текст-атомы из grammar_ops
            // (E1 feedback loop сохраняет их туда, но они не попадают в laws)
            foreach ($db->query("SELECT name FROM grammar_ops WHERE source='discovered' AND name LIKE '%(%)%'") as $r) {
                $discovered[] = $r['name'];
                self::$discoveredAtoms[$r['name']] = true;
            }
        } catch (\PDOException) {
        }
        $env = self::$envAtoms ?? [];
        return array_values(array_unique(array_merge($curated, $discovered, $env)));
    }

    public static function isTextAtom(string $name): bool
    {
        return in_array($name, self::TEXT_ATOMS, true) || isset(self::$discoveredAtoms[$name]);
    }

    /**
     * E1.3-fix: проверяет, зарегистрирован ли text-атом ранее.
     * Используется в Hive::doTick чтобы повторные вхождения слова
     * не сбрасывали plateau (foundAny). Атом в БД ≠ новое открытие.
     */
    public static function isDiscoveredTextAtom(string $name): bool
    {
        return isset(self::$discoveredAtoms[$name]);
    }

    /**
     * E1.3-fix: валидация label для text-атомов.
     * Отсекает мусор: числа, римские цифры, короткие (<3), небуквенные,
     * стоп-слова. Label должен быть кандидатом в метрику.
     */
    public static function isValidTextAtomLabel(string $label): bool
    {
        $len = mb_strlen($label);
        if ($len < 2 || $len > 40) {
            return false;
        }
        // Только буквы (кириллица/латиница) — числа и смешанные отсекаются
        if (! preg_match('/^[A-Za-zА-Яа-яЁё]+$/u', $label)) {
            return false;
        }
        // Римские цифры (III, IV, X, XL...)
        if (preg_match('/^[IVXLCDM]+$/i', $label)
            && preg_match('/^(?=[MDCLXVI])M*(C[MD]|D?C{0,3})(X[CL]|L?X{0,3})(I[XV]|V?I{0,3})$/i', $label)
        ) {
            return false;
        }
        // Стоп-слова (регистронезависимо)
        if (in_array(mb_strtolower($label), self::TEXT_ATOM_STOPWORDS, true)) {
            return false;
        }
        return true;
    }

    /**
     * E1.3-fix: есть ли в результате text-атома РЕАЛЬНЫЕ данные.
     *
     * match_label → [7.2, 6.0] — числа → true.
     * preg_match с группами → [[7.2,...], ...] — непустые группы → true.
     * preg_match без групп → [[], [], []] — пустые вхождения → false.
     * (Безгрупповые вхождения НЕ считаются открытием в Hive, но остаются
     * доступны Forager/StreamingAccumulator для частотных foraged_txt_* задач.)
     */
    public static function hasTextAtomData(array $result): bool
    {
        foreach ($result as $row) {
            if (is_numeric($row)) {
                return true;
            }
            if (is_array($row) && ! empty($row)) {
                return true;
            }
        }
        return false;
    }

    /**
     * /** Register discovered text atom (compose: match_label + arg) */
    public static function addDiscoveredTextAtom(string $parentAtom, string $arg): void
    {
        if (! self::isTextAtom($parentAtom)) {
            return;
        }
        if ($arg === '') {
            return;
        }
        // E1.3-fix: мусорные label не персистим в grammar_ops (защита БД)
        if (! self::isValidTextAtomLabel($arg)) {
            return;
        }
        $name = "{$parentAtom}({$arg})";
        if (isset(self::$discoveredAtoms[$name])) {
            return;
        }
        self::$discoveredAtoms[$name] = true;
        // Persist to grammar_ops
        try {
            \BeeSwarm\Infra\Database::get()->prepare('INSERT OR IGNORE INTO grammar_ops (name,source) VALUES (?,?)')
                ->execute([$name, 'discovered']);
        } catch (\PDOException) {
        }
    }

    /**
     * Apply text atom to raw content
     */
    public static function applyTextAtom(string $name, string $content, string $arg = ''): mixed
    {
        // Decompose composed name like match_label(GI)
        if (preg_match('/^(\w+)\((.+)\)$/', $name, $m)) {
            $name = $m[1];
            $arg = $arg ?: $m[2];
        }
        $result = match ($name) {
            'match_label' => (function (string $c, string $label): array {
                preg_match_all('/' . preg_quote($label, '/') . ':\s*([\d.]+)/u', $c, $m);
                if (empty($m[1])) {
                    return [];
                }
                return array_map('floatval', $m[1]);
            })($content, $arg),
            'preg_match' => (function (string $c, string $pattern): array {
                $r = @preg_match_all('{' . $pattern . '}u', $c, $m, PREG_SET_ORDER);
                if ($r === false || empty($m)) {
                    return [];
                }
                $pairs = [];
                foreach ($m as $match) {
                    array_shift($match);
                    $pairs[] = array_values($match);
                }
                return $pairs;
            })($content, $arg),
            'extract_col' => (function (string $c, string $col): array {
                // Apply preg_match first, then extract column
                $matches = (function (string $ct, string $p): array {
                    $r = @preg_match_all('{' . $p . '}u', $ct, $m, PREG_SET_ORDER);
                    return ($r === false || empty($m)) ? [] : $m;
                })($c, '(\w+):\s+([\d.]+)');
                $col = (int) $col;
                $result = [];
                foreach ($matches as $m) {
                    array_shift($m); // full match
                    if (isset($m[$col])) {
                        $result[] = $m[$col];
                    }
                }
                return $result;
            })($content, $arg),
            default => null,
        };
        return $result;
    }

    public static function isUnary(string $name): bool
    {
        return in_array($name, AtomDefinitions::UNARY, true);
    }

    public static function isBinary(string $name): bool
    {
        // Resolve alias first
        if (isset(AtomDefinitions::BINARY[$name]) && is_string(AtomDefinitions::BINARY[$name])) {
            return true;
        }
        return in_array($name, AtomDefinitions::BINARY, true);
    }

    // ═══ RESOLVE ═══
    private static function resolve(string $name): string
    {
        return (isset(AtomDefinitions::BINARY[$name]) && is_string(AtomDefinitions::BINARY[$name]))
            ? AtomDefinitions::BINARY[$name] : $name;
    }

    // ═══ ПРИМЕНЕНИЕ ═══

    public static function apply(string $name, float $a, ?float $b = null): ?float
    {
        $name = self::resolve($name);
        $isBinary = self::isBinary($name);

        if ($isBinary && $b === null) {
            return null; // бинарный атом требует два аргумента
        }

        return match ($name) {
            // Унарные
            'abs' => abs($a),
            'sqrt' => $a >= 0 ? sqrt($a) : null,
            'sin' => sin($a),
            'cos' => cos($a),
            'tan' => (abs(cos($a)) > 1e-10) ? tan($a) : null,
            'asin' => ($a >= -1 && $a <= 1) ? asin($a) : null,
            'acos' => ($a >= -1 && $a <= 1) ? acos($a) : null,
            'atan' => atan($a),
            'sinh' => sinh($a),
            'cosh' => cosh($a),
            'tanh' => tanh($a),
            'exp' => ($a < 20) ? exp($a) : null,
            'log' => ($a > 0) ? log($a) : null,
            'log10' => ($a > 0) ? log10($a) : null,
            'log1p' => ($a > -1) ? log1p($a) : null,
            'floor' => floor($a),
            'ceil' => ceil($a),
            'round' => round($a),
            'deg2rad' => deg2rad($a),
            'rad2deg' => rad2deg($a),
            'sq' => $a * $a,
            'cube' => $a * $a * $a,
            'inv' => ($a != 0) ? 1.0 / $a : null,
            'inverse' => ($a != 0) ? 1.0 / $a : null,
            'neg' => -$a,
            'log2' => log(max($a, 0.001)) / log(2),
            'parity' => ((int) $a % 2 === 0) ? 1.0 : -1.0,
            'sign' => $a > 0 ? 1.0 : ($a < 0 ? -1.0 : 0.0),
            'relu' => max(0.0, $a),
            'not' => $a > 0 ? 0.0 : 1.0,

            // Бинарные с b
            'add' => $a + $b,
            'sub' => $a - $b,
            'mul' => $a * $b,
            'div' => ($b != 0) ? $a / $b : null,
            'mod' => ($b != 0) ? fmod($a, $b) : null,
            'min' => min($a, $b),
            'max' => max($a, $b),
            'hypot' => hypot($a, $b),
            'pow' => $a ** $b,
            'fmod' => ($b != 0) ? fmod($a, $b) : null,
            'gt' => ($a > $b) ? 1.0 : 0.0,
            'lt' => ($a < $b) ? 1.0 : 0.0,
            'eq' => (abs($a - $b) < 0.001) ? 1.0 : 0.0,
            'neq' => (abs($a - $b) >= 0.001) ? 1.0 : 0.0,
            'and' => (($a > 0) && ($b > 0)) ? 1.0 : 0.0,
            'or' => (($a > 0) || ($b > 0)) ? 1.0 : 0.0,

            default => null,
        };
    }

    // ═══ DISCOVER: найти все атомы с CV=0 на данных ═══

    /**
     * Перебирает ВСЕ атомы среды, возвращает те что дают CV=0.
     * @param array $X массив признаков [[x0, x1, ...], ...]
     * @param array $y целевые значения
     * @return array [{atom, cv, mode}, ...]
     */
    public static function discover(array $X, array $y): array
    {
        return AtomProvider::discover($X, $y);
    }

    /**
     * discover с held-out validation (HONEST_CRITERIA §1.1).
     * h = max(1, floor(n/5)) точек откладываются.
     * Поиск на train, приём: CV_train ≤ 0.01 И CV_holdout ≤ 0.10.
     */
    public static function discoverHeldout(array $X, array $y): array
    {
        return LawValidator::discoverHeldout($X, $y);
    }

    // ═══ COMPOSE: пары grammar-атомов ═══

    /**
     * Перебирает все пары grammar-атомов, возвращает compose с CV=0.
     */
    public static function discoverCompose(array $X, array $y, array $grammar, ?float $cvThreshold = null): array
    {
        return AtomProvider::discoverCompose($X, $y, $grammar, $cvThreshold);
    }

    // ═══ СИГНАЛ ═══

    /**
     * Накопленный сигнал атома по набору задач.
     * Сигнал = Σ(1 − CV) × novelty(domain)
     */
    public static function accumulateSignal(array $tasks, string $atom): array
    {
        $total = 0.0;
        $domains = [];

        foreach ($tasks as $task) {
            $X = $task['X'];
            $y = $task['y'];
            $domain = $task['domain'] ?? 'unknown';
            $novelty = $task['novelty'] ?? 1.0;
            $nFeat = count($X[0] ?? []);
            $n = count($y);

            $vec = [];
            $valid = true;

            foreach ($X as $row) {
                if (self::isBinary($atom) && $nFeat >= 2) {
                    $v = self::apply($atom, (float) $row[0], (float) $row[1]);
                } elseif (self::isUnary($atom)) {
                    $v = self::apply($atom, (float) $row[0]);
                } else {
                    $valid = false;
                    break;
                }
                if ($v === null || is_nan($v) || is_infinite($v)) {
                    $valid = false;
                    break;
                }
                $vec[] = $v;
            }

            if (! $valid || count($vec) !== $n) {
                continue;
            }

            $cv = self::cv($vec, $y);
            $signal = max(0, 1.0 - min(1.0, $cv)) * $novelty;
            $total += $signal;
            $domains[$domain] = ($domains[$domain] ?? 0) + $signal;
        }

        return [
            'total' => $total,
            'domains' => count($domains),
            'by_domain' => $domains,
        ];
    }

    // ═══ CV ═══

    /**
     * Ретроспективная валидация всех законов (HONEST_CRITERIA §1.1).
     * Принимает массив tasks с данными, проверяет каждый закон через held-out.
     * Возвращает ['passed' => [...], 'overfit' => [...]].
     */
    public static function retrospectiveValidate(array $tasks): array
    {
        return RetrospectiveValidator::validate($tasks);
    }

    /**
     * §0.7: System Null-Calibration — run full pipeline on shuffled data.
     * Returns ['fpr' => float, 'trials' => int, 'false_discoveries' => int, 'pass' => bool]
     */
    public static function runNullCalibration(array $domains, int $trialsPerDomain = 20): array
    {
        $totalFalseDiscoveries = 0;
        $totalTrials = 0;

        foreach ($domains as $domain) {
            $X = $domain['X'];
            $y = $domain['y'];

            for ($trial = 0; $trial < $trialsPerDomain; $trial++) {
                $shuffledY = $y;
                shuffle($shuffledY);
                $discoveries = self::discoverHeldout($X, $shuffledY);
                if (! empty($discoveries)) {
                    $totalFalseDiscoveries++;
                }
                $totalTrials++;
            }
        }

        return [
            'fpr' => $totalTrials > 0 ? $totalFalseDiscoveries / $totalTrials : 0,
            'trials' => $totalTrials,
            'false_discoveries' => $totalFalseDiscoveries,
            'pass' => $totalFalseDiscoveries === 0,
        ];
    }

    // ═══ CV ═══

    public static function isHeldoutEnabled(): bool
    {
        return self::$heldoutEnabled;
    }

    public static function setHeldoutEnabled(bool $enabled): void
    {
        self::$heldoutEnabled = $enabled;
    }

    /**
     * Check if atom is trivial (HONEST_CRITERIA §1.4): identity, constant, algebraic reduction
     */
    public static function isTrivial(string $atom, array $X, array $y): bool
    {
        // Feature references: x0, x1, x2...
        if (preg_match('/^x\d+$/', $atom)) {
            return true;
        }

        // Constants: K1, K2.5, K-3...
        if (preg_match('/^K-?[\d.]+$/', $atom)) {
            return true;
        }

        // Algebraic reductions (§1.4): identity, double negation, idempotence
        if (str_contains($atom, '(')) {
            if (preg_match('/^(add|\\+)\\(.+,0\\)$/', $atom)) {
                return true;
            }
            if (preg_match('/^(mul|×)\\(.+,1\\)$/', $atom)) {
                return true;
            }
            if (preg_match('/^(sub|−)\\(.+,0\\)$/', $atom)) {
                return true;
            }
            if (preg_match('/^(div|\\/)\\(.+,1\\)$/', $atom)) {
                return true;
            }
            if (preg_match('/^neg\\(neg\\(.+\\)\\)$/', $atom)) {
                return true;
            }
            if (preg_match('/^inv\\(inv\\(.+\\)\\)$/', $atom)) {
                return true;
            }
            if (preg_match('/^abs\\(abs\\(.+\\)\\)$/', $atom)) {
                return true;
            }
            if (preg_match('/^min\\(([^,]+),\\1\\)$/', $atom)) {
                return true;
            }
            if (preg_match('/^max\\(([^,]+),\\1\\)$/', $atom)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Complexity: 1 для простых атомов, 1+N для compose
     * /** Complexity: 1 для простых атомов, nodes для compose */
    public static function atomComplexity(string $atom): int
    {
        if (! str_contains($atom, '(')) {
            return 1;
        }
        $tree = ExpressionTree::fromFormula($atom);
        return $tree ? $tree->nodeCount() : 1 + substr_count($atom, '(');
    }

    public static function cv(array $vec, array $y): float
    {
        return CvCalculator::compute($vec, $y);
    }

    /**
     * Compression superiority (HONEST_CRITERIA §1.7): atom must beat y=mean baseline
     * /** Compression superiority (HONEST_CRITERIA §1.7): cost(f) < cost(mean) */
    public static function isBetterThanBaseline(float $cvAtom, string $atom, ?float $cvMean = null): bool
    {
        $complexity = self::atomComplexity($atom);

        $costAtom = $complexity + log(1.0 + $cvAtom, 2);
        $costMean = 1.0 + log(1.0 + ($cvMean ?? $cvAtom), 2); // если cvMean не указан — сравниваем с baseline=1

        return $costAtom < $costMean;
    }
}
