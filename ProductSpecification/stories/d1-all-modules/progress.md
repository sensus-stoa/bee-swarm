# Story D1-all: Complete Modular Architecture

> Все оставшиеся классы → модули. Один заход.

## Plan (в порядке: нет зависимостей → есть)

| Step | Files | Target |
|------|-------|--------|
| 1 | CellBee | src/Bee/ |
| 2 | ConsciousBee, SelfLearningBee, SwarmSpawner | src/Bee/ |
| 3 | EcoHive, DensityHive, PersistentHive | src/Hive/ |
| 4 | DarwinLoop, PhenotypeManager | src/Evolution/ |
| 5 | ParadigmHypothesis, ParadigmSwarm, ParadigmValidator | src/Evolution/ |
| 6 | DataRequestor | src/Forager/ |
| 7 | CorpusVocabulary, SentenceRegistry | src/Text/ |
| 8 | Database, ResourceGuard, PlateauDetector | src/Infra/ |
| 9 | MetaInventor, Ontology, ConceptRegistry | src/Knowledge/ |
| 10 | Остальное: AutonomousAgent, HypothesisGenerator, SelfOptimizer, etc. | по доменам |

## Rules
- ≤ 7 файлов в директории
- Namespace: `BeeSwarm\{Module}`
- Full suite после каждого шага
- 264 tests green в конце

## Status
[~] Step 1: CellBee → Bee/
