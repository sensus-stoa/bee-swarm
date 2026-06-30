<?php
declare(strict_types=1);
namespace BeeSwarm;

/**
 * ExpressionTree: вычисляет деревья выражений без хардкода.
 * Когда рой изобретает операцию, её определение сохраняется как дерево —
 * Grammar может вычислить ЛЮБОЕ дерево без правки PHP.
 */
class ExpressionTree
{
    private array $tree;
    
    public function __construct(array|string $tree)
    {
        $this->tree = is_string($tree) ? json_decode($tree, true) : $tree;
    }
    
    /**
     * Вычисляет дерево для пары (a, b).
     * Узлы: {"op":"+","left":X,"right":Y} — бинарная операция
     *       {"op":"abs","arg":X} — унарная операция
     *       "a" / "b" — переменные
     *        число — константа
     */
    public function evaluate(float $a, float $b): float
    {
        return $this->evalNode($this->tree, $a, $b);
    }
    
    private function evalNode(array|string|float $node, float $a, float $b): float
    {
        if (is_numeric($node)) return (float)$node;
        if ($node === 'a') return $a;
        if ($node === 'b') return $b;
        if (is_string($node)) return 0.0;
        
        $op = $node['op'] ?? '?';
        
        return match ($op) {
            '+' => $this->evalNode($node['left'], $a, $b) + $this->evalNode($node['right'], $a, $b),
            '−' => $this->evalNode($node['left'], $a, $b) - $this->evalNode($node['right'], $a, $b),
            '×' => $this->evalNode($node['left'], $a, $b) * $this->evalNode($node['right'], $a, $b),
            '/' => $this->evalNode($node['left'], $a, $b) / max($this->evalNode($node['right'], $a, $b), 1e-8),
            'abs' => abs($this->evalNode($node['arg'], $a, $b)),
            'sq' => $this->evalNode($node['arg'], $a, $b) ** 2,
            'pow' => $this->evalNode($node['left'] ?? $node['arg'], $a, $b) ** $this->evalNode($node['right'] ?? 2.0, $a, $b),
            'min' => min($this->evalNode($node['left'], $a, $b), $this->evalNode($node['right'], $a, $b)),
            'max' => max($this->evalNode($node['left'], $a, $b), $this->evalNode($node['right'], $a, $b)),
            default => 0.0,
        };
    }
    
    /**
     * Сериализует дерево в JSON для хранения в БД.
     */
    public function toJson(): string
    {
        return json_encode($this->tree);
    }
    
    /**
     * Строит дерево из формулы Search.
     * «(x0+x1−abs(x0−x1))/K2» → JSON-дерево
     */
    public static function fromFormula(string $formula): ?self
    {
        // Упрощённый парсер для формул Search
        $tree = self::parseFormula($formula);
        return $tree ? new self($tree) : null;
    }
    
    private static function parseFormula(string $f): ?array
    {
        // Простые случаи
        if ($f === 'x0') return 'a';
        if ($f === 'x1') return 'b';
        if (is_numeric($f)) return (float)$f;
        if (preg_match('/^K(\d+(\.\d+)?)$/', $f)) return (float)substr($f, 1);
        
        // Унарные: (x0 op) → {"op":"op","arg":"a"}
        if (preg_match('/^\(x0(\w+)\)$/', $f, $m)) {
            return ['op' => $m[1], 'arg' => 'a'];
        }
        if (preg_match('/^\(x1(\w+)\)$/', $f, $m)) {
            return ['op' => $m[1], 'arg' => 'b'];
        }
        
        return null; // сложные формулы парсить не будем — используем встроенные
    }
}
