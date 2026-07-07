# Story D11: Search Performance — Grammar Outgrowing Combinatorial Search

> Search::find depth=3: 551 ops → 8B комбинаций → 11 секунд. При 192 ops было <5s.
> Демон открывает законы → grammar растёт → search экспоненциально замедляется.

## Root Cause

`Search::find` — полный перебор O(features² × ops^depth):
- L1: 7² × 551 = 27K выражений
- L2: 27K × 551 = 15M
- L3: 15M × 551 = 8B

При depth=3 каждая новая операция в grammar умножает пространство поиска на 551.

## Phases

### Phase 1: Grammar cap for search
- Top-N ops по частоте использования в известных законах
- Или: только ops, реально встречающиеся в formulas с CV < 0.1
- Цель: сократить grammar для search до ~100 ops без потери качества

### Phase 2: Pruning на ранних depth
- CV > 1.0 на depth=1 → не раскрывать на depth=2
- Монотонность: если выражение плохое на depth=k, композиция на depth=k+1 не станет лучше

### Phase 3: Benchmark + time bound
- DaemonEfficiencyTest: bound должен зависеть от grammar size
- formula: `max_time = ops_count * 0.02 * depth` или калибровать dynamically

## Status
🔧 Backlog

## Метрики цели
- Search::find depth=3: ≤5s при 500+ ops
- Или: ≤3s при capped grammar (100 ops)
- DaemonEfficiencyTest: bound адаптивный
