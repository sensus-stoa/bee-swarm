# Bee Swarm Stories — Stage 0 + Tech Debt

> Каждый критерий / техдолг → story → progress.md
> Цель: pass 7/7 Stage 0 критериев 24h непрерывно + SOLID архитектура

## In Progress

| # | What | Spec | Core | Tests | % |
|---|------|------|------|-------|---|
| D1b | Core module: Grammar, Search, ExpressionTree | ✅ | 🔧 | — | 0% |

## Backlog — Stage 0

| # | Criterion | What |
|---|-----------|------|
| 04 | 1.2 Statistical Sufficiency | t ≥ t_min |
| 05 | 1.7 Compression Superiority | MDL cost |
| 06 | 1.4 Non-Triviality | Алгебраическая редукция |
| 07 | 1.3 Parsimony | complexity(e) |

## Backlog — Modular Architecture

| # | Module | Classes |
|---|--------|---------|
| D1c | Validation/ | LawVerifier, LawCompressor, LawWatchdog |
| D1d | Bee/ | ConsciousBee, SelfLearningBee, CellBee |
| D1e | Hive/ | EcoHive, DensityHive, PersistentHive |
| D1f | Evolution/ | DarwinLoop, PhenotypeManager, Paradigm* |
| D1g | Forager/ | DataRequestor |
| D1h | Text/ | CorpusVocabulary, SentenceRegistry |
| D1i | Infra/ | Database, ResourceGuard, PlateauDetector |
| D1j | Meta/ + rest | MetaInventor, Ontology, SwarmSpawner, etc. |
| D2 | Daemon class | agenda.php → OOP |

## Done

| # | What | Completed |
|---|------|-----------|
| 01 | 1.6 Deduplication | ✅ |
| 02 | 1.5 Plateau Honesty + wakeup + forager | ✅ |
| 03 | 1.1 Held-Out Validation + retrospective | ✅ |
| 03b | 1.1 Retrospective Data | 🔧 2/4 |
| D1 | SOLID: Split AtomRegistry (7/7) | ✅ |
