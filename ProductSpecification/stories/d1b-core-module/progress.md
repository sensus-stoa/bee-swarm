# Story D1b: Core module (Grammar, Search, ExpressionTree)

> SOLID S: ядро системы → src/Core/

## Spec

1. `src/Grammar.php` → `src/Core/Grammar.php`
2. `src/Search.php` → `src/Core/Search.php`
3. `src/ExpressionTree.php` → `src/Core/ExpressionTree.php`
4. Namespace: `BeeSwarm` → `BeeSwarm\Core`
5. Все импорты обновлены, full suite green

## Core

[~] move: ExpressionTree → Core (нет зависимостей, безопасный первый шаг)
    [ ] moved, full suite GREEN
    [ ] lint
    [ ] review
    [ ] approve
[ ] move: Search → Core (зависит от Grammar)
[ ] move: Grammar → Core (зависит от AtomRegistry)
[ ] refactor: обновить namespace во всех use-ах
[ ] verify: full suite + daemon restart

## Status

- Next: `move: ExpressionTree → Core`
