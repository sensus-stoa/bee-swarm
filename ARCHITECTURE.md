# ExoCortex AGI Roj — Спецификация v2.0

> 03.07.2026 | Архитектор: Dolgov Evgeniy V. | Co-Architect: Hermes
> 230 тестов, 481 assertion, 61 закон, 5000-словный корпус

---

## 0. Принцип

```
ОДИН механизм: вариация + CV→0 + среда = эволюция.
На всех слоях. Без исключений.
```

CV = σ/μ → 0. Закон = выражение, делающее ВСЕ отношения данных константой.
CV>0.5 = хаос, не тратить ресурс. CV→∞ = anti-структура, уходить.

**НЕ LLM. НЕ ML. НЕ статистика.** Символьный поиск.

---

## 1. Слои (7)

### Слой 0: Среда (дана)
| Компонент | Что | Статус |
|-----------|-----|--------|
| Атомы (числовые) | `get_defined_functions()` → 27 шт | ✅ |
| Атомы (семантические) | `is_a`, `has`, `relates_to`, `can` в Grammar | ✅ |
| Атомы (текстовые) | CorpusVocabulary, SentenceRegistry | ✅ |
| Данные | metrics.jsonl, 473 .md Obsidian, код | ✅ |
| Ресурсы | ResourceGuard v2: `/proc/self/stat`, CPU≤50% | ✅ |
| Forager | Сканер с evolving стратегиями, KG-вставка | ✅ |

### Слой 1: Законы (Discover)
```
Задачи × Атомы → CV→0 → grammar_ops
```
| Механизм | Статус |
|----------|--------|
| `AtomRegistry::discover()` | ✅ |
| `AtomRegistry::discoverCompose()` | ✅ |
| `knownLaws` + preload из БД | ✅ |
| Strict threshold (CV ≤ 0.01) | ✅ |
| Алфавит из среды — 0 curated | ✅ |

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
Текст → Forager(regex) → knowledge_graph (факты + confidence)
     → is_a атом → CV→0 → закон
```
| Механизм | Статус |
|----------|--------|
| Discovery → `solves`, `is_a` в KG | ✅ |
| Compose → `composes_with` | ✅ |
| Forager → KG INSERT с confidence | ✅ |
| SemanticVerifier (кросс-валидация) | ✅ |
| LawCompressor (≥3 → meta-law) | ✅ |

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
| Semantic verification | ✅ |
| Law compression | ✅ |
| D/C ratio + Fidelity | ✅ |

### Слой 7: Автономные решения
```
Метрики → selectGoal() → действие → результат
Forager с plateau detection (>20 тиков без открытий)
```

---

## 2. Текстовый слой (новое)

### Cloze-система
```
Текст → CorpusVocabulary (слово→ID, макс 5000) → SentenceRegistry (предложения, макс 1000)
     → cloze-задачи (mask + positive/negative) → CV = error rate
