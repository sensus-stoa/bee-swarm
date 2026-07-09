# Story S1.3: Grammar Isolation

> Protocol 2.3: no shared grammar state, inheritance only at spawn.

## Phases

### Phase 1: In-memory isolation ✅
- [x] private array $grammar, grammar() returns by value (COW)
- [x] spawn creates new Bee instance → independent grammar
- [x] 4 isolation tests PASS

### Phase 2: SQLite per-bee tables (backlog)
- [ ] grammar_ops_{bee_id} tables
- [ ] Миграция из общей grammar_ops

## Status
🔧 Phase 2 — backlog (in-memory достаточно для S1-WIRE)
