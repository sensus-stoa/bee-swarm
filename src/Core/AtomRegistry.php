<?php
declare(strict_types=1);

namespace BeeSwarm\Core;

use BeeSwarm\Core\AtomDefinitions;
use BeeSwarm\Validation\LawValidator;
use BeeSwarm\Validation\RetrospectiveValidator;
use BeeSwarm\Math\CvCalculator;

class AtomRegistry
{
    // Held-out validation (HONEST_CRITERIA §1.1)
    private const HO_MIN_POINTS = 3;
    private const HO_SPLIT_RATIO = 5;
    private const CV_TRAIN_MAX = 0.01;
    private const CV_HOLDOUT_MAX = 0.10;
    private const CV_EXACT_TOLERANCE = 0.0001;

    private static bool $heldoutEnabled = true;
    private static array $fnCache = [];
    private static ?array $envAtoms = null;

    // ═══ АЛФАВИТ ИЗ СРЕДЫ ═══

    /** Загрузить все числовые PHP-функции (один раз, кэшируется) */
    public static function loadEnvironment(): array
    {
        if (self::$envAtoms !== null) return self::$envAtoms;

        $all = get_defined_functions()['internal'];
        $unary = []; $binary = [];
        
        $skip = AtomDefinitions::ENV_SKIP_PREFIXES;
        
        foreach ($all as $fn) {
            $skipIt = false;
            foreach ($skip as $p) if (str_starts_with($fn, $p)) { $skipIt = true; break; }
            if ($skipIt) continue;
            
            // Унарный тест (in-process)
            ob_start();
            try { $r = @$fn(-5.0); } catch (\Throwable $e) { $r = null; }
            ob_get_clean();
            if ($r !== null && $r !== false && !is_array($r) && !is_object($r) && !is_string($r) && !is_bool($r)) {
                $rf = (float)$r;
                if (!is_nan($rf) && !is_infinite($rf)) { $unary[] = $fn; continue; }
            }
            
            // Бинарный тест
            ob_start();
            try { $r = @$fn(1.0, 2.0); } catch (\Throwable $e) { $r = null; }
            ob_get_clean();
            if ($r !== null && $r !== false && !is_array($r) && !is_object($r) && !is_string($r) && !is_bool($r)) {
                $rf = (float)$r;
                if (!is_nan($rf) && !is_infinite($rf)) $binary[] = $fn;
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

    public static function all(): array
    {
        $curated = array_merge(AtomDefinitions::UNARY, AtomDefinitions::BINARY);
        $env = self::$envAtoms ?? [];
        return array_values(array_unique(array_merge($curated, $env)));
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
            'abs'   => abs($a),
            'sqrt'  => $a >= 0 ? sqrt($a) : null,
            'sin'   => sin($a),
            'cos'   => cos($a),
            'tan'   => (abs(cos($a)) > 1e-10) ? tan($a) : null,
            'asin'  => ($a >= -1 && $a <= 1) ? asin($a) : null,
            'acos'  => ($a >= -1 && $a <= 1) ? acos($a) : null,
            'atan'  => atan($a),
            'sinh'  => sinh($a),
            'cosh'  => cosh($a),
            'tanh'  => tanh($a),
            'exp'   => ($a < 20) ? exp($a) : null,
            'log'   => ($a > 0) ? log($a) : null,
            'log10' => ($a > 0) ? log10($a) : null,
            'log1p' => ($a > -1) ? log1p($a) : null,
            'floor' => floor($a),
            'ceil'  => ceil($a),
            'round' => round($a),
            'deg2rad' => deg2rad($a),
            'rad2deg' => rad2deg($a),
            'sq'    => $a * $a,
            'cube'  => $a * $a * $a,
            'inv'   => ($a != 0) ? 1.0 / $a : null,
            'neg'   => -$a,
            'sign'  => $a > 0 ? 1.0 : ($a < 0 ? -1.0 : 0.0),
            'relu'  => max(0.0, $a),
            'not'   => $a > 0 ? 0.0 : 1.0,
            
            // Бинарные с b
            'add'   => $a + $b,
            'sub'   => $a - $b,
            'mul'   => $a * $b,
            'div'   => ($b != 0) ? $a / $b : null,
            'mod'   => ($b != 0) ? fmod($a, $b) : null,
            'min'   => min($a, $b),
            'max'   => max($a, $b),
            'hypot' => hypot($a, $b),
            'pow'   => $a ** $b,
            'fmod'  => ($b != 0) ? fmod($a, $b) : null,
            'gt'    => ($a > $b) ? 1.0 : 0.0,
            'lt'    => ($a < $b) ? 1.0 : 0.0,
            'eq'    => (abs($a - $b) < 0.001) ? 1.0 : 0.0,
            'neq'   => (abs($a - $b) >= 0.001) ? 1.0 : 0.0,
            'and'   => (($a > 0) && ($b > 0)) ? 1.0 : 0.0,
            'or'    => (($a > 0) || ($b > 0)) ? 1.0 : 0.0,
            
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

    private static function evaluateHeldout(string $formula, array $X, array $y): ?array
    {
        return LawValidator::evaluateHeldout($formula, $X, $y);
    }

    // ═══ COMPOSE: пары grammar-атомов ═══

    /**
     * Перебирает все пары grammar-атомов, возвращает compose с CV=0.
     */
    public static function discoverCompose(array $X, array $y, array $grammar): array
    {
        return AtomProvider::discoverCompose($X, $y, $grammar);
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
                    $v = self::apply($atom, (float)$row[0], (float)$row[1]);
                } elseif (self::isUnary($atom)) {
                    $v = self::apply($atom, (float)$row[0]);
                } else {
                    $valid = false; break;
                }
                if ($v === null || is_nan($v) || is_infinite($v)) {
                    $valid = false; break;
                }
                $vec[] = $v;
            }

            if (!$valid || count($vec) !== $n) continue;

            $cv = self::cv($vec, $y);
            $signal = max(0, 1.0 - min(1.0, $cv)) * $novelty;
            $total += $signal;
            $domains[$domain] = ($domains[$domain] ?? 0) + $signal;
        }

        return [
            'total'   => $total,
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

    // ═══ CV ═══

    public static function isHeldoutEnabled(): bool
    {
        return self::$heldoutEnabled;
    }

    public static function setHeldoutEnabled(bool $enabled): void
    {
        self::$heldoutEnabled = $enabled;
    }

    public static function cv(array $vec, array $y): float
    {
        return CvCalculator::compute($vec, $y);
    }
}
