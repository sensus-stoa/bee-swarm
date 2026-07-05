# Story D1: Split AtomRegistry (SOLID S + I)

> S: один класс = одна ответственность
> I: мелкие интерфейсы для тестируемости

## Spec

1. Атомы → `src/Core/AtomDefinitions.php` (150 строк конфигурации)
2. `AtomRegistry::discover()` → `src/Core/AtomProvider.php`
3. `AtomRegistry::discoverHeldout()` → `src/Validation/LawValidator.php`
4. `AtomRegistry::retrospectiveValidate()` → `src/Validation/RetrospectiveValidator.php`
5. `AtomRegistry::cv()` → `src/Math/CvCalculator.php`
6. `AtomRegistry` → deprecated alias, все тесты перенаправлены

Интерфейсы:
- `ValidatorInterface` — validate(atom, X, y): ?Result
- `TaskProviderInterface` — getTasks(): array

## Core

[~] extract: atoms → AtomDefinitions.php
    [ ] atoms moved, full suite GREEN
    [ ] review
    [ ] approve
[ ] extract: discover → AtomProvider
    [ ] moved, full suite GREEN
    [ ] lint
    [ ] review
    [ ] approve
[ ] extract: discoverHeldout → LawValidator
    [ ] moved, full suite GREEN
    [ ] lint
    [ ] review
    [ ] approve
[ ] extract: retrospective → RetrospectiveValidator
    [ ] moved, full suite GREEN
    [ ] lint
    [ ] review
    [ ] approve
[ ] extract: cv → CvCalculator
    [ ] moved, full suite GREEN
    [ ] lint
    [ ] review
    [ ] approve
[ ] add: ValidatorInterface
    [ ] implemented, full suite GREEN
    [ ] lint
    [ ] review
    [ ] approve
[ ] refactor: update agenda.php imports
[ ] verify: full suite + daemon restart

## Status

- Next: `extract: atoms → AtomDefinitions.php`
