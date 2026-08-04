# Story S2.7: Population Resilience

> Протокол §2.7: популяция должна пережить extinction+recovery цикл.

## Spec

```
Extinction: все пчёлы умерли (pop=0)
Recovery: SpawnManager восстанавливает популяцию до ≥3
  → через GAP_SPAWN или diversity-spawn
  → лог: EXTINCTION, RECOVERY
Pass: ≥1 extinction+recovery за 7 дней, без человеческого вмешательства
```

## Core

[ ] red: test_extinction_detected — pop=0 → EXTINCTION logged
[ ] red: test_recovery_without_human — pop восстанавливается через GAP_SPAWN
[ ] red: test_no_bootstrap_on_recovery — recovery ≠ seed bootstrap
[ ] green: ResilienceMonitor в Hive
[ ] green: GAP_SPAWN trigger при pop=0
[ ] refactor + lint

## Work Units

[ ] red: test_extinction_detected
[ ] red: test_recovery_without_human
[ ] red: test_no_bootstrap_on_recovery
[ ] green: ResilienceMonitor
[ ] green: GAP_SPAWN на вымирании
[ ] tests pass + review

## Зависимость
- Требует GAP_SPAWN (2.2 дополнение: spawn при E<15 на plateau+new_data)

## Status
- Next: `red: test_extinction_detected`
