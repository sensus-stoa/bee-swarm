# Bee Swarm Stories — Stage 0 (HONEST_CRITERIA.md)

> Каждый критерий → story → progress.md → red → green → refactor → verify
> Цель: pass 7/7 критериев 24h непрерывно

## In Progress

| # | Criterion | What | Spec | Core | Tests | % |
|---|-----------|------|------|------|-------|---|
| 02 | 1.5 Plateau Honesty | PLATEAU detect + compose off | ✅ | — | — | 0% |

## Backlog (priority order)

| # | Criterion | What | Почему первый |
|---|-----------|------|---------------|
| 03 | 1.1 Held-Out Validation | train/test split, ε_holdout=0.10 | Фундамент: без него нет контроля false positives |
| 04 | 1.2 Statistical Sufficiency | t ≥ t_min, защита от случайных CV→0 | Без него held-out бессмысленен |
| 05 | 1.7 Compression Superiority | MDL cost vs y=mean(y) | Отсекает over-parameterized constants |
| 06 | 1.4 Non-Triviality | Алгебраическая редукция | Чистит +(x0,0) и ×(x1,1) |
| 07 | 1.3 Parsimony | complexity(e), выбор простейшего | Оптимизация, не безопасность — можно последним |

## Done

| # | Criterion | Completed |
|---|-----------|-----------|
| 01 | 1.6 Deduplication | ✅ preload + UNIQUE(name,formula) |

## Stage 1+ (будущее)

| Stage | Criteria | Когда |
|-------|----------|-------|
| Stage 1 | Living System (2.1–2.7 + bis–sexies) | После Stage 0 pass 24h |
| Stage 2 | Understanding (3.1–3.5) | После Stage 1 |
| Stage 3 | Autonomy | После Stage 2 |
