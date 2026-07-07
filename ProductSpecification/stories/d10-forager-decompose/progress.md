# Story D10: Forager Decomposition

> Forager.php: 638 lines, 11 методов >40 строк. Раздулся как удав.

## Spec

1. **Forager.php** → `src/Forager/` package:
   - `Forager.php` (~150 lines) — orchestrator, scan()
   - `Scanner.php` (~100 lines) — file iteration, content reading
   - `Accumulator.php` (~120 lines) — SQLite-backed scanWithAccumulator
   - `Strategies.php` (~150 lines) — strategy loading + composition
   - `Semantics.php` (~80 lines) — addSemanticFact, KG insert

2. Каждый файл ≤200 строк, каждый метод ≤30 строк

3. **Priority:** после E1, до Stage 1

## Status
⬜ Backlog
