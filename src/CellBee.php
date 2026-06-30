<?php
declare(strict_types=1);
namespace BeeSwarm;

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
     * Поиск CV→0. С grammar этой пчелы.
     */
    public function search(array $X, array $y): array
    {
        // Используем существующий Search но с отношениями из grammar
        $g = new Grammar();
        $g->restrictTo($this->grammar->all());
        
        return Search::find($X, $y, $g, 2);
    }
    
    /**
     * Прожить один цикл: задача → поиск → результат → обновление.
     */
    public function live(array $X, array $y): array
    {
        [$ok, $cv, $formula] = $this->search($X, $y);
        
        if ($ok) {
            $this->energy = min(1.5, $this->energy + 0.15);
            $this->successes++;
        } else {
            $this->energy -= 0.1;
            $this->failures++;
            
            // ПРОВАЛ → МУТАЦИЯ
            if ($this->failures % 3 === 0) {
                $mutated = $this->grammar->mutate();
                if (!empty($mutated)) {
                    $this->history[] = ['event' => 'mutate', 'added' => $mutated, 'cv_before' => $cv];
                }
            }
        }
        
        return [
            'bee' => $this->id,
            'domain' => $this->domain,
            'ok' => $ok,
            'cv' => $cv,
            'formula' => $formula,
            'energy' => $this->energy,
            'grammar_size' => $this->grammar->count(),
            'successes' => $this->successes,
            'failures' => $this->failures,
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
