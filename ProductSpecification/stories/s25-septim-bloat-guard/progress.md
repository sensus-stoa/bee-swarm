# Story S2.5-септим: Grammar Bloat Guard (MITOSIS)

> Протокол §2.5-септим: |G| > 50 → принудительное деление грамматики.

## Spec

```
Если |G_i| > G_max (50) > 10 тактов:
  → родитель делит грамматику пополам
  → потомок получает вторую половину (+мутация)
  → родитель: ΔE = −5.0, потомок: E₀ = 5.0
  → лог: MITOSIS
```

## Core

[ ] red: test_bloat_guard_triggers_mitosis — |G|>50 >10 тактов → MITOSIS
[ ] red: test_mitosis_splits_grammar — parent+child ≈ old_grammar (±5 ops)
[ ] red: test_no_mitosis_below_threshold — |G|≤50 → no MITOSIS
[ ] green: BloatGuard::check() в doTick()
[ ] green: Bee::mitosis() — divide grammar, spawn child
[ ] refactor + lint

## Work Units

[ ] red: test_bloat_guard_triggers_mitosis
[ ] red: test_mitosis_splits_grammar
[ ] red: test_no_mitosis_below_threshold
[ ] green: BloatGuard class + wiring
[ ] green: Bee::mitosis()
[ ] tests pass + review

## Status
- Next: `red: test_bloat_guard_triggers_mitosis`
