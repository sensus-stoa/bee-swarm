# Story D1j: Остальное (MetaInventor, Ontology, worker, etc.)

> SOLID S: оставшиеся классы

## Spec
1. `src/MetaInventor.php` → `src/Meta/MetaInventor.php`
2. `src/Ontology.php` → `src/Meta/Ontology.php`
3. `src/worker.php` — оставить в корне (точка входа RoadRunner)
4. `src/SwarmSpawner.php` → `src/Bee/SwarmSpawner.php`
5. `src/ConceptRegistry.php` → `src/Knowledge/ConceptRegistry.php`
6. `src/SelfOptimizer.php` + `SelfRewriter.php` → `src/Evolution/`

## Core
[~] move: оставшиеся классы по доменам
[ ] verify: full suite green
