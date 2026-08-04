# Story D19: FAANG Review — Bug Prevention Layers 1-4

> Architectural Review v2 (04.08.2026). 4-layer strategy.

## Layer 1: Write Fewer Bugs

### D19.1: GlobalStateGuard ⭐⭐
> Ловит утечки глобального состояния PHP (srand, set_error_handler, etc.)
[ ] red: test_guard_detects_srand_leak
[ ] green: GlobalStateGuard::snapshot() + ::assertClean()

### D19.2: Task Value Object ⭐⭐⭐
> Замена ad-hoc массивов на typed Task объект. Убирает "undefined array key" баги.
[ ] red: test_task_requires_domain
[ ] green: Task class + миграция

### D19.3: Local Random Engine ⭐⭐
> `Random\Engine\Mt19937` вместо глобального srand().
[ ] red: test_local_rng_does_not_poison_global
[ ] green: Random class wrapping Mt19937

## Layer 2: Catch Before Production

### D19.4: Contract Tests ⭐⭐⭐
> Межмодульные гарантии: "getTasks() не портит array_rand()"
[ ] red: test_getTasks_does_not_poison_rng
[ ] green: contract test suite

### D19.5: Psalm Level 2 ⭐
> Повышение с level 5 до level 2
[ ] psalm level 2 → fix errors → commit

### D19.6: Periodic Invariants ⭐⭐
> assert в tick loop: RNG clean, task diversity, bee energy в диапазоне
[ ] red: test_invariant_assertion_fires_on_violation
[ ] green: InvariantGuard in Hive::doTick()

## Layer 3: Diagnose Quickly

### D19.7: StructuredLog (JSON) ⭐⭐
> JSON-логи вместо emoji. jq-queryable.
[ ] red: test_log_is_valid_json
[ ] green: StructuredLog + миграция

### D19.8: Diagnostics Script ⭐
> `scripts/diagnostics.php` — одна команда = health check
[ ] green: diagnostics.php (no RED — скрипт)

## Layer 4: Prevent Regression

### D19.9: Pre-Commit v2 ⭐⭐
> Contract tests + debug code detection + gate-v2.sh
[ ] green: gate-v2.sh + hook update

### D19.10: Named Regression Tests ⭐
> Каждый баг → *RegressionTest.php
[ ] green: конвенция + шаблон

## Status
- Created: 04.08.2026
- Source: docs/architectural-review-v2-bug-prevention-strategy.md
- Next: D19.1 (GlobalStateGuard)
