# V0.8 — Overlap Tracking (§1.8)

## Статус: ⬜ не реализован

Единственный неверифицированный критерий Стадии 0.

## Что требует протокол

Когда задача назначена пчеле A, не решена за K=100 тактов, и переназначена пчеле B:
→ записать в `overlap_log`: (bee_a, bee_b, task, answer_a, answer_b, matched).

Накопить ≥10 shared_tasks для хотя бы одной пары пчёл → пара «измерена».

## Что уже есть

- TaskRouter: таймаут K=100, переназначение задач
- Hive::doTick: ROUTE log с назначением пчелы
- Таблица `overlap_log` в БД (пустая)

## Что нужно сделать

### Phase 1: OverlapTracker class (~1h)
- Новый класс `OverlapTracker` с методом `record(task, beeFrom, beeTo, answerFrom, answerTo)`
- Сравнивает ответы (пока: строгое равенство формул)
- Пишет в `overlap_log`

### Phase 2: Wire into Hive (~1.5h)
- При переназначении: сохранить старую пчелу + её ответ (если был)
- Когда новая пчела находит/не находит: записать overlap
- Если старая пчела не нашла ответа: answer = null, matched = 0

### Phase 3: verify_0_8 production (~0.5h)
- Прогнать улей на ноутбуке
- Дождаться overlap-записей
- `verify_0_8.php` → PASS

### Phase 4: Algebraic reduction in answer comparison (1h)
- Сейчас: строгое `===` сравнение формул
- Протокол §1.8: «идентично после алгебраической редукции»
- `x0+0` ≡ `x0`, `x0+x1` ≡ `x1+x0` (коммутативность)
- Добавить `reduceFormula()` с правилами из `isTrivial` + коммутативность для `+, ×, min, max`
- Тест: `testAnswersMatchAfterAlgebraicReduction`

## Сложность: ⭐⭐ | 4h
