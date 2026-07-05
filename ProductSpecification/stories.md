# Bee Swarm Stories — Stage 0 (HONEST_CRITERIA.md)

> Каждый критерий → story → progress.md → red → green → lint → refactor → verify
> Цель: pass 7/7 критериев 24h непрерывно

## In Progress

| # | Criterion | What | Spec | Core | Tests | % |
|---|-----------|------|------|------|-------|---|
| 02b | 1.5 Plateau Wakeup | forager→tick, timeout probe | ✅ | 🔧 | 1/1 | 100% |
| 02c | 1.5 Forager Integration | wire Forager, plateau exit | ✅ | 🔧 | 1/1 | 100% |
| 03 | 1.1 Held-Out Validation | train/test split, ε=0.10 | ✅ | 🔧 | 4/4 | 100% |
| 03b | 1.1 Retrospective Data | foraged/generated task reconstruction | ✅ | 🔧 | 2/4 | 50% |
| D1 | SOLID: Split AtomRegistry | atoms/discover/heldout/retro/cv | ✅ | — | — | 0% |

## Backlog (priority order)

| # | Criterion | What | Почему первый |
|---|-----------|------|---------------|
| 03 | 1.1 Held-Out Validation | train/test split, ε_holdout=0.10 | Фундамент: без него нет контроля false positives |
| 04 | 1.2 Statistical Sufficiency | t ≥ t_min, защита от случайных CV→0 | Без него held-out бессмысленен |
| 05 | 1.7 Compression Superiority | MDL cost vs y=mean(y) | Отсекает over-parameterized constants |
| 06 | 1.4 Non-Triviality | Алгебраическая редукция | Чистит +(x0,0) и ×(x1,1) |
| 07 | 1.3 Parsimony | complexity(e), выбор простейшего | Оптимизация, не безопасность — можно последним |

## Technical Debt (после Stage 0)

| # | Что | Инструменты |
|---|-----|-------------|
| D1 | Модульная структура + SOLID | S: AtomRegistry→AtomProvider+LawValidator, Forager→SourceAdapter, Grammar→AtomDefinitions. I: ValidatorInterface, TaskProviderInterface, SourceInterface. Архитектура: ADR/Layered по ARCHITECTURE.md |
| D2 | agenda.php → Daemon class | procedural → testable OOP, тонкий entry point |
| D3 | Статический анализ | psalm level 3+ |
| D4 | Clean Code PHP rules | https://github.com/piotrplenik/clean-code-php |
| D5 | Cognitive complexity | https://github.com/Rarst/phpcs-cognitive-complexity |
| D6 | Автоформатирование | php-cs-fixer (PSR-12) |
| D7 | Forager — баги и оптимизации | scanDir 110 строк → разбить. maxTotal=30, strategyScores leak, composed→json, hardcoded domains, hardcoded exclusions, explode_lines comma-split, file_get_contents @-silent. Плюс: pluggable sources (файлы/сеть/LLM), maxTasks env, domain extraction |

## Lint step (добавлен в workflow)

После GREEN, до REVIEW: `php -l` на всех изменённых .php файлах. Ловит parse errors до коммита.

## Done

| # | Criterion | Completed |
|---|-----------|-----------|
| 01 | 1.6 Deduplication | ✅ preload + UNIQUE(name,formula) |
| 02 | 1.5 Plateau Honesty | ✅ detect + compose gating |
