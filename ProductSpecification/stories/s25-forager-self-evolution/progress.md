# Story S2.5: Forager Self-Evolution

> Stage 2. Forager не фиксирован — мутирует стратегии, учится на CV-выходе.
> Несколько фуражиров → специализация по источникам → пчёлы видят меньше мусора.

## Spec

```
Forager = агент без grammar, но с памятью:
  - Хранит мапу: источник → средний CV_выход
  - Мутирует: какие файлы/директории, какие стратегии, глубина извлечения
  - Цель: минимизировать ratio (tMin_fail / total_tasks)

Несколько фуражиров:
  - Каждый сканирует своё подмножество источников
  - Пчёлы выбирают у кого брать задачи (по историческому CV)
  - Специализация: один на metrics.jsonl, другой на .md, третий на .csv

Измеряет:
  - mean_CV с каждого источника
  - null_floor ≈ 0.09 для метрик — граница сигнал/шум
  - FPR источника = доля задач с CV > null_floor
```

## Core

[ ] red: test_forager_tracks_source_cv — источник → средний CV
[ ] red: test_forager_mutates_strategies — мутация набора стратегий
[ ] red: test_multi_forager_specialization — два фуражира → разные источники
[ ] red: test_bee_chooses_best_forager — пчела предпочитает фуражира с лучшим CV
[ ] green: ForagerMemory + ForagerMutator
[ ] green: MultiForager + Bee-Forager routing
[ ] refactor + lint + review

## Зависимости
- S1-WIRE (множество процессов)
- S2.9 (Architect — overlap domains)

## Work Units

[ ] red: test_forager_tracks_source_cv
[ ] red: test_forager_mutates_strategies
[ ] red: test_multi_forager_specialization
[ ] red: test_bee_chooses_best_forager
[ ] green: ForagerMemory + ForagerMutator
[ ] green: MultiForager + routing
[ ] tests pass + review

## Status
- Сложность: ⭐⭐⭐⭐
- Stage: 2
- Next: `red: test_forager_tracks_source_cv`
