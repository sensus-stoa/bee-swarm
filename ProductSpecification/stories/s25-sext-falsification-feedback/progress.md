# Story S2.5-секст: Falsification Feedback

> Протокол §2.5-секст: penalty на источники шумовых кандидатов.

## Spec

```
Forager candidate → CV > ε_holdout → rejected
  → penalty на источник кандидата
  → Forager снижает приоритет этого типа паттернов в этой области
  → лог: FALSIFICATION_FEEDBACK
```

## Core

[ ] red: test_falsification_penalty_on_rejected_candidate — reject → source weight −0.1
[ ] red: test_falsification_reduces_priority — after 3 rejections, source deprioritised
[ ] red: test_verified_candidate_resets_penalty — verify → weight restored
[ ] green: FalsificationTracker in Forager
[ ] green: candidate rejection → penalty wiring
[ ] refactor + lint

## Work Units

[ ] red: test_falsification_penalty_on_rejected_candidate
[ ] red: test_falsification_reduces_priority
[ ] red: test_verified_candidate_resets_penalty
[ ] green: FalsificationTracker
[ ] green: wiring in candidate evaluation
[ ] tests pass + review

## Status
- Next: `red: test_falsification_penalty_on_rejected_candidate`
