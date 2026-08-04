# Story S1.2: Bee Birth (Spawn + Mutation)

> Protocol 2.2: E_spawn=15.0, ΔE_spawn=−7.0, E_child=7.0, mutate(G)
> Protocol §2.2 дополнение: Gap-Triggered Spawn при PLATEAU + новые данные

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

### Phase 4: Gap-Triggered Spawn (GAP_SPAWN) ⬜
> Сейчас: SPAWN_THRESHOLD=15.0, E₀=10.0 — пчёлы никогда не достигают порога на plateau.
> Gap-spawn — дополнительный путь размножения.

**Spec:**
```
PLATEAU > 5×P тактов (250 тактов)
  И (FORAGER_NEW_DOMAIN или FORAGER_NEW_TASK
      ИЛИ PLATEAU > 10×P тактов (500 тактов) — fallback без новых данных)
  И E > 0
→ разрешён spawn одной пчелы с мутированной грамматикой
→ энергия потомка = min(E₀, E_parent × 1.5)
→ энергия родителя НЕ расходуется
→ лог: GAP_SPAWN [plateau|new_data]
```

**Work units:**
[ ] red: test_gap_spawn_on_plateau — PLATEAU 250+ тактов + new tasks → GAP_SPAWN
[ ] red: test_gap_spawn_fallback_no_new_data — PLATEAU 500+ → GAP_SPAWN без новых данных
[ ] red: test_gap_spawn_respects_energy — E_parent не меняется
[ ] red: test_no_gap_spawn_with_energy — E=0 → нет спавна
[ ] green: GapSpawner in SpawnManager
[ ] green: wiring in Hive::doTick()
[ ] refactor + lint

## Status
🔧 Phase 1-2 done. Phase 3 — wiring (зависит от S1-WIRE). Phase 4 — gap-spawn.
