# Story 07: Parsimony (Criterion 1.3)

> HONEST_CRITERIA.md §1.3
> Из нескольких законов с CV_H ≤ ε_holdout — выбрать простейший

## Spec

1. Для одной задачи: если несколько атомов проходят held-out → выбрать с минимальной complexity
2. `complexity(e)` = число узлов в expression tree (proxy: 1 для простых, strlen/3 для compose)
3. Проверка в `LawValidator::validate()` — после held-out фильтра
4. `selectSimplest(candidates) → candidate`

## Core

[~] red: test_parsimony_selects_simplest — из двух выбирается простейший
    [ ] test written, RED confirmed
    [ ] review
    [ ] approve
[ ] green: selectSimplest() in LawValidator
    [ ] implementation, full suite GREEN
    [ ] lint
    [ ] review
    [ ] approve

## Status
- Next: `red: test_parsimony_selects_simplest`