```

### Семантическая грамматика
```
Grammar::SEMANTIC_OPS = ['is_a', 'has', 'relates_to', 'can']
ConceptRegistry: хеш → концепт → knowledge_graph
apply(is_a, Сократ, человек) → SELECT FROM KG → confidence
Compose: and(is_a, is_a) → силлогизм
```

### Forager → KG петля
```
preg_match_is_a → INSERT OR IGNORE (conf=0.3)
Повтор → UPDATE confidence +0.25 (cap 1.0)
4+ источника → confidence=1.0 → is_a атом возвращает 1.0 → CV=0
```

---

## 3. Что НЕ используется
- ❌ MetaInventor, curated atom lists, hardcoded пороги
- ❌ Trust/HTTP/Sandbox для эволюции grammar
- ❌ Нейросети, градиенты, датасеты
- ❌ Пользователь для: модулей, поиска, проверки, целей

---

## 4. Файловая структура

```
.bee_swarm/
├── agenda.php              ← ДЕМОН v5: все 7 слоёв + cloze
├── ARCHITECTURE.md          ← этот файл
│
├── src/
│   ├── Grammar.php              ← BASE_OPS + SEMANTIC_OPS
│   ├── Search.php               ← CV→0 перебор (strict 0.01)
│   ├── AtomRegistry.php         ← apply + discover + compose + loadEnvironment
│   ├── Database.php             ← SQLite singleton (WAL), все таблицы в migrate()
│   ├── ResourceGuard.php        ← v2: /proc/self/stat, CPU≤50%, MEM≤50%
│   ├── Forager.php              ← evolving strategies + KG insert + negative examples
│   ├── ConceptRegistry.php      ← хеш → концепт → knowledge_graph
│   ├── CorpusVocabulary.php     ← слово → ID (макс 5000, ≥3 символа, без цифр)
│   ├── SentenceRegistry.php     ← предложения → ID (макс 1000)
│   ├── LawVerifier.php          ← H1: верификация + прунинг
│   ├── LawCompressor.php        ← кластеризация → meta-law (*)
│   ├── SemanticVerifier.php     ← кросс-валидация фактов
│   ├── PhenotypeManager.php     ← мутация + отбор фенотипа
│   ├── SelfLearningBee.php      ← knowledge_graph + CV→0 валидация
│   ├── DataSelfGenerator.php    ← tasks из metrics.jsonl
│   ├── CellBee.php              ← пчела-клетка
│   ├── DensityHive.php          ← density-based routing
│   ├── EcoHive.php              ← эко-рой с коалициями
│   ├── MetaInventor.php         ← изобретение констант/операций
│   ├── ExpressionTree.php       ← dynamic dispatch
│   ├── AutonomousAgent.php      ← /decide
│   ├── ParadigmSwarm.php        ← изоляция парадигм
│   ├── ParadigmHypothesis.php   ← генератор гипотез
│   ├── ParadigmValidator.php    ← валидация парадигм
│   ├── SwarmSpawner.php         ← spawn дочерних роёв
│   ├── PersistentHive.php       ← персистентный рой
│   ├── SelfOptimizer.php        ← оптимальные действия
│   ├── SelfFeedingGenerator.php ← пул шаблонов, self-feed
│   ├── SelfRewriter.php         ← оптимизация Search
│   ├── DarwinLoop.php           ← поколенческий цикл
│   ├── HypothesisGenerator.php  ← генератор гипотез
│   ├── HypothesisTester.php     ← тестирование гипотез
│   ├── DataRequestor.php        ← авто-запросы данных
│   ├── ArchitectProxy.php       ← прокси для изменений кода
│   ├── ConceptualPatcher.php    ← концептуальные патчи
│   ├── LawWatchdog.php          ← валидация законов
│   ├── worker.php               ← RoadRunner HTTP handler
│   └── Generated/               ← 93 автосгенерированных модуля
│
├── tests/                    ← 36 файлов, 230 тестов, 481 assertion
│   ├── AtomRegistryTest.php        (18)
│   ├── EnvironmentAlphabetTest.php (7)
│   ├── DiscoveryLoopTest.php       (5)
│   ├── ResourceGuardTest.php       (7)
│   ├── ResourceGuardCpuTest.php    (4)
│   ├── PhenotypeEvolutionTest.php  (6)
│   ├── LawVerificationTest.php     (5)
│   ├── LawCompressionTest.php      (3)
│   ├── SemanticLayerTest.php       (4)
│   ├── SemanticVerificationTest.php(4)
│   ├── SemanticFalsificationTest.php(5)
│   ├── SemanticGrammarTest.php     (5)
│   ├── CollapsedTextSystemTest.php (5)
│   ├── CorpusVocabularyTest.php    (5)
│   ├── CorpusSizeLimitTest.php     (4)
│   ├── ClozeGeneratorTest.php      (5)
│   ├── ForagerIntegrationTest.php  (6)
│   ├── ForagerOutputTest.php       (4)
│   ├── ForagerEvolutionTest.php    (6)
│   ├── ForagerKnowledgeGraphTest.php(3)
│   ├── ForagerRealDataTest.php     (4)
│   ├── SelfCodingTest.php          (6)
│   ├── SelfRegulationTest.php      (5)
│   ├── StrategyEvolutionTest.php   (4)
│   ├── StrictThresholdTest.php     (4)
│   ├── DaemonEfficiencyTest.php    (4)
│   ├── KnownLawsPreloadTest.php    (3)
│   ├── AutonomousDecisionsTest.php (5)
│   ├── ComposePruningTest.php      (4)
│   ├── KnowledgeGraphTest.php      (...)
│   └── ...
│
├── data/
│   └── swarm.db              ← SQLite, 8 таблиц
│
├── logs/
│   └── agenda.log            ← лог демона
│
├── start.sh                  ← @reboot cron
├── watch.sh                  ← мониторинг
└── autocommit.sh             ← git auto-commit
```

---

## 5. База данных (SQLite, WAL)

| Таблица | Назначение |
|---------|-----------|
| laws | Законы (name, formula, cv, domain) |
| grammar_ops | Операции (name, source, invented_at, definition) |
| knowledge_graph | Семантический граф (subject, predicate, object, confidence) |
| hive_state | Состояние роя |
| action_pool | Пул шаблонов |
| conscious_state | Аттракторы |
| conscious_events | История событий |
| data_requests | Авто-запросы |

---

## 6. Демон (agenda.php v5)

### Цикл
```
1. ResourceGuard::guard() — процессный CPU через /proc/self/stat
2. Exponential backoff при плато (2^n, cap 3.2s)
3. getTasks() — метрики + базовые + generated + foraged + cloze
4. Случайная задача → CLOZE / DISCOVER / COMPOSE / Search::find (throttled)
5. Plateau (>20 тиков) → Forager сканирует + auto-expand
6. Новый закон → preload в knownLaws
```

### Ключевые константы
| Параметр | Значение |
|----------|---------|
| CPU limit | 50% |
| Backoff cap | 3.2s |
| Forager plateau | >20 тиков |
| Search::find interval | раз в 10 тиков |
| Cloze threshold | error < 0.7 |
| Corpus max words | 5000 |
| Corpus max sentences | 1000 |

---

## 7. HTTP API (RoadRunner, порт 8765)

| Метод | Путь | Назначение |
|-------|------|-----------|
| GET | /status | Грамматика, законы |
| GET | /talk?q=... | Русский диалог |
| GET | /introspect | Само-описание |
| GET | /decide | Автономное решение |
| GET | /density | Тик density-роя |
| POST | /solve | Поиск закона |
| POST | /domain | Поиск в домене |
| POST | /learn | Обучение факту |
| POST | /validate-fact | CV-валидация |
| POST | /route | Роутинг по парадигмам |
| ... | ... | (+15 эндпоинтов) |

---

## 8. Ключевые цифры

```
Слоёв:            7
Тестов:           230
Assertions:       481
Законов:          61
Grammar ops:      192
Knowledge Graph:  385 фактов
Generated:        93 модуля
Corpus:           5000 слов, 1000 предложений
Строк production: ~5000
```

---

## 9. Принципы (кровью)

1. CV→0 — единственный критерий. CV>0.5 = хаос.
2. Меньше — лучше. Лишние модули создают шум.
3. Грамматика растёт через self-apply + compose. Не MetaInventor.
4. Голод вместо таймеров: система реагирует на СВОЁ состояние.
5. Штраф за тривиальность: константа energy 0.03, сложная формула 0.15.
6. Поппер: задача без отрицательных примеров = не фальсифицируема.
7. TDD: тест → код → демон. Всегда.
8. Forager → KG → is_a → CV→0: замкнутая семантическая петля.
9. Corpus limits: 5000 слов, 1000 предложений — иначе подвесит.
10. Эйнштейн: просто насколько возможно, но не проще. 6 компонентов → 3.
