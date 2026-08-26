# АССОЦИАТИВНАЯ КУЛЬТУРА: spreading activation вместо relevance-scoring

## Дата: 26.08.2026 · Статус: архитектурный документ (не стори)
## Источник: сессия EXP-029..031 (три FAIL) + нейро-аналогия GPT-5.6

---

## 1. Что мы узнали из трёх провалов

| Попытка | Механизм | Взрыв |
|---|---|---|
| EXP-029 | inline AST B-атомов в Search | depth-взрыв → OOM 14.5GB |
| EXP-030 | opaque z-колонки | width-взрыв → beam не справляется |
| EXP-031 | residual retrieval + extend | beam/generation не находят CV=0 цепочку |

**Общий знаменатель:** культура меняла ФОРМУ комбинаторного взрыва,
но не устраняла его. Причина: **relevance как явный score** —
это всё ещё exhaustive search по памяти.

**Ключевой инсайт:**
> Релевантность — не свойство атома.
> Релевантность = состояние атома относительно текущего активного графа.

---

## 2. Нейро-аналогия (эвристика, не буквальность)

Мозг не считает score для 10^9 воспоминаний. Он делает:

```
частичный ключ (текущий контекст)
    ↓
распространение активации по сильным связям (pattern completion, CA3)
    ↓
конкуренция / inhibition (winner-take-most)
    ↓
3-5 ансамблей в рабочую память
```

Дорогой search — FALLBACK, а не нормальный режим:
`2+2` → retrieve; новая задача → большой search.

**Replay/consolidation:** успешный длинный маршрут A→F→Q→B→C
после повторов превращается в A→C (chunking).
«Поиск превратился в primitive» — это и есть культура.

---

## 3. Целевая архитектура Bee Swarm

### Три уровня памяти

1. **Семантическая культура** (long-term): B-примитивы SUB(a,b), MUL(a,b), square(a)
2. **Ассоциативная память**: граф переходов SUB→MUL (.83), MUL→DIV (.77) — веса = частота успеха
3. **Рабочая память**: 3-5 активных атомов на задачу; остальные 10000 НЕ участвуют

### Activation propagation вместо relevance-score

Начальные активные узлы: features + ops + current partial expression + task fingerprint.

```
A_i^(t+1) = (1−α)·A_i^t + α·Σ_j W_ji·A_j^t   (+ inhibition/нормализация)
```

2-3 hops → top-K activated atoms → рабочий Search.
**Никакого классификатора relevant/not-relevant.**

### Консолидация

Успешная цепочка retrieval'ов (SUB → MUL → DIV дала инвариант):
- усиливает веса этой траектории;
- после N подтверждений — рождает macro-node (новый primitive).

Это ровно механизм рождения B-атомов, но на уровне МАРШРУТОВ, не формул.

---

## 4. Почему это решает наши три взрыва

| Проблема | Было | Станет |
|---|---|---|
| depth explosion | inline AST × cascade | z = узел графа, depth не растёт |
| width explosion | 32 колонки в X | только top-K активированных |
| beam теряет цепочку | generate-all-then-filter | активация ведёт поиск ПО ГРАФУ |

Комбинаторика: `N^depth` → `(N+M)^depth` (хуже!) → **K^depth при K=2..4,
M хоть 100000** (память индексируется ДО поиска).

---

## 5. Дорожная карта (не спешить!)

### Фаза 0 (сейчас): граф культуры как данные
- grammar_ops уже хранит атомы; добавить таблицу culture_edges:
  from_atom, to_atom, op_transition, weight, success_count
- Заполнять из успешных discovery-цепочек улья (уже логируются!)

### Фаза 1: spreading activation прототип
- отдельный скрипт: heat + граф → top-K активированных → Search depth 3
- сравнение с волной 1 (heat 0/20)
- предрегистрация критерия: ≥10/20

### Фаза 2: консолидация маршрутов
- успешная цепочка из ≥3 шагов → macro-node (B-атом нового уровня)
- проверка: повторная задача решается за 1 шаг

### Фаза 3 (далеко): SUM3/PROD-n канонизация грамматики
- binary AST → семантическая глубина (отдельная большая работа)

