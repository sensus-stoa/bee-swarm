# Story D1i: Infrastructure module (Database, ResourceGuard, PlateauDetector)

> SOLID S: инфраструктура → src/Infra/

## Spec
1. `src/Database.php` → `src/Infra/Database.php`
2. `src/ResourceGuard.php` → `src/Infra/ResourceGuard.php`
3. `src/PlateauDetector.php` → `src/Infra/PlateauDetector.php`
4. Namespace: `BeeSwarm\Infra`

## Core
[~] move: PlateauDetector уже изолирован → Infra
[ ] move: ResourceGuard → Infra
[ ] move: Database → Infra (много импортов!)
[ ] verify: full suite green
