# Story 04: Statistical Sufficiency (Criterion 1.2)

> HONEST_CRITERIA.md §1.2
> t ≥ t_min before claiming discovery. Protect against random CV→0 on small samples.

## Spec

1. `AtomRegistry::discover()` requires t ≥ t_min data points before returning results
2. t_min = max(10, n_feat * 5) — теоретически выведенный порог
3. При t < t_min: возвращает пустой массив + логирует причину `DATA`
4. discoverHeldout автоматически наследует проверку (split уменьшает и train и holdout)
5. verify_1_2 script: count(DATA) = 0 за 24h при реальной нагрузке

## Core

[~] red: test_insufficient_data — discover returns empty on small samples
    [ ] test written, RED confirmed
    [ ] review
    [ ] approve
[ ] green: sufficiency check in discover()
    [ ] implementation, full suite GREEN
    [ ] lint
    [ ] review
    [ ] approve

## Status
- Next: `red: test_insufficient_data`
