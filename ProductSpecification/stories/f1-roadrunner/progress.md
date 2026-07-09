# Story F1: RoadRunner Worker for Bee

> Каждая пчела — отдельный PHP процесс под управлением RoadRunner. Hive = HTTP supervisor, пчёлы = workers.

## Phases

### Phase 1: RoadRunner setup ✅
- [x] Установлен `rr` binary (v2025.1.15)
- [x] worker.php: PSR-7 worker с /status + /task
- [x] .rr.yaml: pool=4, port=8765, relay=pipes
- [x] nyholm/psr7 installed

### Phase 2: Bee in worker ✅
- [x] BeeWorker wraps Bee (energy, grammar, handleTask)
- [x] GET /status → {energy, grammar, alive, discoveries}
- [x] POST /task → chargeSearch + return grammar

### Phase 3: BeeWorker::handleTask → реальный Search::find
- [ ] См. S1-WIRE Phase 1

## Status
🔧 Phase 3 → S1-WIRE
