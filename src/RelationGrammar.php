<?php
declare(strict_types=1);
namespace BeeSwarm;

/**
 * RelationGrammar: единая грамматика отношений.
 * Не {+,−} для чисел и {is_a,can} для слов.
 * Одна структура: R(x, y) → z.
 * self-apply → новый R. self-invert → обратный R.
 */
class RelationGrammar
{
    private array $relations = [];
    
    // Базовые отношения (атомы)
    public function __construct(string $domain = 'base')
    {
        switch ($domain) {
            case 'arithmetic':
                $this->relations = ['+' => ['arity'=>2, 'inv'=>null], '−' => ['arity'=>2, 'inv'=>'+']];
                break;
            case 'language':
                $this->relations = ['is_a'=>['arity'=>2], 'can'=>['arity'=>2], 'has'=>['arity'=>2]];
                break;
            case 'ethics':
                $this->relations = ['virtue_of'=>['arity'=>2], 'action_of'=>['arity'=>2]];
                break;
            default:
                $this->relations = ['+' => ['arity'=>2, 'inv'=>null]];
        }
    }
    
    /** Применить self-apply: R + R → новый R */
    public function selfApply(string $rel): ?string
    {
        if ($rel === '+') return '×';      // + + + = ×
        if ($rel === '×') return '^';      // × × × = ^
        if ($rel === 'is_a') return 'is_a'; // is_a ∘ is_a = is_a (транзитивность)
        if ($rel === 'can') return 'can_chain'; // can ∘ can = цепочка способностей
        return null;
    }
    
    /** Применить self-invert: undo(R) → обратное отношение */
    public function selfInvert(string $rel): ?string
    {
        if ($rel === '+') return '−';
        if ($rel === '×') return '/';
        if ($rel === '^') return 'sqrt';
        if ($rel === 'is_a') return 'contains';
        if ($rel === 'can') return 'can_be_done_by';
        if ($rel === 'has') return 'belongs_to';
        return null;
    }
    
    /** Применить оба правила ко всем отношениям — получить новое поколение */
    public function mutate(): array
    {
        $new = [];
        foreach ($this->relations as $name => $info) {
            $sa = $this->selfApply($name);
            if ($sa && !isset($this->relations[$sa]) && !in_array($sa, $new)) {
                $new[] = $sa;
            }
            $si = $this->selfInvert($name);
            if ($si && !isset($this->relations[$si]) && !in_array($si, $new)) {
                $new[] = $si;
            }
        }
        // Добавляем новые отношения
        foreach ($new as $r) {
            $this->relations[$r] = ['arity'=>2, 'source'=>'mutated'];
        }
        return $new;
    }
    
    public function all(): array { return array_keys($this->relations); }
    public function count(): int { return count($this->relations); }
    
    /** Задать отношения напрямую */
    public function setRelations(array $rels): void {
        $this->relations = [];
        foreach ($rels as $r) $this->relations[$r] = ['arity'=>2, 'source'=>'manual'];
    }
}
