# Story D10: Forager Decomposition + Nesting Cleanup

> Forager.php: 659 строк, 11 методов >40. Глубокая вложенность if/foreach/else.

## Spec

### Phase 1: Decompose (5 files ≤200 lines)

| New file | Lines | Responsibility |
|----------|-------|----------------|
| `src/Forager/Forager.php` | ~150 | Orchestrator, scan() |
| `src/Forager/Scanner.php` | ~100 | File iteration, content reading |
| `src/Forager/Accumulator.php` | ~120 | SQLite-backed scanWithAccumulator |
| `src/Forager/Strategies.php` | ~150 | Strategy loading + composition |
| `src/Forager/Semantics.php` | ~80 | addSemanticFact, KG insert |

### Phase 2: Flatten nesting (≤30 lines/method, no else)

**Правило: `else` — индикатор сложности. Заменять на early return/continue.**

```php
// ❌ Deep nesting with else
if ($x) {
    foreach ($arr as $item) {
        if ($item['ok']) {
            process($item);
        } else {
            logSkip($item);
        }
    }
} else {
    handleEmpty();
}

// ✅ Flat with early returns
if (! $x) { handleEmpty(); return; }
foreach ($arr as $item) {
    if (! $item['ok']) { logSkip($item); continue; }
    process($item);
}
```

**Метрики:**
- Каждый метод ≤30 строк
- `else` только для симметричных веток (true/false одинакового веса)
- Вложенность ≤2 уровня (method → if/foreach → тело)
- `scanWithAccumulator` (120 строк) → 4 метода по ≤30 строк

## Status
⬜ Backlog — после E1, до Stage 1
