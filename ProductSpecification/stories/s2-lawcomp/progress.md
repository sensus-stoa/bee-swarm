# Story S2-LAWCOMP: LawCompressor — Structural Isomorphism → Meta-Laws

> Протокол §3.8: система создаёт внутренние имена для сжатых законов, и другие пчёлы используют их как атомы grammar.
> «Compilation beats coalition» — held-out CV мета-закона СТРОГО МЕНЬШЕ среднего CV исходных законов.

## Идея

Когда законы из разных доменов имеют одинаковую СТРУКТУРУ expression tree (с точностью до констант), LawCompressor создаёт мета-закон:

```
Домен arithmetic: y = +(x₀, x₁)           expression tree: add(x0, x1)
Домен physics:    F = +(m, a)              expression tree: add(x0, x1)  ← изоморфно!
Домен economics:  cost = +(fixed, variable) expression tree: add(x0, x1)  ← изоморфно!

→ LawCompressor: meta-law "linear_combination" = template: +(■, ■)
→ Имя "linear_combination" → атом grammar
→ Другая пчела в домене biology: compose(linear_combination, K2) → новый закон!
```

## Спецификация

```php
LawCompressor::compress(array $laws): array
// Вход:  массив законов [{formula, domain, cv}]
// Выход: массив мета-законов [{name, template, source_laws, cv_compiled}]
// 
// Алгоритм:
// 1. Для каждого закона: нормализовать expression tree (заменить константы на placeholder)
// 2. Сгруппировать по нормализованному дереву
// 3. Если группа ≥ 2: создать мета-закон
// 4. Имя = хеш нормализованного дерева (первые 8 символов) или semantic label
// 5. CV_compiled = held-out CV мета-закона на данных ВСЕХ исходных доменов
// 6. Gate: CV_compiled < mean(CV исходных законов) → PASS
```

## Phases

### Phase 1: Expression tree normalisation
- [ ] RED: test_normalize_replaces_constants — +(x₀, 5) → +(x₀, ■)
- [ ] GREEN: ExpressionNormalizer::normalize(string $formula): string

### Phase 2: Isomorphism detection
- [ ] RED: test_detect_isomorphic_laws — 3 закона add(x0,x1) в разных доменах → 1 мета-закон
- [ ] GREEN: LawCompressor::compress() с группировкой

### Phase 3: Grammar injection
- [ ] RED: test_meta_law_becomes_grammar_atom — имя мета-закона → grammar_ops
- [ ] GREEN: LawCompressor → AtomRegistry::addMetaLaw()

### Phase 4: Cross-domain usage
- [ ] RED: test_bee_uses_meta_law_in_new_domain — пчела использует atom "linear_combination" в biology
- [ ] GREEN: интеграция с Bee::spawn (meta-law atoms в available)

## Метрики
- verify_2_8: ≥1 meta-law, использован как grammar atom в другом домене, CV_compiled < mean(CV_исходных)

## Статус
⬜ Backlog — R&D, зависит от: S1-WIRE (нужна популяция), E1-FIX (нужны text-законы), D11 (нужен grammar cap)
