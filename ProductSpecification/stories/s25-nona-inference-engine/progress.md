# Story S2.5-нона: Inference Engine

> Протокол §2.5-нона: транзитивность, modus ponens, симметричность как мета-атомы.

## Spec

```
Мета-правила (всегда active): transitive(R), symmetric(R), modus_ponens
Доменные правила (active при CV→0): transitive(R, domain)
Throttle: W_inf_min=0.3, ρ_op≥0.1, N_atoms≥50
Вес: W_result = min(W₁,W₂) × 0.5^N (экспоненциальное затухание)
Жизненный цикл: INFERENCE → VERIFIED (через Forager) или удалён (stale/contradiction)
```

## Core

[ ] red: test_transitive_inference — R(A,B)∧R(B,C) → R(A,C) with weight 0.5
[ ] red: test_inference_throttle — below N_atoms=50 → no inference
[ ] red: test_inference_verified_promotion — Forager confirms → origin=VERIFIED
[ ] red: test_inference_stale_removal — T_inf_stale generations → removed
[ ] green: InferenceEngine class
[ ] green: wiring in candidate evaluation pipeline
[ ] refactor + lint

## Work Units

[ ] red: test_transitive_inference
[ ] red: test_inference_throttle
[ ] red: test_inference_verified_promotion
[ ] red: test_inference_stale_removal
[ ] green: InferenceEngine
[ ] green: wiring
[ ] tests pass + review

## Status
- Next: `red: test_transitive_inference`
