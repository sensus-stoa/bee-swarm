# Story 03: Held-Out Validation (Criterion 1.1)

> HONEST_CRITERIA.md §1.1 — фундамент Stage 0

## Spec

1. train/test split: h = max(1, floor(n/5))
2. Поиск на train, приём: CV_T ≤ 0.01 И CV_H ≤ 0.10
3. Overfit rejection: CV_T ≤ 0.01 но CV_H > 0.10 → rejected
4. Retrospective: все существующие законы провалидировать, OVERFIT удалить
5. verify_0_1: count(OVERFIT) = 0 за 24h
6. [deferred] shuffle перед split

## Core

[x] red: test_heldout_split (4fff4c4)
[x] green: discoverHeldout() (1504d1d)
[x] overfit rejection (implicit in discoverHeldout)
[~] red: test_retrospective_check — все законы проходят held-out
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

- 2/4 work units done
- Next: `red: test_retrospective_check`
