# L3: §1.2 Sufficiency — calibrated tMin + INSUFFICIENT_DATA log

> Финальный аудит: упрощённый tMin, нет лога

## Fixes

1. **tMin depth-aware:** `max(8, nFeat * 4, n_depth * 3)` где n_depth = число compose-вложений
2. **INSUFFICIENT_DATA log:** `$this->log("INSUFFICIENT_DATA: {$task['name']} t={count($y)} < tMin={$tMin}")` в doDiscoverTick и doComposeTick

## Status

- [~] L3: Calibrated tMin + DATA log
