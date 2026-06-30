<?php
declare(strict_types=1);
namespace BeeSwarm;

/**
 * SelfLearningBee: самообучающаяся пчела.
 * Получает простые факты → строит граф знаний → выводит новые отношения.
 * 
 * Принцип: семантика = отношения. CV→0 в графе = истина.
 * «Машина — двигающийся объект» → (машина, is_a, двигающийся_объект)
 * «Компьютер — такое устройство» → (компьютер, is_a, устройство)
 */
class SelfLearningBee
{
    private Ontology $ontology;
    private array $graph = [];
    private array $inferences = [];
    private int $factsLearned = 0;
    private bool $loaded = false;
    
    public function getOntology(): Ontology { return $this->ontology; }
    
    public function __construct()
    {
        $this->ontology = new Ontology();
        $this->initDb();
        $this->loadFromDb();
        if (!$this->loaded) {
            $this->seedBasicKnowledge();
        }
    }
    
    private function initDb(): void
    {
        $db = Database::get();
        $db->exec("CREATE TABLE IF NOT EXISTS knowledge_graph (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subject TEXT NOT NULL,
            predicate TEXT NOT NULL,
            object TEXT NOT NULL,
            confidence REAL DEFAULT 1.0,
            inferred INTEGER DEFAULT 0,
            created_at TEXT DEFAULT (datetime('now')),
            UNIQUE(subject, predicate, object)
        )");
    }
    
    private function loadFromDb(): void
    {
        $db = Database::get();
        $rows = $db->query("SELECT subject, predicate, object, confidence, inferred FROM knowledge_graph")->fetchAll();
        if (count($rows) > 0) {
            foreach ($rows as $row) {
                $fact = ['s' => $row['subject'], 'p' => $row['predicate'], 
                         'o' => $row['object'], 'conf' => (float)$row['confidence']];
                if ($row['inferred']) {
                    $this->inferences[] = $fact;
                } else {
                    $this->graph[] = $fact;
                }
            }
            $this->factsLearned = count(array_filter($rows, fn($r) => !$r['inferred']));
            $this->loaded = true;
        }
    }
    
    private function saveFact(string $s, string $p, string $o, float $conf, bool $inferred = false): void
    {
        $db = Database::get();
        $db->prepare("INSERT OR IGNORE INTO knowledge_graph (subject, predicate, object, confidence, inferred) VALUES (?,?,?,?,?)")
           ->execute([$s, $p, $o, $conf, $inferred ? 1 : 0]);
    }
    
    /** Базовые знания — как ребёнок учит мир */
    private function seedBasicKnowledge(): void
    {
        $this->learnFact('объект', 'is_a', 'вещь');
        $this->learnFact('живой_объект', 'is_a', 'объект');
        $this->learnFact('человек', 'is_a', 'живой_объект');
        $this->learnFact('животное', 'is_a', 'живой_объект');
        $this->learnFact('машина', 'is_a', 'объект');
        $this->learnFact('компьютер', 'is_a', 'машина');
        $this->learnFact('двигатель', 'is_a', 'машина');
        
        $this->learnFact('человек', 'has', 'разум');
        $this->learnFact('человек', 'can', 'думать');
        $this->learnFact('машина', 'can', 'двигаться');
        $this->learnFact('компьютер', 'can', 'вычислять');
        $this->learnFact('пчела', 'can', 'искать_законы');
        $this->learnFact('пчела', 'has', 'энергия');
        
        // Связь с онтологией роя
        $this->learnFact('рой', 'contains', 'пчела');
        $this->learnFact('закон', 'measured_by', 'CV');
        $this->learnFact('CV', 'means', 'сжатие');
    }
    
    /**
     * Выучить новый факт. Предложение на русском → отношение.
     * «Сократ — человек» → (сократ, is_a, человек)
     * «Машина двигается» → (машина, can, двигаться)
     * «У пчелы есть энергия» → (пчела, has, энергия)
     */
    public function learnFact(string $subject, string $predicate, string $object, float $confidence = 1.0): void
    {
        $s = $this->ontology->resolve($subject);
        $p = $this->ontology->resolve($predicate);
        $o = $this->ontology->resolve($object);
        
        // Проверка на противоречия (CV>0 в графе)
        $contradiction = $this->checkContradiction($s, $p, $o);
        if ($contradiction) {
            // Не добавляем — противоречие
            return;
        }
        
        // Добавляем факт
        $this->graph[] = ['s' => $s, 'p' => $p, 'o' => $o, 'conf' => $confidence];
        $this->saveFact($s, $p, $o, $confidence, false);
        AutoGit::factLearned($s, $p, $o);
        $this->factsLearned++;
        
        // Выводим новые отношения через транзитивность
        $this->infer();
    }
    
