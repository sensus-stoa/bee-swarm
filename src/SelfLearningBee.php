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
        // Iterative transitive closure: повторяем пока есть новые выводы
        $allFacts = array_merge($this->graph, $this->inferences);
        $changed = true;
        $maxIterations = 5;
        $iter = 0;
        
        while ($changed && $iter < $maxIterations) {
            $changed = false;
            $iter++;
            $newInferences = [];
            
            for ($i = 0; $i < count($allFacts); $i++) {
                for ($j = 0; $j < count($allFacts); $j++) {
                    if ($i === $j) continue;
                    $a = $allFacts[$i]; $b = $allFacts[$j];
                    
                    // Транзитивность: A→B→C → A→C
                    if ($a['p'] === $b['p'] && $a['o'] === $b['s']) {
                        $inferred = ['s'=>$a['s'],'p'=>$a['p'],'o'=>$b['o'],
                                    'conf'=>min($a['conf'],$b['conf'])*0.9,'from'=>[$a,$b]];
                        $exists = false;
                        foreach ($allFacts as $f) {
                            if ($f['s']===$inferred['s']&&$f['p']===$inferred['p']&&$f['o']===$inferred['o'])
                                {$exists=true;break;}
                        }
                        if (!$exists) {
                            $newInferences[] = $inferred;
                            $changed = true;
                        }
                    }
                    
                    // is_a + can → can (свойства наследуются через классификацию)
                    if ($a['p']==='is_a' && $b['s']===$a['o'] && $b['p']==='can') {
                        $inferred = ['s'=>$a['s'],'p'=>'can','o'=>$b['o'],
                                    'conf'=>min($a['conf'],$b['conf'])*0.8,'from'=>[$a,$b]];
                        $exists = false;
                        foreach ($allFacts as $f) {
                            if ($f['s']===$inferred['s']&&$f['p']===$inferred['p']&&$f['o']===$inferred['o'])
                                {$exists=true;break;}
                        }
                        if (!$exists) {
                            $newInferences[] = $inferred;
                            $changed = true;
                        }
                    }
                    // has+can → can
                    if ($a['p']==='has' && $b['s']===$a['o'] && $b['p']==='can') {
                        $inferred = ['s'=>$a['s'],'p'=>'can','o'=>$b['o'],
                                    'conf'=>min($a['conf'],$b['conf'])*0.7,'from'=>[$a,$b]];
                        $exists = false;
                        foreach ($allFacts as $f) {
                            if ($f['s']===$inferred['s']&&$f['p']===$inferred['p']&&$f['o']===$inferred['o'])
                                {$exists=true;break;}
                        }
                        if (!$exists) {
                            $newInferences[] = $inferred;
                            $changed = true;
                        }
                    }
                }
            }
            
            $this->inferences = array_merge($this->inferences, $newInferences);
            $allFacts = array_merge($allFacts, $newInferences);
            
            // 💾 Сохраняем выводы в SQLite на каждой итерации
            foreach ($newInferences as $inf) {
                $this->saveFact($inf['s'], $inf['p'], $inf['o'], $inf['conf'], true);
            }
        }
    }
    
    private function checkContradiction(string $s, string $p, string $o): bool
    {
        // Только is_a может быть противоречивым.
        // can, has, построил, ценит — могут иметь много объектов.
        if ($p !== 'is_a') return false;
        
        foreach ($this->graph as $fact) {
            if ($fact['s'] === $s && $fact['p'] === $p && $fact['o'] !== $o) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * CV→0 в графе знаний: насколько когерентен граф.
     * CV = доля противоречий среди всех фактов.
     * CV=0 — граф идеально когерентен. CV>0.3 — есть проблемы.
     */
    public function knowledgeCV(): array
    {
        $contradictions = 0;
        $total = count($this->graph);
        if ($total < 2) return ['cv' => 0, 'contradictions' => 0, 'total' => $total];
        
        $checked = [];
        foreach ($this->graph as $a) {
            foreach ($this->graph as $b) {
                if ($a === $b) continue;
                $key = $a['s'].'|'.$a['p'].'|'.$b['s'].'|'.$b['p'];
                if (isset($checked[$key])) continue;
                $checked[$key] = true;
                
                if ($a['s'] === $b['s'] && $a['p'] === $b['p'] && $a['o'] !== $b['o']) {
                    $contradictions++;
                }
            }
        }
        
        $cv = $total > 0 ? $contradictions / $total : 0;
        
        return [
            'cv' => round($cv, 3),
            'contradictions' => $contradictions,
            'total_facts' => $total,
            'coherent' => $cv < 0.1,
            'status' => $cv < 0.05 ? 'когерентен' : ($cv < 0.2 ? 'есть вопросы' : 'противоречив'),
        ];
    }
    
    /**
     * Учит факт с проверкой на противоречия.
     * Если противоречит → понижает confidence, логирует.
     */
    public function learnFactWithValidation(string $s, string $p, string $o, float $confidence = 1.0): array
    {
        $contradiction = $this->checkContradiction($s, $p, $o);
        
        if ($contradiction) {
            // Факт противоречит графу — понижаем confidence, но НЕ отвергаем
            $confidence *= 0.3;
            $this->learnFact($s, $p, $o, $confidence);
            return [
                'status' => 'contradiction',
                'confidence_reduced' => $confidence,
                'message' => "{$s} {$p} {$o} — противоречит существующим знаниям. Confidence снижена до {$confidence}.",
            ];
        }
        
        // CV до и после
        $cvBefore = $this->knowledgeCV()['cv'];
        $this->learnFact($s, $p, $o, $confidence);
        $cvAfter = $this->knowledgeCV()['cv'];
        
        return [
            'status' => $cvAfter > $cvBefore ? 'needs_review' : 'learned',
            'cv_before' => $cvBefore,
            'cv_after' => $cvAfter,
            'message' => $cvAfter > $cvBefore 
                ? "CV графа выросло с {$cvBefore} до {$cvAfter}. Факт требует проверки."
                : "Факт усвоен. CV графа стабильно ({$cvAfter}).",
        ];
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
        
        // НОРМАЛИЗАЦИЯ ГЛАГОЛОВ: множественное → единственное
        $verbMap = [
            'помогают'=>'помогает', 'делают'=>'делает', 'могут'=>'может',
            'ценят'=>'ценит', 'практикуют'=>'практикует', 'строят'=>'строит',
            'выбирают'=>'выбрал', 'учат'=>'учил',
        ];
        $words = explode(' ', $s);
        foreach ($words as &$w) {
            $lower = mb_strtolower($w);
            if (isset($verbMap[$lower])) $w = $verbMap[$lower];
        }
        $s = implode(' ', $words);
        
        // «X — это Y» или «X — Y» или «X это Y»
        if (preg_match('/^(.+?)\s*(?:—\s*(?:это\s*)?|–|—|это\s+)(.+)$/u', $s, $m)) {
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
        
        // «X может/умеет/делает/построил/ценит/практикует/помогает/помогают Y»
        if (preg_match('/^(.+?)\s+(?:может|умеет|измеряет|делает|построил|ценит|практикует|помогает|помогают|выбрал|учил)\s+(.+)$/u', $s, $m)) {
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
