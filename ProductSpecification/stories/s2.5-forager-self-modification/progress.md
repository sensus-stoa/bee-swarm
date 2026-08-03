# S2.5 — Forager Self-Modification (Extraction Ceiling)

## Статус: ⬜ Stage 3 backlog

## Проблема

Seed-grammar определяет не только набор операций (grammar ceiling, §2.5-тер),
но и **онтологию** — способ членения мира. Forager извлекает только то, на что
он запрограммирован: пары концептов с глаголом, TF-IDF top-N, окно ±3.

Это аристотелевская онтология (объект-предикат). Процессуальная онтология
(цепочки событий), мереология (часть-целое), blending — недоступны без
модификации extraction-примитивов.

## Что нужно

1. **Forager parameter space**: окно (±3, ±5, ±10), тип предиката (VERB, NOUN, ADJ),
   порог TF-IDF, domain extraction rules
2. **Mutation**: пчела может изменить extraction-примитивы при spawn
3. **Validation**: новый extraction-примитив → новый тип кандидатов → CV→0
4. **Criterion 2.5-тер-extraction**: на поколении 100 ≥1 extraction-примитив
   модифицирован и дал новый тип кандидатов

## Фазы (предварительно)

### Phase 1: Parameterisable Forager (3h)
- Extraction params как evolvable свойства пчелы
- Window size, predicate type, domain rules

### Phase 2: Mutation mechanics (2h)
- При spawn: ±20% мутация extraction-параметров
- Cross-over: ребёнок наследует параметры родителя

### Phase 3: Validation pipeline (2h)
- Новый extraction-примитив → forager scan → новые кандидаты
- CV→0 валидация новых типов кандидатов

### Phase 4: verify_1_5c_extraction script (1h)

## Сложность: ⭐⭐⭐⭐ | 8h+
