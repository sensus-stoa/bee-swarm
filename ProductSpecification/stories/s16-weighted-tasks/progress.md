# Story S1.6: Weighted Task Selection

> `array_rand` даёт всем задачам равные шансы. 5 базовых среди 900 → 0.5% за тик.
> Взвешенный отбор: nFeat=1 чаще nFeat=100.

## Spec

```
Вместо $tasks[array_rand($tasks)]:
  weight(task) = 1 / (nFeat + 1)
  P(task) = weight / Σ weights
  text/semantic задачи (без data) → weight = 0.01

Базовая пятёрка (nFeat=1, weight=0.5): 5×0.5 / (5×0.5 + 700×0.5 + 200×0.01) = 7% вместо 0.5%
→ 14× ускорение открытий
```

## Core

[ ] red: test_weighted_selection_prefers_narrow — nFeat=1 выбирается чаще nFeat=10
[ ] red: test_weighted_selection_includes_all — все задачи имеют ненулевую вероятность
[ ] green: WeightedTaskPicker class
[ ] green: wiring in Hive::doTick() — замена array_rand
[ ] refactor + lint + review

## Work Units

[ ] red: test_weighted_selection_prefers_narrow
[ ] red: test_weighted_selection_includes_all
[ ] green: WeightedTaskPicker
[ ] green: wiring
[ ] tests pass + review

## Status
- Next: `red: test_weighted_selection_prefers_narrow`
