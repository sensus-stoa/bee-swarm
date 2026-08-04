# Story E1-FIX: Text Law Pipeline

> Полный путь: .md файлы → forager → cross-pair → законы.

## Phase 1-3: Cross-pair + Pipeline ✅
- [x] TextAtomCrossPairer
- [x] TaskGenerator crossPairTasks
- [x] StreamingAccumulator text atom extraction

## Phase 4: Forager Narrow Extraction ✅
> Forager извлекал ВСЕ колонки как одну задачу → tMin завышен.
> Fix: разбивка на окна по 3 колонки.

- [x] red: test_narrow_extraction
- [x] green: sliding window split
- [x] 506/506 PASS, review CONCERNS accepted
- [x] Journal-only: 697 задач, все PASS tMin

## Phase 4b: Task Priority by Column Count ⬜
> Узкие задачи (пары/тройки) должны быть в начале пула — выше шанс открытия.
> Широкие — в конце.

**Spec:**
```
filterInsufficient() → usort по nFeat ASC
nFeat = count(data[0]) - 1
text/semantic → в конец (nFeat=999)
```

[ ] red: test_tasks_sorted_by_column_count
[ ] green: usort by nFeat ASC
[ ] refactor + lint + review
[ ] deploy

## Status
🔧 Phase 4b — `red: test_tasks_sorted_by_column_count`