    /**
     * Транзитивный вывод: если A→B и B→C, то A→C.
     * Это и есть «понимание»: цепочка простых фактов → сложный вывод.
     */
    private function infer(): void
    {
        $newInferences = [];
        
        // Транзитивность is_a: компьютер is_a машина + машина is_a объект → компьютер is_a объект
        for ($i = 0; $i < count($this->graph); $i++) {
            for ($j = 0; $j < count($this->graph); $j++) {
                if ($i === $j) continue;
                
                $a = $this->graph[$i];
                $b = $this->graph[$j];
                
                // A → B → C: если A.pred = B.pred и A.obj = B.subj
                if ($a['p'] === $b['p'] && $a['o'] === $b['s']) {
                    $inferred = ['s' => $a['s'], 'p' => $a['p'], 'o' => $b['o'],
                                'conf' => min($a['conf'], $b['conf']) * 0.9,
                                'from' => [$a, $b]];
                    
                    // Проверяем что такого вывода ещё нет
                    $exists = false;
                    foreach ($this->graph as $existing) {
                        if ($existing['s'] === $inferred['s'] && 
                            $existing['p'] === $inferred['p'] && 
                            $existing['o'] === $inferred['o']) {
                            $exists = true; break;
                        }
                    }
                    foreach ($this->inferences as $existing) {
                        if ($existing['s'] === $inferred['s'] && 
                            $existing['p'] === $inferred['p'] && 
                            $existing['o'] === $inferred['o']) {
                            $exists = true; break;
                        }
                    }
                    
                    if (!$exists) {
                        $newInferences[] = $inferred;
                    }
                }
                
                // A has B + B can C → A can C через B
                if ($a['p'] === 'has' && $b['s'] === $a['o'] && $b['p'] === 'can') {
                    $inferred = ['s' => $a['s'], 'p' => 'can', 'o' => $b['o'],
                                'conf' => min($a['conf'], $b['conf']) * 0.7,
                                'from' => [$a, $b]];
                    $exists = false;
                    foreach (array_merge($this->graph, $this->inferences) as $e) {
                        if ($e['s'] === $inferred['s'] && $e['p'] === $inferred['p'] && $e['o'] === $inferred['o']) {
                            $exists = true; break;
                        }
                    }
                    if (!$exists) $newInferences[] = $inferred;
                }
            }
        }
        
        $this->inferences = array_merge($this->inferences, $newInferences);
        
        // 💾 Сохраняем выводы в SQLite
        foreach ($newInferences as $inf) {
            $this->saveFact($inf['s'], $inf['p'], $inf['o'], $inf['conf'], true);
        }
    }
    
    private function checkContradiction(string $s, string $p, string $o): bool
    {
        foreach ($this->graph as $fact) {
            // Противоречие: уже есть факт с теми же s,p но другим o
            if ($fact['s'] === $s && $fact['p'] === $p && $fact['o'] !== $o) {
                return true; // CV>0 в графе!
            }
        }
        return false;
    }
    
    /**
     * Ответить на вопрос: что пчела ЗНАЕТ о концепте?
     * Возвращает все известные и выведенные отношения.
     */
    public function query(string $concept): array
    {
        $c = $this->ontology->resolve($concept);
        $known = [];
        $inferred = [];
        
        foreach ($this->graph as $fact) {
            if ($fact['s'] === $c) $known[] = $fact;
        }
        foreach ($this->inferences as $inf) {
            if ($inf['s'] === $c) $inferred[] = $inf;
        }
        
        return [
            'concept' => $c,
            'facts_known' => $known,
            'facts_inferred' => $inferred,
            'total_facts' => $this->factsLearned,
            'total_inferences' => count($this->inferences),
        ];
    }
    
    /**
     * Обучить из простого русского предложения.
     * Поддерживает форматы:
     * - «X — это Y» → (X, is_a, Y)
     * - «X имеет Y» → (X, has, Y)  
     * - «X может Y» → (X, can, Y)
     * - «X Y» (простое) → пытается угадать отношение
     */
    public function learnFromRussian(string $sentence): array
    {
        $s = trim($sentence);
        
        // «X — это Y» или «X — Y»
        if (preg_match('/^(.+?)\s*(?:—|–|—|это)\s*(.+)$/u', $s, $m)) {
            $subj = trim($m[1]);
            $obj = trim($m[2]);
            $pred = 'is_a';
            $this->learnFact($subj, $pred, $obj);
            return ['parsed' => [$subj, $pred, $obj], 'status' => 'learned'];
        }
        
        // «X имеет Y» / «у X есть Y»
        if (preg_match('/^(?:у\s+)?(.+?)\s+(?:имеет|есть)\s+(.+)$/u', $s, $m)) {
            $subj = trim($m[1]);
            $obj = trim($m[2]);
            $pred = 'has';
            $this->learnFact($subj, $pred, $obj);
            return ['parsed' => [$subj, $pred, $obj], 'status' => 'learned'];
        }
        
        // «X может Y» / «X умеет Y»
        if (preg_match('/^(.+?)\s+(?:может|умеет)\s+(.+)$/u', $s, $m)) {
            $subj = trim($m[1]);
            $obj = trim($m[2]);
            $pred = 'can';
            $this->learnFact($subj, $pred, $obj);
            return ['parsed' => [$subj, $pred, $obj], 'status' => 'learned'];
        }
        
        return ['parsed' => null, 'status' => 'unrecognized', 'sentence' => $s];
    }
    
    public function stats(): array
    {
        return [
            'facts_learned' => $this->factsLearned,
            'inferences_made' => count($this->inferences),
            'graph_size' => count($this->graph),
        ];
    }
}
