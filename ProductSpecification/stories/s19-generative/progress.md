# Story S1.9-GENERATIVE: Reduce — Arity Bridge

> Protocol: generative rule. AXIOM, not operator.
> `Grammar::reduce(string $op, array $vector): ?float` — любой бинарный ассоциативный оператор → float[]→float.
> ОДНА аксиома для всей грамматики. НЕ добавлять sum/mean/correl вручную.

## Почему

Грамматика заперта в float→float. Compose не порождает float[]→float.
Реальные данные (sleep→energy 152 строки, vault 5000 файлов) — 0 открытий за 11ч.
Reduce — единственный мост между арностями (4 эксперта: Альтшуллер, Викентьев, Пелевин, Эволюционист).

## Архитектура цели

```
Expr ::= ScalarOp(Expr, Expr)        // (float,float)→float
      | Reduce(AssociativeOp, Vector) // float[]→float  ← THE BRIDGE
      | Map(ScalarOp, Vector)         // float[]→float[] (Stage 2)

reduce(+, v)  = sum(v)
reduce(×, v)  = product(v)
reduce(max, v) = maximum(v)
reduce(min, v) = minimum(v)
compose(reduce(+, w), /(x, count)) → mean(v)
```

## Phases

### Phase 1: Grammar::reduce ✅
- [x] RED: tests/GrammarReduceTest.php — sum/product/max/min, один элемент, пустой вектор → null, не-ассоциативные (−, /) → null, неизвестный op → null, float precision
- [x] GREEN: реализация в Grammar::reduce (11/11 PASS)
- [x] LINT + REVIEW (sub-agent commit on PASS)

### Phase 1.5: Флак-фикс BootstrapTest ✅ (01.08)
- [x] RED: HiveZeroTicksTest — run(maxTicks=0) = bootstrap без тика
- [x] GREEN: early return в Hive::run() (maxTicks === 0)
- [x] BootstrapTest E₀: maxTicks=0 → assertSame(10.0), детерминированно
- [x] 400/400 PASS (было: 1/5 прогонов BootstrapTest флакал из-за novelty +0.5)

### Phase 2: Search integration ✅
- [x] Search::find: GlobalReduce слой — reduce по колонкам (R+x0, R×x0, Rmaxx0, Rminx0)
- [x] Pointwise выражения: (xj / Ropxj), (xj - Rminxj), (xj - Rmaxxj)
- [x] Range константа Rrangex0 + min-max нормализация (Rnormx0)
- [x] 3 теста в SearchTest: testFindReduceMin, testFindReduceMinMaxNormalization, testFindReduceWithMultipleColumns
- [x] 415/415 PASS (было 412), 853 assertions

### Phase 3: Co-evolution (backlog, Stage 2)
- [ ] S2.13-AFD: повторяющиеся compose-цепи → новые операторы
- [ ] S2.14-METAGRAMMAR: система находит СВОИ generative rules

## Что НЕ делать
- ❌ Не добавлять sum, correl, mean, std, linear_regression
- ❌ Не хардкодить статистические операторы
- ❌ Gradient reward за CV improvement (NovaMind-3 anti-pattern)

## Статус
✅ Phase 1 + Phase 1.5 завершены (09741be)
✅ Phase 2 — Search integration + Hive wiring (cb2cc2c)
⬜ Phase 3 — Co-evolution (backlog, Stage 2)

## Результаты Phase 2 (01.08.2026)
- **Первые открытия на реальных данных** (ноутбук, демон PID 15016):
  - sleep→energy: 3 закона (CV=0.055–0.084)
  - discipline→dq: 3 закона (CV=0.075–0.084)
  - foraged_num_0af5a10ad369: 3 закона (CV=0.067–0.133)
  - dq→intact, stress→intact, gi→dq, anxiety→intact, energy→intact, sleep→discipline
- Все используют reduce-выражения (R+x0, x0/R+x0, Rmaxx0, x0minK2)
- Hive::doDiscoverTick теперь вызывает Search::find с калиброванным ε_null
- 416/416 PASS, 855 assertions
