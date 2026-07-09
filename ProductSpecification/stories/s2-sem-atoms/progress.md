# Story S2-SEM-ATOMS: Семантика → Grammar Atoms → Числовые Законы

> Обходной путь (анализ CUBE_CV0_SEMANTIC.md, решение P5-P6):
> Не пытаться применить CV→0 к семантике напрямую.
> Использовать семантику как ИСТОЧНИК НОВЫХ GRAMMAR ATOMS,
> которые затем участвуют в ЧИСЛОВОМ CV→0 поиске.

## Идея

```
Сейчас:     семантические факты → KG → confidence (тупик)
Обход:      семантические факты → PREDICATES как grammar atoms
            → пчела с атомом 'causes' в grammar
            → числовая задача: X=[causes_score], y=[target]
            → CV→0! (потому что target — число)
```

## Пример

```
Текст:       «Стресс вызывает усталость» → forager извлекает 'causes'
             «Кофеин вызывает бодрость»  → forager извлекает 'causes'
             
Forager:     candidate_predicates = ['causes', 'relates_to', 'near', ...]
             
Задача:      Для каждого candidate — бинарная классификация:
             X = [text_has_causes], y = [relation_is_causal]
             'causes' → accuracy=0.9
             'near'   → accuracy=0.5
             
Открытие:    'causes' — ЗНАЧИМЫЙ предикат → grammar atom
             
Пчела:       grammar = {+, ×, causes, lag}
             compose: causes(stress, fatigue) → числовой закон!
```

## Спецификация

```php
PredicateDiscoveryEngine::discover(array $semanticFacts): array
// Группирует факты по candidate predicate (глагол между субъектом и объектом)
// Для каждого predicate: бинарная задача — предсказывает ли predicate отношение?
// Log-loss < 0.3 → predicate → grammar atom
```

## Phases

### Phase 1: Candidate predicate extraction
- [ ] RED: test_extract_candidate_predicates — из «Стресс вызывает усталость» → ['вызывает']
- [ ] GREEN: Forager извлекает глаголы между известными концептами как candidate predicates

### Phase 2: Predicate significance testing
- [ ] RED: test_predicate_significance — 'causes' лучше случайного (log-loss)
- [ ] GREEN: PredicateDiscoveryEngine с log-loss оценкой

### Phase 3: Predicate → grammar atom
- [ ] RED: test_predicate_becomes_grammar_atom — 'causes' → grammar_ops
- [ ] GREEN: AtomRegistry::addDiscoveredPredicate()

### Phase 4: Bee использует predicate atom
- [ ] RED: test_bee_with_predicate_atom_finds_law — пчела с 'causes' находит закон
- [ ] GREEN: интеграция с Bee::spawn (predicate atoms в available ops)

## Преимущество перед прямым CV→0 на семантике
- Не требует непрерывного confidence
- Не требует 47+ фактов для stability law
- Использует существующий CV→0 без модификации
- Решает проблему «как пчеле получить НОВЫЕ grammar atoms» (S2.2 grammar ceiling break)

## Статус
⬜ Backlog — зависит от: E1-FIX (нужны text atoms), S1-WIRE (нужны пчёлы)
