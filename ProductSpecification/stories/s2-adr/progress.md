# Story S2-ADR: ActiveDataRequester — Система запрашивает данные

> Протокол §3.4: система определяет какие данные максимально уменьшат неопределённость и формулирует конкретный запрос.

## Идея

Когда домен имеет frontier-задачи (CV ∈ [0.01, 0.10] — почти решён, но не до конца), система вычисляет где не хватает данных и запрашивает их.

```
Сейчас:   пассивно ждёт forager → тихо умирает на plateau
Stage 2:  REQUEST: domain=X, vary feature=Y from A to B, N points
          Current CV=0.07, expected improvement to CV<0.01
```

## Спецификация

```php
ActiveDataRequester::analyze(array $tasks, array $laws): array
// Вход:  все задачи домена + известные законы
// Выход: массив REQUEST с конкретными диапазонами
//
// Алгоритм:
// 1. Для каждой frontier-задачи (CV ∈ [0.01, 0.10]):
//    a. Вычислить coverage каждого признака: min, max, density
//    b. Найти признак с наихудшим покрытием
//    c. Сформулировать REQUEST: признак + диапазон + N точек
// 2. Для каждого REQUEST: ожидаемое улучшение CV
// 3. Сортировать по expected_improvement ↓
```

## Phases

### Phase 1: Feature coverage analysis
- [ ] RED: test_coverage_gap_detection — 20 точек в [0,10], gap в [10,20]
- [ ] GREEN: CoverageAnalyzer с min/max/density per feature

### Phase 2: Request generation
- [ ] RED: test_request_generation — frontier задача CV=0.07 → REQUEST с диапазоном
- [ ] GREEN: ActiveDataRequester::analyze() → массив REQUEST

### Phase 3: Retraction
- [ ] RED: test_retraction_when_data_does_not_help — предоставили данные, CV не улучшился → REQUEST_RETRACTED
- [ ] GREEN: логика retraction в Hive

## Метрики
- verify_2_4: ≥1 REQUEST, конкретный (домен + признак + диапазон), CV улучшился ИЛИ retracted

## Статус
⬜ Backlog — зависит от: S1-WIRE (нужна популяция), E1-FIX (нужны text-законы)