---

## 6. Связь с существующими сторис

- CULTURE-COMPOSE P2 → заменяется этим документом
- CRITERION-EVOLUTION P1 → совместимо (Level 0 энергия фиксирована,
  Level 1 «как искать» — activation propagation И ЕСТЬ эволюция способа искать)
- SEARCH-STRATEGY-TRANSFER P2 → частично покрывается фазой 1-2

---

## 7. Честные оговорки

- Нейробиология — эвристика, не доказательство. Архитектура должна
  стоять на своих экспериментах (heat ≥10/20 или честный отказ).
- Граф весов может выродиться в монокультуру (один путь доминирует) —
  нужен ε-exploration (20% random activation), как в density routing улья.
- O(n) потоковый отбор остаётся для cold-start (граф пуст).


---

# ДОПОЛНЕНИЕ (26.08.2026, вторая итерация): 4 СЛОЯ ПАМЯТИ + КАРТА РЕГИОНОВ

## Ключевое различение (аналитик)

| Механизм | Функция | Аналог |
|---|---|---|
| Hashmap (ExactCache) | УЗНАВАНИЕ: contextHash → cached path | System 1, мгновенно |
| Граф (AssociationGraph) | АССОЦИАЦИЯ: node → likely next nodes | System 2, spreading |

Hash = «ровно такую ситуацию видел». Graph = «похожую — вот путь».
Нужны ОБА.

## Карта пространства возможностей (не формул — РЕГИОНОВ!)

Уровень 0: ADDITIVE / MULTIPLICATIVE / RATIO / POWER / DIFFERENCE /
PERIODIC / SATURATION
Уровень 1 (внутри RATIO): product/var, diff/var, sq/prod
Уровень 2: MUL → {MUL→DIV, SUB→MUL, SQ→DIV}
Листья: реальные cultural atoms / cached subgraphs

Поток: ВСЁ ПРОСТРАНСТВО → 5-10 районов → 1-2 района → 10-20 patterns
→ 2-4 atoms → дорогой Search.

## Fingerprint задачи (грубый, дешёвый — НЕ для решения!)

sign structure · monotonicity · pair correlations · log-correlations ·
ratio correlations · scale behavior · symmetry clues · distribution shape

Цель: определить КУДА смотреть («там что-то движется»), не решить задачу.

## Цикл внимания (не один lookup!)

F0 (task fingerprint) → регион → partial hypothesis e1
→ F1 = fingerprint(y/e1) → карта ПЕРЕСТРАИВАЕТ внимание → следующий primitive.

## Итоговые 4 слоя

1. ExactCache: contextHash → known path (дёшево)
2. RegionIndex: fingerprint → top-3 regions
3. AssociationGraph: внутри региона spreading activation
4. WorkingSet: 2-8 atoms — ТОЛЬКО ЗДЕСЬ дорогой Bee Search

**Следствие:** размер долговременной культуры почти не влияет на
размер Search. 100000 атомов → 4 активных.

## Cache маршрутов ≠ cache формул

Формула конкретна: κ(T2−T1)A/d.
Маршрут ОБЩИЙ: DIFFERENCE → PRODUCT → DIVISION — работает на куче других задач.
После опыта маршрут сам становится chunk: DIFF_PRODUCT_RATIO = новая
«мыслительная операция».

**Эволюция Bee: от примитивов к формулам → от успешных последовательностей
рассуждений к новым примитивам мышления.**

Depth/width FAIL'ы EXP-029..031 показали ЗАЧЕМ нужен механизм внимания,
а не просто больше памяти.

## PatternNode (offline-структура карты)

```
PatternNode { id; structural_signature; semantic_signature;
              children[]; successful_next[]; failures[];
              usage_count; payoff }
```

Успех SUB→MUL→DIV укрепляет маршрут SUB-(0.82)->MUL-(0.91)->DIV.
Сотни задач → карта навигирована; новая задача стартует с
nearest region + historically successful paths.

## Статус: архитектура зафиксирована. Реализация — после suite-green
## и закрытия текущих сторис. Первый шаг (фаза 0): логировать
## discovery-цепочки улья в culture_edges — данные уже идут.
