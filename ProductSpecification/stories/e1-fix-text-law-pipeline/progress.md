# Story E1-FIX Phase 4: Forager Narrow Extraction

> Forager извлекает ВСЕ числовые колонки файла как одну задачу → tMin завышен → всё фильтруется.
> Нужно: разбивать на задачи с 2-3 колонками (пары/тройки признаков).

## Диагноз

```
metrics.jsonl: 6 колонок × 5 = tMin=30, данных t=19 → INSUFFICIENT
AirQualityUCI.csv: 249 колонок × 5 = tMin=1245, данных t=10 → INSUFFICIENT

Результат: 0 foraged-задач проходят tMin. 2983 passed = только GEN_ и base.
```

## Spec

**Forager должен извлекать из одного файла НЕСКОЛЬКО задач:**
- Каждая задача = 1 target + 1-2 features (пары/тройки соседних колонок)
- tMin для пары = 10 (2 features × 5), для тройки = 15
- 155 строк metrics.jsonl → t=155 >> tMin=10 → проходит

**Стратегия разбивки:**
- Для каждого числового столбца как target:
  - Создать задачу target + 1 соседний признак (min tMin)
  - Создать задачу target + 2 соседних признака
  - Не создавать target + ALL (текущее поведение — убрать)

**Альтернатива (если структура данных неизвестна):**
- Вычислить все столбцы → определить max_rows, max_cols
- Если max_cols > 5: создавать задачи-пары вместо одной широкой
- Если max_cols ≤ 5: текущее поведение ок

## Core

[ ] red: test_narrow_extraction_from_metrics — metrics.jsonl → задачи с t≥tMin
[ ] red: test_wide_csv_becomes_pairs — 249-колоночный CSV → пары, не одна широкая задача
[ ] red: test_small_file_unchanged — файл с 3 колонками → старое поведение
[ ] green: StreamingAccumulator narrow extraction mode
[ ] green: Forager config: max_cols_per_task=3
[ ] refactor + lint

## Work Units

[ ] red: test_narrow_extraction_from_metrics
[ ] red: test_wide_csv_becomes_pairs
[ ] red: test_small_file_unchanged
[ ] green: narrow extraction in StreamingAccumulator
[ ] green: Forager config
[ ] tests pass + review
[ ] deploy → metrics.jsonl задачи проходят tMin

## Status
- Next: `red: test_narrow_extraction_from_metrics`
