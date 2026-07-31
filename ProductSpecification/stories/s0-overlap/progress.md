# Story 🔬 S0-OVERLAP: Overlap Awareness (§1.8)

> Протокол: pairwise сравнение ответов пчёл при переназначении задач

## Phases

### Phase 1: OverlapTracker core
- [~] RED: test_overlap_tracker_records_pairwise — OverlapTracker::record()
- [ ] GREEN: OverlapTracker class + overlap_log DB table
- [ ] LINT + REVIEW + APPROVE

### Phase 2: Integration with Hive
- [ ] RED: test_hive_logs_overlap_on_reassign — Hive пишет OVERLAP при переназначении
- [ ] GREEN: Wiring в doTick()

### Phase 3: verify_0_8
- [ ] RED: test_verify_0_8_pass — за 24h: ≥1 OVERLAP, ≥1 пара с shared_tasks≥10
- [ ] GREEN: verify_0_8.php скрипт

## Status
⬜ Backlog
