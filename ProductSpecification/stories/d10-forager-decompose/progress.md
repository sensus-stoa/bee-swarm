# Story D10: Forager Decomposition + Nesting Cleanup

> Forager.php: 608 строк, 4+ уровней вложенности. Жить нельзя.

## Phases

### Phase 1: Strategies ✅
- [x] RED — class not found
- [x] GREEN — bridge class + getStrategiesForExtraction()
- [ ] Phase 2: Accumulator → `scanWithAccumulator()` в отдельный класс
- [ ] Phase 3: Semantics → `addSemanticFact()` в отдельный класс
- [ ] Phase 4: Scanner → файловая итерация в отдельный класс
- [ ] Phase 5: Flatten nesting → ≤2 уровня, ≤30 строк/метод

## Status
🔧 Phase 2 — next session

## Метрики цели
- Forager.php: 608 → ≤150
- Nesting: 4 → ≤2
- Методы >40: 11 → 0
