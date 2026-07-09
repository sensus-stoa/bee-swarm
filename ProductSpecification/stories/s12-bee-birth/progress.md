# Story S1.2: Bee Birth (Spawn + Mutation)

> Protocol 2.2: E_spawn=15.0, ΔE_spawn=−7.0, E_child=7.0, mutate(G)

## Phases

### Phase 1: GrammarMutator ✅
- [x] GrammarMutator::mutate(G, available): add/remove/replace
- [x] 5 tests PASS

### Phase 2: Bee::spawn() ✅
- [x] E≥15 → child with mutated grammar, parent−7, child E=7
- [x] Dead bee guard, below-threshold guard
- [x] 21 total tests PASS

### Phase 3: OS process spawn
- [ ] proc_open → S1-WIRE Phase 4

## Status
🔧 Phase 3 — wiring (зависит от S1-WIRE)
