<?php

declare(strict_types=1);

namespace BeeSwarm\Core;

use BeeSwarm\Infra\Database;
use BeeSwarm\Knowledge\ConceptRegistry;

class Grammar
{
    /** @var string[]|null кэш unary ops (GRAMMAR-BIRTH, 06.08) */
    private ?array $unaryOpsCache = null;

    public const BASE_OPS = [
        '+' => [
            'fn' => 'add',
            'symbol' => '+',
        ],
        '×' => [
            'fn' => 'mul',
            'symbol' => '×',
        ],
        '−' => [
            'fn' => 'sub',
            'symbol' => '−',
        ],
        '/' => [
            'fn' => 'div',
            'symbol' => '/',
        ],
        'min' => [
            'fn' => 'min',
            'symbol' => 'min',
        ],
        'max' => [
            'fn' => 'max',
            'symbol' => 'max',
        ],
        'sq' => [
            'fn' => 'sq',
            'symbol' => 'sq',
        ],
    ];

    // Семантические предикаты — такие же атомы как + и ×, но над концептами
    public const SEMANTIC_OPS = ['is_a', 'has', 'relates_to', 'can'];

    private array $ops = [];

    public function __construct()
    {
        // Базовые операции всегда доступны
        $this->ops = self::BASE_OPS;

        // Семантические атомы из знания (knowledge_graph)
        foreach (self::SEMANTIC_OPS as $semOp) {
            $this->ops[$semOp] = [
                'fn' => 'semantic_' . $semOp,
                'symbol' => $semOp,
                'semantic' => true,
            ];
        }

        $db = Database::get();
        $rows = $db->query('SELECT name, definition FROM grammar_ops')
            ->fetchAll();
        foreach ($rows as $row) {
            $name = $row['name'];
            // Не перезаписываем базовые — дополняем
            if (! isset($this->ops[$name])) {
                $this->ops[$name] = [
                    'fn' => 'custom_' . $name,
                    'symbol' => $name,
                ];
                if ($row['definition']) {
                    $this->ops[$name]['definition'] = $row['definition'];
                }
            }
        }
    }

    public function add(string $name, string $source = 'invented', ?string $definition = null, string $birthDomain = ''): bool
    {
        if (isset($this->ops[$name])) {
            return false;
        }

        $this->ops[$name] = [
            'fn' => 'custom_' . $name,
            'symbol' => $name,
        ];
        if ($definition) {
            $this->ops[$name]['definition'] = $definition;
        }

        $db = Database::get();
        $db->prepare('INSERT OR IGNORE INTO grammar_ops (name, source, definition, birth_domain) VALUES (?,?,?,?)')
            ->execute([$name, $source, $definition, $birthDomain]);
        return true;
    }

    public function reloadFromDb(): void
    {
        $db = Database::get();
        $rows = $db->query('SELECT name, definition FROM grammar_ops')
            ->fetchAll();
        $this->ops = [];
        foreach ($rows as $row) {
            $name = $row['name'];
            if (isset(self::BASE_OPS[$name])) {
                $this->ops[$name] = self::BASE_OPS[$name];
            } else {
                $this->ops[$name] = [
                    'fn' => 'custom_' . $name,
                    'symbol' => $name,
                ];
                if ($row['definition']) {
                    $this->ops[$name]['definition'] = $row['definition'];
                }
            }
        }
    }

    public function apply(float $a, float $b, string $op): ?float
    {
        return match ($op) {
            '+' => $a + $b,
            '×' => $a * $b,
            '−' => $a - $b,
            '/' => ($b != 0) ? $a / $b : null,
            'add' => $a + $b,
            'sub' => $a - $b,
            'mul' => $a * $b,
            'div' => ($b != 0) ? $a / $b : null,
            default => $this->applyCustom($a, $b, $op),
        };
    }

    /**
     * S1.9-GENERATIVE: reduce(op, vector) — arity bridge (float[]→float).
     *
     * Generative rule (AXIOM, не оператор): любой АССОЦИАТИВНЫЙ бинарный
     * оператор грамматики применим к вектору через reduce.
     * Не-ассоциативные (−, /) и семантические предикаты → null.
     * ОДНА аксиома вместо sum/mean/correl вручную.
     */
    public function reduce(string $op, array $vector): ?float
    {
        $n = count($vector);
        if ($n === 0) {
            return null;
        }

        return match ($op) {
            '+' => array_sum($vector),
            '×' => array_product($vector),
            'max' => max($vector),
            'min' => min($vector),
            default => null,
        };
    }

    private function applyCustom(float $a, float $b, string $op): ?float
    {
        // GRAMMAR-BIRTH фаза 2: рождённый атом (definition) — через AtomRegistry
        if (str_starts_with($op, 'B')) {
            $v = \BeeSwarm\Core\AtomRegistry::apply($op, $a, $b);
            if ($v !== null) {
                return $v;
            }
        }

        // ОРИГИНАЛЬНАЯ логика custom ops (abs/min/max/inverse/pow/parity/semantic)
        return $this->applyCustomOriginal($a, $b, $op);
    }

