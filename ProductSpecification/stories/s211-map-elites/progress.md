# Story S2.11: MAP-Elites — Quality-Diversity Grid

> Stage 2. Пчёлы в нишах вместо глобальной конкуренции.
> Каждая клетка grid'а — лучшая пчела для своего домена/сложности.

## Spec

```
Grid: complexity(bins=5) × CV(bins=10) = 50 клеток
Каждая клетка хранит ≤1 лучшую пчелу
Пчела выживает если она лучшая в СВОЕЙ клетке (не глобально)
Мутация и спавн — внутри ниши
Домены проявляются из overlap-кластеров (§3.9)

Требует: S1-WIRE (RoadRunner workers, 1 bee = 1 process)
```

## Core

[ ] red: test_grid_initialization — 50 клеток, пустые
[ ] red: test_niche_elite_survives — CV=0.05 в пустой клетке → выживает
[ ] red: test_niche_replacement — лучшая пчела заменяет худшую в той же клетке
[ ] red: test_cross_niche_no_competition — пчела из клетки (cplx=3, cv=0.05) не конкурирует с (cplx=1, cv=0.01)
[ ] green: MAPElitesGrid class
[ ] green: wiring in Hive — замена глобального отбора
[ ] refactor + lint + review

## Зависимости
- S1-WIRE (RoadRunner workers)
- S2.9 (Architect cluster emergence — overlap domains)

## Work Units

[ ] red: test_grid_initialization
[ ] red: test_niche_elite_survives
[ ] red: test_niche_replacement
[ ] red: test_cross_niche_no_competition
[ ] green: MAPElitesGrid
[ ] green: wiring
[ ] tests pass + review

## Status
- Сложность: ⭐⭐⭐⭐
- Зависит от: S1-WIRE, S2.9
- Next: `red: test_grid_initialization`
