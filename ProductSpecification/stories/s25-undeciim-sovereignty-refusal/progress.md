# Story S2.5-ундецим: Sovereignty Refusal (опционально)

> Протокол §2.5-ундецим: право пчелы отказаться от задачи (О — опционально).

## Spec

```
Пчела может ответить REFUSAL если задача противоречит ≥3 verified-законам (вес≥0.7)
REFUSAL ≠ gap: «знаю как, но не буду»
Ограничение: REFUSAL_rate < 0.3 за 100 тактов (иначе penalty)
Событие логируется: REFUSAL bee_id task_id conflicting_laws=[...]
```

## Core

[ ] red: test_refusal_on_conflict — 3+ conflicting laws → REFUSAL
[ ] red: test_refusal_rate_limit — >30% REFUSAL → penalty
[ ] red: test_gap_not_refusal — unknown task → gap, not REFUSAL
[ ] green: SovereigntyRefusal in Bee
[ ] green: wiring in bee tick
[ ] refactor + lint

## Work Units

[ ] red: test_refusal_on_conflict
[ ] red: test_refusal_rate_limit
[ ] red: test_gap_not_refusal
[ ] green: SovereigntyRefusal
[ ] green: wiring
[ ] tests pass + review

## Status
- Next: `red: test_refusal_on_conflict`
