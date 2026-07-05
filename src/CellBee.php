<?php
declare(strict_types=1);
namespace BeeSwarm;

use BeeSwarm\Core\Search;

use BeeSwarm\Core\Grammar;

/**
 * CellBee: пчела-клетка.
 * Своя грамматика (мембрана). Своя энергия. Своя история.
 * Мутирует при провалах. Умирает при energy<0. Делится при energy>0.8.
 */
class CellBee
{
    public RelationGrammar $grammar;
    public float $energy = 0.5;
    public string $domain;
    public string $id;
    public int $successes = 0;
    public int $failures = 0;
    public array $history = [];
    
    private static int $counter = 0;
    
    public function __construct(string $domain = 'arithmetic')
    {
        $this->id = 'cell_' . (++self::$counter);
        $this->domain = $domain;
        $this->grammar = new RelationGrammar($domain);
    }
    
    /**
     * Поиск CV→0. С СОБСТВЕННОЙ grammar пчелы (in-memory, без БД).
     */
    public function search(array $X, array $y): array
    {
        // Строим временный Grammar из отношений пчелы — БЕЗ записи в БД
        $g = new Grammar();
        $g->clearAll();
        // Обходим add() и пишем напрямую в ops (in-memory only)
        $refl = new \ReflectionClass($g);
        $prop = $refl->getProperty('ops');
        $prop->setAccessible(true);
        $ops = [];
        foreach ($this->grammar->all() as $rel) {
            $ops[$rel] = ['fn' => 'custom_' . $rel, 'symbol' => $rel];
        }
        $prop->setValue($g, $ops);
        
        return Search::find($X, $y, $g, 2);
    }
    
    /**
     * Обновлённый live: grammar — своя (не из БД).
     * Штраф за тривиальные законы.
     */
    public function live(array $X, array $y): array
    {
        [$ok, $cv, $formula] = $this->search($X, $y);
        $isTrivial = false;
        
        if ($ok) {
            // Сложность = число уникальных операторов + число переменных
            $hasX0 = str_contains($formula, 'x0');
            $hasX1 = str_contains($formula, 'x1');
            $hasAbs = str_contains($formula, 'abs');
            $hasSqrt = str_contains($formula, 'sqrt');
            $hasPow = str_contains($formula, 'pow');
            $hasLog = str_contains($formula, 'log');
            
            $complexity = ($hasX0 ? 1 : 0) + ($hasX1 ? 1 : 0) 
                        + ($hasAbs ? 2 : 0) + ($hasSqrt ? 2 : 0) 
                        + ($hasPow ? 2 : 0) + ($hasLog ? 2 : 0);
            
            // Тривиально: сложность 0 или 1 (только константы или одна переменная)
            $isTrivial = $complexity <= 1;
            $gain = $isTrivial ? 0.03 : 0.15;
            $this->energy = min(1.5, $this->energy + $gain);
            $this->successes++;
        } else {
            $this->energy -= 0.1;
            $this->failures++;
            
            // ПРОВАЛ → МУТАЦИЯ (своя grammar, не БД)
            if ($this->failures % 3 === 0) {
                $mutated = $this->grammar->mutate();
                if (!empty($mutated)) {
                    $this->history[] = ['event' => 'mutate', 'added' => $mutated];
                }
            }
        }
        
        return [
            'bee' => $this->id, 'domain' => $this->domain,
            'ok' => $ok, 'cv' => $cv, 'formula' => $formula,
            'energy' => $this->energy,
            'grammar_size' => $this->grammar->count(),
            'trivial' => $isTrivial,
        ];
    }
    
    /** Деление: spawn с мутированной грамматикой */
    public function divide(): ?CellBee
    {
        if ($this->energy < 0.8) return null;
        if ($this->successes < 3) return null;
        
        $child = new CellBee($this->domain);
        $child->grammar = clone $this->grammar;
        $child->grammar->mutate();
        $child->energy = 0.5;
        
        $this->energy -= 0.3;
        $this->history[] = ['event' => 'divide', 'child' => $child->id];
        
        return $child;
    }
    
    public function isDead(): bool
    {
        return $this->energy < 0.05;
    }
    
    public function isReady(): bool
    {
        return $this->energy > 0.3;
    }
}
