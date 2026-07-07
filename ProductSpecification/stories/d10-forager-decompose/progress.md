# Story D10: Forager Decomposition + Nesting Cleanup

> Forager.php: 608 строк, 4+ уровней вложенности. Жить нельзя.

## Phases

### Phase 1: Strategies ✅
- [x] RED — class not found
- [x] GREEN — bridge class + getStrategiesForExtraction()

### Phase 2: Accumulator → `scanWithAccumulator()` в отдельный класс ✅
- [x] RED — StreamingAccumulator class not found
- [x] GREEN — StreamingAccumulator extracted, Forager delegates
- [x] Review concerns fixed: single walk (paths via getPaths()), deduplicated getComposedStrategies(), fingerprint desync eliminated

### Phase 3: Semantics → `addSemanticFact()` в отдельный класс ✅
- [x] RED — SemanticFactInserter class not found
- [x] GREEN — SemanticFactInserter extracted, wired into Forager + StreamingAccumulator
- [x] Eliminated callable fragility (review finding #5)

### Phase 4: Scanner → файловая итерация в отдельный класс
- [ ] RED — ...
- [ ] GREEN — ...

### Phase 5: Flatten nesting → ≤2 уровня, ≤30 строк/метод
- [ ] ...

## Status
🔧 Phase 4 — next session

## Метрики цели
- Forager.php: 608 → 486 (↓ 122)
- Nesting: 4 → ? (Phase 5)
- Методы >40: ? → ? (Phase 5)
