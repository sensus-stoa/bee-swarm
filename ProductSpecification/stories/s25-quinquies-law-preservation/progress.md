# Story S2.5-квинкве: Law Preservation Across Generations

> Протокол §2.5-квинкве: закон, открытый в поколении N, должен воспроизводиться в поколении N+10.

## Spec

```
Gen 1-5: registry L_registry = все законы с CV_H≤0.05, complexity≥2
Gen 15: каждая пчела ищет законы из L_registry на исходных данных
Закон СОХРАНЁН если хоть одна пчела выдала CV≤ε_holdout
Закон ПОТЕРЯН если никто не выдал
Pass: count(ПОТЕРЯН) = 0
```

## Core

[ ] red: test_law_registry_captures_nontrivial — complexity≥2 попадают в реестр
[ ] red: test_law_preserved_after_10_generations — закон воспроизводится на Gen 15
[ ] red: test_lost_law_detected — потерянный закон → LOST в логе
[ ] green: LawRegistry::snapshot() + ::audit()
[ ] green: PreservationAudit в Hive::bootstrap() при gen≥15
[ ] refactor + lint

## Work Units

[ ] red: test_law_registry_captures_nontrivial
[ ] red: test_law_preserved_after_10_generations
[ ] red: test_lost_law_detected
[ ] green: LawRegistry class + wiring
[ ] green: PreservationAudit
[ ] tests pass + review

## Зависимость
- Требует ≥15 поколений → блокировано SPAWN_THRESHOLD fix

## Status
- Next: `red: test_law_registry_captures_nontrivial`
