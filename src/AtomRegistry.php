<?php
declare(strict_types=1);

namespace BeeSwarm;

class AtomRegistry
{
    private static array $unary = [
        'abs','sqrt','sin','cos','tan','asin','acos','atan',
        'sinh','cosh','tanh','exp','log','log10','log1p',
        'floor','ceil','round','deg2rad','rad2deg',
        'sq','cube','inv','neg','sign','relu','not',
    ];

    private static array $binary = [
        'add','sub','mul','div','mod',
        'min','max','hypot','pow','fmod',
        'gt','lt','eq','neq','and','or',
        // Grammar-compatible aliases
        '+' => 'add', '−' => 'sub', '×' => 'mul', '/' => 'div',
    ];

    private static array $fnCache = [];
    private static ?array $envAtoms = null;

    // ═══ АЛФАВИТ ИЗ СРЕДЫ ═══

    /** Загрузить все числовые PHP-функции (один раз, кэшируется) */
    public static function loadEnvironment(): array
    {
        if (self::$envAtoms !== null) return self::$envAtoms;

        $all = get_defined_functions()['internal'];
        $unary = []; $binary = [];
        
        $skip = ['set_','ini_','header','session','ob_','error_report','trigger_error',
                 'define','class_','function_','method_','trait_','interface_',
                 'stream','socket','curl','exec','proc_','pcntl','posix',
                 'mysql','pg_','oci_','odbc','sqlite','pdo','mongo',
                 'image','gd_','exif','openssl','hash','password','crypt',
                 'xml_encode','xml_decode','simplexml','dom_',
                 'mb_','iconv','locale','date_default','timezone',
                 'apache','fastcgi','php_ini','zend_','opcache','xdebug',
                 'readline','ncurses','newt',
                 'print','echo','printf','sprintf','vprintf','vsprintf',
                 'var_dump','var_export','print_r','debug_','highlight_',
                 'json_encode','json_decode'];
        
        foreach ($all as $fn) {
            $skipIt = false;
            foreach ($skip as $p) if (str_starts_with($fn, $p)) { $skipIt = true; break; }
            if ($skipIt) continue;
            
            // Унарный тест (in-process)
            ob_start();
            try { $r = @$fn(-5.0); } catch (\Throwable $e) { $r = null; }
            $out = ob_get_clean();
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
        $curated = array_merge(self::$unary, self::$binary);
        $env = self::$envAtoms ?? [];
        return array_values(array_unique(array_merge($curated, $env)));
    }

    public static function isUnary(string $name): bool
    {
        return in_array($name, self::$unary, true);
    }

    public static function isBinary(string $name): bool
    {
        // Resolve alias first
        if (isset(self::$binary[$name]) && is_string(self::$binary[$name])) {
            return true;
        }
        return in_array($name, self::$binary, true);
    }

    // ═══ RESOLVE ═══
    private static function resolve(string $name): string
    {
        return (isset(self::$binary[$name]) && is_string(self::$binary[$name]))
            ? self::$binary[$name] : $name;
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
        $found = [];
        $nFeat = count($X[0] ?? []);
        $n = count($y);

        foreach (self::all() as $atom) {
            $vec = [];
            $valid = true;

            foreach ($X as $row) {
                if (self::isBinary($atom) && $nFeat >= 2) {
                    $v = self::apply($atom, (float)$row[0], (float)$row[1]);
                } elseif (self::isUnary($atom)) {
                    $v = self::apply($atom, (float)$row[0]);
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

            if (!$valid || count($vec) !== $n) continue;

            $cv = self::cv($vec, $y);
            if ($cv < 0.001) {
                $found[] = [
                    'atom' => $atom,
                    'cv'   => $cv,
                    'mode' => self::isBinary($atom) ? 'binary' : 'unary',
                ];
            }
        }

        return $found;
    }

    // ═══ COMPOSE: пары grammar-атомов ═══

    /**
     * Перебирает все пары grammar-атомов, возвращает compose с CV=0.
     */
    public static function discoverCompose(array $X, array $y, array $grammar): array
    {
        $found = [];
        $nFeat = count($X[0] ?? []);
        $n = count($y);

        foreach ($grammar as $outer) {
            foreach ($grammar as $inner) {
                if ($outer === $inner) continue;
                if (!self::isUnary($outer) && !self::isBinary($outer)) continue;
                if (!self::isUnary($inner) && !self::isBinary($inner)) continue;

                $vec = [];
                $valid = true;

                foreach ($X as $row) {
                    // Inner
                    if (self::isBinary($inner) && $nFeat >= 2) {
                        $v1 = self::apply($inner, (float)$row[0], (float)$row[1]);
                    } elseif (self::isUnary($inner)) {
                        $v1 = self::apply($inner, (float)$row[0]);
                    } else {
                        $valid = false; break;
                    }
                    if ($v1 === null || is_nan($v1) || is_infinite($v1)) {
                        $valid = false; break;
                    }

                    // Outer
                    if (self::isBinary($outer) && $nFeat >= 3) {
                        $v2 = self::apply($outer, $v1, (float)$row[2]);
                    } elseif (self::isBinary($outer) && $nFeat >= 2) {
                        $v2 = self::apply($outer, $v1, (float)$row[1]);
                    } elseif (self::isUnary($outer)) {
                        $v2 = self::apply($outer, $v1);
                    } else {
                        $valid = false; break;
                    }
                    if ($v2 === null || is_nan($v2) || is_infinite($v2)) {
                        $valid = false; break;
                    }
                    $vec[] = $v2;
                }

                if (!$valid || count($vec) !== $n) continue;

                $cv = self::cv($vec, $y);
                if ($cv < 0.001) {
                    $found[] = [
                        'atom' => "{$outer}({$inner})",
                        'cv'   => $cv,
                        'mode' => 'compose',
                    ];
                }
            }
        }

        return $found;
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

    public static function cv(array $vec, array $y): float
    {
        $n = count($vec);
        if ($n < 2) return 9.99;

        // Exact match
        for ($i = 0; $i < $n; $i++) {
            if (abs($vec[$i] - $y[$i]) > 0.0001) break;
            if ($i === $n - 1) return 0.0;
        }

        $ratios = [];
        for ($i = 0; $i < $n; $i++) {
            $denom = $y[$i] + 1e-8;
            if (abs($denom) < 1e-10) return 9.99;
            $ratios[] = $vec[$i] / $denom;
        }

        $mean = array_sum($ratios) / $n;
        if (abs($mean) < 1e-8) return 9.99;

        $variance = 0.0;
        foreach ($ratios as $r) $variance += ($r - $mean) ** 2;
        return sqrt($variance / $n) / abs($mean);
    }
}
