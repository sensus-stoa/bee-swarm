# Story S1.5: Evolutionary Dynamics

> Protocol 2.5: популяция демонстрирует сжатие грамматики и сохранение разнообразия через поколения.

## Спецификация

Из протокола §2.5:
- Поколение: `count(spawn_events) ≥ N` (N = размер популяции)
- Снимок поколения: средний |G|, уникальные грамматики, разнообразие Жаккара
- Через 100 поколений: средний |G|↓, разнообразие ≥ 0.1
- Монотонный рост grammar = накопление, не эволюция. Монокультура = premature convergence.

## Что уже есть

- `stories.md` S1.5 в бэклоге как ⭐⭐⭐
- Механизмы смерти/рождения/изоляции/маршрутизации из S1.1-S1.4
- `AtomRegistry` — мутация уже работает через addDiscoveredTextAtom
- `StrategyEvolutionTest` — есть тесты на эволюцию стратегий

## Phases

### Phase 1: Generation tracking
- RED: test_generation_counter — spawn_events ≥ N → generation++
- GREEN: GenerationTracker в Hive

### Phase 2: Diversity metrics
- RED: test_jaccard_diversity — 3 пчелы, 3 грамматики → diversity ∈ [0,1]
- GREEN: DiversityMonitor::jaccard(array $grammars): float

### Phase 3: Grammar compression
- RED: test_grammar_shrinks_over_generations — средний |G| в gen 100 < gen 20
- GREEN: отбор: пчёлы с меньшей grammar при том же CV получают приоритет

### Phase 4: Monoculture prevention
- RED: test_diversity_never_zero — при ≥2 пчёлах diversity > 0
- GREEN: mutation rate адаптивный — растёт при падении diversity

## Метрики цели
- verify_1_5: средний |G|_gen100 < средний |G|_gen20, diversity_gen100 ≥ 0.1

## Status
⬜ Backlog
