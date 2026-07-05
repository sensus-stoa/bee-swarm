# Story 03: Held-Out Validation (Criterion 1.1)

> HONEST_CRITERIA.md §1.1 — фундамент Stage 0
> Без этого: compose explosion (86% законов — мусор), нет контроля false positives

## Spec

1. train/test split: h = max(1, floor(n/5)), остальные t = n-h для обучения
2. Поиск ТОЛЬКО на тренировочных данных T
3. Приём: CV_T ≤ 0.01 И CV_H ≤ 0.10
4. Overfit rejection: CV_T ≤ 0.01 но CV_H > 0.10 → OVERFIT в лог, закон НЕ принимается
5. Retrospective: все существующие законы провалидировать, OVERFIT удалить из БД
6. verify_0_1 script: count(OVERFIT) = 0 за 24h

## Core

[~] red: test_heldout_split — AtomRegistry::discover с train/test split
    [ ] test written, RED confirmed
    [ ] review
    [ ] approve
[ ] green: heldout split in discover()
    [ ] implementation done, full suite GREEN
    [ ] lint
    [ ] review
    [ ] approve
[ ] red: test_overfit_rejection — CV_T≤0.01, CV_H>0.10 → rejected
    [ ] test written, RED confirmed
    [ ] review
    [ ] approve
[ ] green: overfit rejection logic
    [ ] implementation done, full suite GREEN
    [ ] lint
    [ ] review
    [ ] approve
[ ] red: test_retrospective_check — все существующие законы проходят held-out
    [ ] test written, RED confirmed
    [ ] review
    [ ] approve
[ ] green: retrospective validation + OVERFIT cleanup
    [ ] implementation done, full suite GREEN
    [ ] lint
    [ ] review
    [ ] approve
[ ] refactor
[ ] verify: 24h without OVERFIT

## Status

- Next: `red: test_heldout_split`
