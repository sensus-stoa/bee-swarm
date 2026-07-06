# Story 06: Non-Triviality (Criterion 1.4)

> HONEST_CRITERIA.md §1.4
> Reject identity, constant, and trivial algebraic forms

## Spec

1. Reject: `e(x) = x_i` (identity), `e(x) = c` (constant)
2. Reject: `+(x,0)`, `×(x,1)`, `−(x,0)`, `/(x,1)` (algebraic identity)
3. Проверка в `AtomProvider::discover()` после вычисления CV
4. `isTrivial(atom, X, y) → bool`

## Core

[~] red: test_trivial_rejected — identity/constant not accepted
    [ ] test written, RED confirmed
    [ ] review
    [ ] approve
[ ] green: isTrivial() filter in discover()
    [ ] implementation, full suite GREEN
    [ ] lint
    [ ] review
    [ ] approve

## Status
- Next: `red: test_trivial_rejected`
