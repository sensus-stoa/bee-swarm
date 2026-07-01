<?php
declare(strict_types=1);

namespace BeeSwarm;

class Grammar
{
    public const BASE_OPS = [
        '+' => ['fn' => 'add', 'symbol' => '+'],
        '×' => ['fn' => 'mul', 'symbol' => '×'],
        '−' => ['fn' => 'sub', 'symbol' => '−'],
        '/' => ['fn' => 'div', 'symbol' => '/'],
    ];
    
    private array $ops = [];
    
    public function __construct()
    {
        // Базовые операции всегда доступны
        $this->ops = self::BASE_OPS;
        
        $db = Database::get();
        $rows = $db->query("SELECT name, definition FROM grammar_ops")->fetchAll();
        foreach ($rows as $row) {
            $name = $row['name'];
            // Не перезаписываем базовые — дополняем
            if (!isset($this->ops[$name])) {
                $this->ops[$name] = ['fn' => 'custom_' . $name, 'symbol' => $name];
                if ($row['definition']) $this->ops[$name]['definition'] = $row['definition'];
            }
        }
    }
    
    public function add(string $name, string $source = 'invented', ?string $definition = null): bool
    {
        if (isset($this->ops[$name])) return false;
        
        $this->ops[$name] = ['fn' => 'custom_' . $name, 'symbol' => $name];
        if ($definition) $this->ops[$name]['definition'] = $definition;
        
        $db = Database::get();
        $db->prepare("INSERT OR IGNORE INTO grammar_ops (name, source, definition) VALUES (?,?,?)")
           ->execute([$name, $source, $definition]);
        return true;
    }
    
    public function reloadFromDb(): void
    {
        $db = Database::get();
        $rows = $db->query("SELECT name, definition FROM grammar_ops")->fetchAll();
        $this->ops = [];
        foreach ($rows as $row) {
            $name = $row['name'];
            if (isset(self::BASE_OPS[$name])) {
                $this->ops[$name] = self::BASE_OPS[$name];
            } else {
                $this->ops[$name] = ['fn' => 'custom_' . $name, 'symbol' => $name];
                if ($row['definition']) $this->ops[$name]['definition'] = $row['definition'];
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
            default => $this->applyCustom($a, $b, $op),
        };
    }
    
    private function applyCustom(float $a, float $b, string $op): ?float
    {
        // 1. Проверяем definition в БД (динамическое определение)
        $def = $this->ops[$op]['definition'] ?? null;
        if ($def) {
            $tree = new ExpressionTree($def);
            return $tree->evaluate($a, $b);
        }
        
        // 2. Хардкод для базовых операций (временно)
        if ($op === 'MIN') return min($a, $b);
        if ($op === 'MAX') return max($a, $b);
        if ($op === 'abs') return abs($a);
        // powN: a^b (e.g. pow2 means 2^x)
        if (str_starts_with($op, 'pow') && strlen($op) > 3) {
            $base = (float)substr($op, 3);
            return $base ** $a;
        }
        // parity: (-1)^(a%2)
        if ($op === 'parity') {
            $mod = (int)$a % 2;
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
        return null;
    }
    
    public function all(): array { return array_keys($this->ops); }
    public function count(): int { return count($this->ops); }
    
    /** Unary operations: those that only need one argument */
    /** Унарные операции */
    public function getUnaryOps(): array
    {
        $unary = [];
        foreach ($this->ops as $name => $info) {
            if (in_array($name, ['log2', 'inverse', 'parity', 'abs', 'sqrt']) || str_starts_with($name, 'pow')) {
                $unary[] = $name;
            }
        }
        return $unary;
    }
    
    /** Ограничить грамматику конкретными операциями (для изоляции парадигм) */
    public function restrictTo(array $allowedOps): void
    {
        $filtered = [];
        foreach ($allowedOps as $op) {
            if (isset($this->ops[$op])) $filtered[$op] = $this->ops[$op];
        }
        $this->ops = $filtered;
    }
    
    /** Полностью очистить грамматику */
    public function clearAll(): void
    {
        $this->ops = [];
    }
}
