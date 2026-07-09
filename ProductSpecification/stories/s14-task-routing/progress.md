# Story S1.4: Density-based Task Routing

> Protocol 2.4: distribution non-uniform. Implementation: fingerprint + outcome history, domains emerge.

## Phases

### Phase 1: TaskRouter ✅
- [x] Structural fingerprint (columns, size bucket, text/numeric)
- [x] Outcome history: fingerprint → bee → success score
- [x] Exploration/exploitation: N ticks random + 20% ongoing
- [x] 4 tests PASS, review PASS

### Phase 2: Timeout + reassign (backlog)
- [ ] K=100 ticks timeout, переназначение
- [ ] → S1-WIRE Phase 2

## Status
🔧 Phase 2 — wiring (зависит от S1-WIRE)
