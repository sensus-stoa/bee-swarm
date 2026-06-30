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
        $db = Database::get();
        $rows = $db->query("SELECT name FROM grammar_ops")->fetchAll();
        foreach ($rows as $row) {
            $name = $row['name'];
            if (isset(self::BASE_OPS[$name])) {
                $this->ops[$name] = self::BASE_OPS[$name];
            } else {
                $this->ops[$name] = ['fn' => 'custom_' . $name, 'symbol' => $name];
            }
        }
    }
    
    public function add(string $name, string $source = 'invented'): bool
    {
        if (isset($this->ops[$name])) return false;
        $this->ops[$name] = ['fn' => 'custom_' . $name, 'symbol' => $name];
        $db = Database::get();
        $db->prepare("INSERT OR IGNORE INTO grammar_ops (name, source) VALUES (?, ?)")
            ->execute([$name, $source]);
        return true;
    }
    
    public function reloadFromDb(): void
    {
        $db = Database::get();
        $rows = $db->query("SELECT name FROM grammar_ops")->fetchAll();
        $this->ops = [];
        foreach ($rows as $row) {
            $name = $row['name'];
            if (isset(self::BASE_OPS[$name])) {
                $this->ops[$name] = self::BASE_OPS[$name];
            } else {
                $this->ops[$name] = ['fn' => 'custom_' . $name, 'symbol' => $name];
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
    public function getUnaryOps(): array
    {
        $unary = [];
        foreach ($this->ops as $name => $info) {
            // Custom ops that are unary: log2, inverse, parity, powN
            if (in_array($name, ['log2', 'inverse', 'parity']) || str_starts_with($name, 'pow')) {
                $unary[] = $name;
            }
        }
        return $unary;
    }
}
