# Story D10: Forager Decomposition + Nesting Cleanup

> Forager.php: 608 строк, 4+ уровней вложенности. Жить нельзя.

## Phases

### Phase 1: Strategies ✅
- [x] RED — class not found
- [x] GREEN — bridge class + getStrategiesForExtraction()

### Phase 2: Accumulator → `scanWithAccumulator()` в отдельный класс ✅
- [x] RED — StreamingAccumulator class not found
- [x] GREEN — StreamingAccumulator extracted, Forager delegates
- [x] Review concerns fixed: single walk, deduplicated getComposedStrategies, fingerprint desync eliminated

### Phase 3: Semantics → `addSemanticFact()` в отдельный класс ✅
- [x] RED — SemanticFactInserter class not found
- [x] GREEN — SemanticFactInserter extracted, wired into Forager + StreamingAccumulator
- [x] Eliminated callable fragility

### Phase 4: Scanner → файловая итерация в отдельный класс ✅
- [x] RED — Scanner class not found
- [x] GREEN — scanDir() extracted, paths in result (single walk)
- [x] computeFingerprint() removed (dead code)
- [x] Paths collected AFTER skip-dir filter

### Phase 5: Flatten nesting + unify KG writes
- [ ] RED — ...
- [ ] GREEN — ...
- [ ] **Review finding #2:** Scanner пишет в KG напрямую (+0.25 boost), минуя SemanticFactInserter (+0.15 boost + валидация). Два пути с разными параметрами. Унифицировать — Scanner должен делегировать KG-записи в SemanticFactInserter.
- [ ] Nesting ≤2 уровня, методы ≤30 строк

## Status
🔧 Phase 5 — next session

## Метрики цели
- Forager.php: 608 → 310 (↓ 298, -49%)
- Nesting: 4 → ? (Phase 5)
- Методы >40: ? → ? (Phase 5)
