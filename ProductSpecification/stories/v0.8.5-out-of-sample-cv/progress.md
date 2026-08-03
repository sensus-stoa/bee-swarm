# V0.8.5 — Law Classification Gate

## Статус: ⬜

## Проблема

Search::find находит два класса выражений:
- **IDENTITY**: `R×x × x/R×x = x` — истинно для любых данных, CV=0 всегда
- **EMPIRICAL**: `max(hp,wt)/(hp+wt)²` — зависит от данных, CV>0

Identity — не баг. Это структурное свойство грамматики. Система
доказала что `×` и `/` взаимно-обратны. Но Identity ≠ открытие о мире.

## Что нужно

1. **Classify**: split данных train/test → CV_train vs CV_test
2. **IDENTITY gate**: CV_train ≈ 0 AND CV_test > 0.5 → IDENTITY
3. **EMPIRICAL gate**: CV_train ≤ ε AND CV_test ≤ ε × 1.5 → EMPIRICAL
4. **Маркировка**: законы в БД получают поле `law_class: IDENTITY|EMPIRICAL`
5. **Отчёт**: IDENTITY-законы не скрываются, показываются отдельно

## Фазы

### Phase 1: Split + dual CV в Search::find (1h)
- `Search::find` получает `$testRatio = 0.2`
- Возвращает `[$found, $cv_train, $cv_test, $formula]`

### Phase 2: Classification gate (0.5h)
- `DiscoveryEngine::discover()` классифицирует каждый результат
- IDENTITY: маркировать, не отбрасывать
- EMPIRICAL: обычный путь

### Phase 3: law_class в БД (0.5h)
- Миграция: `ALTER TABLE laws ADD COLUMN law_class TEXT DEFAULT 'EMPIRICAL'`
- RecordKeeper сохраняет класс

### Phase 4: Интеграционные тесты (0.5h)
- Auto MPG: первый закон — IDENTITY, второй — EMPIRICAL
- Wine: аналогично
- GrammarFromOps: regression остаётся

## Сложность: ⭐⭐ | 2.5h