    private function applyCustomOriginal(float $a, float $b, string $op): ?float
    {
        // 0. Семантические операции: запрос к knowledge_graph
        if (($this->ops[$op]['semantic'] ?? false)) {
            return ConceptRegistry::checkFact($a, $op, $b);
        }

        // 1. Проверяем definition в БД (динамическое определение)
        // AST-CACHE (26.08, EXP-029): new ExpressionTree() на КАЖДЫЙ вызов
        // = миллионы парсингов (540 строк × пары × B-вызовы = 20x slowdown,
        // heat/airfoil TIMEOUT). Кэшируем дерево по имени оператора.
        $def = $this->ops[$op]['definition'] ?? null;
        if ($def) {
            // REVIEW deleg_fe365da6: ключ = имя + md5(definition) — иначе
            // изменение определения атома в БД (rebirth/PROMOTED) оставляет
            // устаревшее дерево в кэше долгоживущего процесса = тихо неверный CV.
            static $treeCache = [];
            $key = $op . ':' . md5($def);
            if (! isset($treeCache[$key])) {
                $treeCache[$key] = new ExpressionTree($def);
            }
            return $treeCache[$key]->evaluate($a, $b);
        }

        // 2. Хардкод для базовых операций (временно)
        $opLower = strtolower($op);
        if ($opLower === 'min') {
            return min($a, $b);
        }
        if ($opLower === 'max') {
            return max($a, $b);
        }
        if ($opLower === 'abs') {
            return abs($a);
        }
        if ($opLower === 'sq') {
            return $a * $a;
        }
        // powN: a^b (e.g. pow2 means 2^x)
        if (str_starts_with($op, 'pow') && strlen($op) > 3) {
            $base = (float) substr($op, 3);
            return $base ** $a;
        }
        // parity: (-1)^(a%2)
        if ($op === 'parity') {
            $mod = (int) $a % 2;
            return $mod === 0 ? 1.0 : -1.0;
        }
        // log2: log2(x)
        if ($op === 'log2') {
            return log(max($a, 0.001)) / log(2);
        }
        // inverse: 1/x
        if ($op === 'inverse') {
            return $a != 0 ? 1.0 / $a : null;
        }
        // inv (alias for inverse)
        if ($op === 'inv') {
            return $a != 0 ? 1.0 / $a : null;
        }
        // sqrt
        if ($op === 'sqrt') {
            return $a >= 0 ? sqrt($a) : null;
        }
        // neg (unary negation)
        if ($op === 'neg') {
            return -$a;
        }
        return null;
    }

    public function all(): array
    {
        return array_keys($this->ops);
    }

    /**
     * GRAMMAR-PROPAGATION (ЭКСП-012): вес оператора (культурная эволюция).
     */
    public function usageCount(string $op): int
    {
        $db = \BeeSwarm\Infra\Database::get();
        $v = $db->query("SELECT usage_count FROM grammar_ops WHERE name = " . $db->quote($op))->fetchColumn();
        return $v === false ? 0 : (int) $v;
    }

    /**
     * GRAMMAR-PROPAGATION (ЭКСП-012): увеличить вес оператора после успеха.
     */
    /**
     * GRAMMAR-PROPAGATION: статический boost (из Hive, без инстанса).
     */
    /**
     * GRAMMAR-PROPAGATION: weights op→usage_count (топ-100) для weightedPick.
     */
    public static function weightsFromDb(): array
    {
        $db = \BeeSwarm\Infra\Database::get();
        $rows = $db->query(
            'SELECT name, usage_count FROM grammar_ops ORDER BY usage_count DESC LIMIT 100'
        )->fetchAll(\PDO::FETCH_ASSOC);
        $w = [];
        foreach ($rows as $r) {
            $w[$r['name']] = (float) $r['usage_count'];
        }
        return $w;
    }

    /**
     * GRAMMAR-BIRTH (ЭКСП-015): статический add с definition (из Hive).
     */
    public static function staticAdd(string $name, string $source, string $definition, string $birthDomain = ''): void
    {
        $db = \BeeSwarm\Infra\Database::get();
        // REUSE-CRITERION-BIRTH (10.08): рождение = КАНДИДАТ (двухфазность);
        // активным становится после reuse≥1 (registerReuse → PROMOTED).
        $status = $source === 'birth' ? 'candidate' : 'active';
        $db->prepare('INSERT OR IGNORE INTO grammar_ops (name, source, definition, birth_domain, status) VALUES (?, ?, ?, ?, ?)')
            ->execute([$name, $source, $definition, $birthDomain, $status]);
        // Рождение атома → инвалидация null-кэша AtomRegistry
        \BeeSwarm\Core\AtomRegistry::clearDefCache();
    }

