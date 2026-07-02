# ExoCortex AGI Roj — Architecture v4

> 03.07.2026 | 7 слоёв. Полная автоэволюция. Без curated.

---

## 0. Принцип

```
ОДИН механизм: вариация + CV→0 + среда = эволюция.
На всех слоях. Без исключений.
```

---

## 1. Слои

### Слой 0: Среда (дана)

| Компонент | Что | Статус |
|-----------|-----|--------|
| Атомы | `get_defined_functions()` → 27 числовых + семантические | ✅ |
| Данные | Файлы, метрики, Obsidian, код, тексты | ✅ |
| Ресурсы | CPU≤50%, MEM≤50%, adaptive throttle | ✅ |
| Forager | Сканер с evolving стратегиями, числовой + семантический вывод | ✅ |

### Слой 1: Законы (Discover)

```
Задачи × Атомы → CV→0 → grammar_ops
```

| Механизм | Статус |
|----------|--------|
| `AtomRegistry::discover()` — перебор атомов × CV→0 | ✅ |
| `AtomRegistry::discoverCompose()` — пары grammar-атомов × CV→0 | ✅ |
| `knownLaws` — защита от повторов | ✅ |
| Compose pruning — удаление ложных compose | ✅ |
| Алфавит из `get_defined_functions()` — 0 curated | ✅ |
| Strict threshold (CV ≤ 0.01) | ✅ |

### Слой 2: Self-generate (Compose)

```
Grammar → compose-задачи → пополнение пула
```

### Слой 3: Self-coding

```
Открытие атома → генерация .php → валидация → src/Generated/
```

### Слой 4: Semantic

```
Законы → knowledge_graph → связи → семантические законы
Forager → тексты → is_a факты → семантические задачи
```

| Механизм | Статус |
|----------|--------|
| Discovery → `solves`, `is_a` | ✅ |
| Compose → `composes_with` | ✅ |
| Семантический вывод Forager'а (`preg_match_is_a`) | ✅ |
| Compose семантических стратегий | ✅ (эволюционирует) |

### Слой 5: Self-modify (Phenotype)

```
Фенотип → мутация (×1.5/÷1.5) → fitness → отбор
```

### Слой 6: H1 — Верификация

```
Старые законы × свежие данные → CV→0? → подтвердить/удалить
```

| Механизм | Статус |
|----------|--------|
| Arithmetic pruning | ✅ |
| Generated pruning | ✅ |
| Semantic verification | ✅ |
| Law compression (≥3 → meta-law) | ✅ |
| D/C ratio + Fidelity monitoring | ✅ |

### Слой 7: Автономные решения

```
Метрики → selectGoal() → действие → результат
Forager с приоритетами в демоне
```

---

## 2. Что НЕ используется

- ❌ MetaInventor, curated atom lists, hardcoded пороги
- ❌ Trust/HTTP/Sandbox для эволюции grammar
- ❌ Любой механизм кроме «вариация + CV→0 + среда»
- ❌ Пользователь для: модулей, поиска, проверки, целей

---

## 3. Файлы

```
src/
├── AtomRegistry.php       ← apply + discover + compose + loadEnvironment
├── Forager.php            ← evolving strategies, numeric + semantic output
├── Grammar.php            ← BASE_OPS (+,×,−,/,min,max,sq) + applyCustom
├── Search.php             ← CV + find (strict threshold: 0.01)
├── LawVerifier.php        ← H1: верификация + прунинг
├── LawCompressor.php      ← кластеризация compose-законов → meta-law (*)
├── SemanticVerifier.php   ← кросс-валидация фактов, поиск противоречий
├── PhenotypeManager.php   ← мутация + отбор фенотипа
├── ResourceGuard.php      ← CPU/MEM limits + throttle
├── Database.php           ← singleton SQLite (data/swarm.db)
├── Generated/             ← 93 автосгенерированных модуля

agenda.php                 ← демон v4: все 7 слоёв

tests/                     ← 184 теста, 376 утверждений
├── AtomRegistryTest.php            ← 18
├── EnvironmentAlphabetTest.php     ← 7
├── DiscoveryLoopTest.php           ← 5
├── ResourceGuardTest.php           ← 7
├── PhenotypeEvolutionTest.php      ← 6
├── LawVerificationTest.php         ← 5
├── LawCompressionTest.php          ← 3
├── SemanticLayerTest.php           ← 4
├── SemanticVerificationTest.php    ← 4
├── SelfCodingTest.php              ← 6
├── SelfRegulationTest.php          ← 5
├── ForagerIntegrationTest.php      ← 6
├── ForagerOutputTest.php           ← 4
├── ForagerEvolutionTest.php        ← 6
├── StrategyEvolutionTest.php       ← 4
├── StrictThresholdTest.php         ← 4
├── AutonomousDecisionsTest.php     ← 5
├── ComposePruningTest.php          ← 4
└── KnowledgeGraphTest.php          + ещё ~80
```

---

## 4. Фенотип

| Параметр | По умолчанию | Эволюционирует |
|----------|-------------|---------------|
| compose_min_grammar | 3 | ✅ |
| task_regen_interval | 100 | ✅ |
| starvation_timeout | 600 | ✅ |
| forager_max_files | 30 | ✅ |
| mutation_interval | 1000 | ✅ |

---

## 5. Что осталось

| Действие | Статус |
|----------|--------|
| `@reboot cron` | ❌ 1 строка |
| Сеть (forager URL) | ⬜ file_get_contents |
| Самотестирование | ⬜ |
| ExoCortex-lite (чужие данные) | 🔍 протестировано |

---

## 6. Ключевые цифры

```
Слоёв:            7
Тестов:           184
Утверждений:      376
Законов:          46 (все CV=0)
Grammar ops:      189
Knowledge Graph:  321 фактов
Generated:        93 модуля
Строк production: ~3500
```
