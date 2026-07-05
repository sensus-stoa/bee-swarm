# Story 03b: Retrospective Data

> 181/193 законов не проверены — generated-таски не матчатся по именам.
> 12 passed, 9 overfit removed. Нужно покрыть generated и cloze.

## Spec

1. ✅ Foraged-законы: forager scan при старте → 9 overfit удалено
2. [ ] Generated-законы: GEN_+_× → матчить с getTasks()
3. [ ] Cloze-законы: cloze_{i}_{pos} → реконструировать из SentenceRegistry
4. Цель: ≥ 90% законов проверены ретроспективно

## Core

[x] red: test_retro_data_foraged
[x] green: foraged task reconstruction + daemon startup
[~] red: test_retro_data_generated — generated-таски матчатся
    [ ] test written, RED confirmed
    [ ] review
    [ ] approve
[ ] green: generated task matching
    [ ] implementation done, full suite GREEN
    [ ] lint
    [ ] review
    [ ] approve
[ ] refactor
[ ] verify: retrospective ≥ 90% coverage

## Status

- 2/4 work units done
- Next: `red: test_retro_data_generated`