    /**
     * REUSE-TRACKING (06.08): B-атом использован в discovery домена.
     */
    public static function registerReuse(string $name, string $domain): void
    {
        // REUSE-CRITERION-BIRTH (10.08): reuse≥1 → PROMOTED (candidate→active)
        try {
            \BeeSwarm\Infra\Database::get()->prepare(
                'UPDATE grammar_ops SET status = \'active\' WHERE name = ? AND source = \'birth\''
            )->execute([$name]);
        } catch (\Throwable $e) {
            // колонка status может отсутствовать (старая БД без миграции)
        }
        $db = \BeeSwarm\Infra\Database::get();
        // CONCERNS deleg_71cd0698: SHORT-CIRCUIT — UPDATE только при НОВОМ
        // домене (SET-семантика: reuse_count = число УНИКАЛЬНЫХ доменов,
        // не частотомер хитов!). Повторный хит того же домена — no-op.
        $cur = $db->prepare('SELECT reuse_domains FROM grammar_ops WHERE name = ?');
        $cur->execute([$name]);
        $domains = json_decode((string) ($cur->fetchColumn() ?: '[]'), true) ?: [];
        if (in_array($domain, $domains, true)) {
            return;
        }
        $domains[] = $domain;
        $stmt = $db->prepare('UPDATE grammar_ops SET reuse_count = reuse_count + 1, reuse_domains = ? WHERE name = ? AND source = \'birth\'');
        $stmt->execute([json_encode($domains), $name]);
    }

    public static function staticBoostOp(string $op, int $delta = 1): void
    {
        $db = \BeeSwarm\Infra\Database::get();
        $db->prepare('INSERT INTO grammar_ops (name, source, usage_count) VALUES (?, ?, ?)
            ON CONFLICT(name) DO UPDATE SET usage_count = usage_count + excluded.usage_count')
            ->execute([$op, 'boost', $delta]);
    }

    public function boostOp(string $op, int $delta = 1): void
    {
        $db = \BeeSwarm\Infra\Database::get();
        $db->prepare('INSERT INTO grammar_ops (name, source, usage_count) VALUES (?, ?, ?)
            ON CONFLICT(name) DO UPDATE SET usage_count = usage_count + excluded.usage_count')
            ->execute([$op, 'boost', $delta]);
    }

    public function count(): int
    {
        return count($this->ops);
    }

    /** Unary operations: those that only need one argument */
    /**
     * Унарные операции
     */
    public function getUnaryOps(): array
    {
        $unary = [];
        foreach ($this->ops as $name => $_) {
            if (in_array($name, ['log2', 'inverse', 'parity', 'abs', 'sqrt', 'sq', 'neg', 'inv']) || str_starts_with($name, 'pow')) {
                $unary[] = $name;
            }
        }
        // GRAMMAR-BIRTH фаза 2: рождённые атомы (source='birth') — унарные
        // абстракции, вычисляются через definition (AtomRegistry::apply)
        $db = \BeeSwarm\Infra\Database::get();
        // ТОЛЬКО топ-10 по reuse — иначе B-атомы раздувают unary pool
        foreach ($db->query(
            "SELECT name FROM grammar_ops WHERE source = 'birth' ORDER BY reuse_count DESC LIMIT 10"
        ) as $row) {
            $unary[] = $row['name'];
        }
        return $unary;
    }

    /**
     * Ограничить грамматику конкретными операциями (для изоляции парадигм)
     */
    public function restrictTo(array $allowedOps): void
    {
        $filtered = [];
        foreach ($allowedOps as $op) {
            if (isset($this->ops[$op])) {
                $filtered[$op] = $this->ops[$op];
            }
        }
        $this->ops = $filtered;
    }

    /**
     * Создать Grammar только из указанных операций — без чтения общей БД.
     * Для per-bee грамматик (§2.3 изоляция).
     *
     * @param string[] $opNames имена операций
     */
    public static function fromOps(array $opNames): self
    {
        $g = new self();
        // Не читаем из БД — перезаписываем ops напрямую
        $g->ops = [];
        $allKnown = array_merge(self::BASE_OPS, self::SEMANTIC_OPS);
        foreach ($opNames as $op) {
            if (isset($allKnown[$op])) {
                $g->ops[$op] = $allKnown[$op];
            } elseif (isset(self::BASE_OPS[$op])) {
                $g->ops[$op] = self::BASE_OPS[$op];
            } else {
                $g->ops[$op] = [
                    'fn' => 'custom_' . $op,
                    'symbol' => $op,
                ];
            }
        }
        return $g;
    }

    /**
     * @return string[] имена базовых операций (всегда доступны всем пчёлам)
     */
    public static function baseOpNames(): array
    {
        return array_keys(self::BASE_OPS);
    }

    /**
     * Полностью очистить грамматику
     */
    public function clearAll(): void
    {
        $this->ops = [];
    }

    /**
     * D11: Возвращает BASE_OPS + топ-N кастомных ops по частоте в законах.
     * Частота = количество использований формулы в laws-таблице.
     * @return string[] имена операторов
     */
    public function capped(int $limit): array
    {
        $base = array_keys(self::BASE_OPS);
        $db = Database::get();
        $rows = $db->prepare(
            'SELECT formula, SUM(usage_count) as cnt FROM laws GROUP BY formula ORDER BY cnt DESC LIMIT ?'
        );
        $rows->execute([$limit]);
        $top = $rows->fetchAll(\PDO::FETCH_COLUMN);

        return array_values(array_unique(array_merge($base, $top)));
    }
}
