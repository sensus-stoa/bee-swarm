# Story 01: Deduplication (Criterion 1.6)

> HONEST_CRITERIA.md §1.6
> PLATEAU_PLAN: 1.1

## Spec

BUG: `laws.name UNIQUE` — только первый атом сохраняется. После рестарта остальные переоткрываются.
Fix: `UNIQUE(name, formula)` + preload из БД в agenda.php.

## Core

[x] red: test_knownlaws_preload (0a21c51)
[x] green: knownLaws preload fix (62b3304) — 243 tests, 0 failures
[x] refactor: extract LAWS_DDL constant (bfde42f)
[~] verify: daemon restart → pgrep check → 0 повторов в логе

## Status

- 1/1 implemented and refactored
- Next: `verify`
