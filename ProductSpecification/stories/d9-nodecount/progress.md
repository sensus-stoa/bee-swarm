# Story D9: ExpressionTree::nodeCount()

> Complexity = real AST nodes, not `substr_count('(', ...)`

## Spec

1. `ExpressionTree::nodeCount(): int` — рекурсивный подсчёт узлов
2. Замена proxy `1 + substr_count('(')` на реальный подсчёт в:
   - `LawValidator::selectSimplest()`
   - `AtomRegistry::isBetterThanBaseline()`
3. Тест: `add` = 1, `add(min)` = 2, `add(min(x0))` = 3, `+(x0,0)` = 2

## Status
⬜ Backlog — после Stage 0
