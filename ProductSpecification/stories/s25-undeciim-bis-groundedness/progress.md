# Story S2.5-ундецим-бис: Groundedness Guard

> Протокол §2.5-ундецим-бис: защита от отрыва мета-модели от реальности.

## Spec

```
G_ratio = N_DREAMING_VERIFIED / N_FORAGER_VERIFIED (последние 500 тактов)
G_ratio > 0.7 → GROUNDEDNESS_WARNING: Forager приоритет, dreaming stop
G_ratio > 0.9 → GROUNDEDNESS_CRITICAL: все dreaming-атомы → вес 0.1
DREAMING→VERIFIED только через Forager (Contour 1)
Obsolescence detection на dreaming-атомах (Contour 2)
```

## Core

[ ] red: test_groundedness_ratio_computed — G_ratio логируется ≥5 раз за период
[ ] red: test_warning_on_high_dreaming_ratio — G_ratio>0.7 → WARNING
[ ] red: test_critical_suppresses_dreaming — G_ratio>0.9 → dreaming atoms weight=0.1
[ ] red: test_dreaming_verified_requires_forager — DREAMING атом не может стать VERIFIED без Forager
[ ] green: GroundednessGuard class
[ ] green: wiring in dreaming evaluation
[ ] refactor + lint

## Work Units

[ ] red: test_groundedness_ratio_computed
[ ] red: test_warning_on_high_dreaming_ratio
[ ] red: test_critical_suppresses_dreaming
[ ] red: test_dreaming_verified_requires_forager
[ ] green: GroundednessGuard
[ ] green: wiring
[ ] tests pass + review

## Status
- Next: `red: test_groundedness_ratio_computed`
