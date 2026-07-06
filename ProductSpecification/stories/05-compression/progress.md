# Story 05: Compression Superiority (Criterion 1.7)

> HONEST_CRITERIA.md §1.7
> Закон должен сжимать данные лучше чем baseline y = mean(y_train)

## Spec

1. `MDL(e) = complexity(e) × log(n) + n × log(CV(e·2))` — approximate MDL
2. `MDL(baseline) = 1 × log(n) + n × log(CV(mean))` — constant prediction
3. Закон принимается только если `MDL(e) < MDL(baseline)`
4. Применяется в `discover()` и `discoverHeldout()`
5. Удаляет константы K_c которые не сжимают лучше чем mean

## Core

[~] red: test_compression_baseline — закон должен быть лучше mean
    [ ] test written, RED confirmed
    [ ] review
    [ ] approve
[ ] green: MDL comparison in discover path
    [ ] implementation, full suite GREEN
    [ ] lint
    [ ] review
    [ ] approve

## Status
- Next: `red: test_compression_baseline`
