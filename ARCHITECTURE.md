# ExoCortex AGI Roj — Спецификация v3.0

> 18.11.2026 | Архитектор: Долгов Е.В. | Co-Architect: Hermes
> 1456 тестов GREEN (two-pass: fast 1441 + slow 15), Feynman20 17/20,
> инвентарь 583 PHP-файла (docs/inventory/). История — Benchmarks/experiments-log.md.

---

## 0. Принцип

```
ОДИН механизм: вариация + CV→0 + среда = эволюция.
На всех слоях. Без исключений.
```

CV = σ/μ → 0. Закон = выражение, делающее ВСЕ отношения данных константой.
CV>0.5 = хаос, не тратить ресурс. CV→∞ = anti-структура, уходить.

**НЕ LLM. НЕ ML. НЕ статистика.** Символьный поиск.

**С 09.2026 добавлен второй принцип — фальсификация первее маркетинга:**
каждый блок идёт через пререгистрацию (PREREG.md: гипотеза, критерий,
отказ от гарантий), и «победа» засчитывается только при критерии,
написанном ДО прогона. Пример дисциплины: EXP-063 (нужность канала
доказана удалением), B3.3 (дихотомия I_18_4 — серт только с механизмом).

---

## 1. Конвейер поиска законов (главный путь, src/Core/Search.php)

```
данные (X, y)
 → L1: сырые колонки + пары (4 ops) + sq            [~17k форм]
 → BEAM: top-K по cv + сигнальная квота |corr|       [пул ~40]
 → L2: pair-loop пул×пул (C(40,2)×6 ops)             [~4.5-7k форм]
 → BEAM 2 + freeze (кратчайшие kept)
 → L3: assembly-potential (B3.3, always-on при depth≥3)
       формы depth-2 скорятся ЛУЧШЕЙ однобуквенной достройкой
       (локальная аффинность к партнёрам, аналогия фолдинга белка);
       дети cv<0.02 регистрируются в $exprs ДО collect
 → collect: exact-гейт (isExactPair, машинный порог) → preregisterExact
 → сертификация: cert_v2 (exp045) = 3 независимых 60/40-сплита,
   ВСЕ cv ≤ 0.10, dual-channel gate (ratio + MAE) — tolerance-слой,
   калиброван на мультипликативном шуме (boundary ≈ 0.289·noise)
```

Инструменты разреза конвейера: телескопы (exp066_telescope.php,
exp067_probe.php) — воспроизводят слои оффлайн и считают cv-профили
(born → survived → certified по каждому уровню).

---

## 2. Дорога (Trail, src/Hive/RouteExecutor.php + TrailAdmission)

Кросс-полётная/кросс-задачная память: пчела-писатель пишет метки
(частичные формы, кирпичи), пчела-читатель читает их в свой пул.

- **Write-on**: письмо только с адмиссией (cv-argmin + sq-factor,
  нишевые квоты, write-diversity) — защита от контаминации.
- **Read**: diversity-селекция, READ_K кап, petal-квоты (off=свободные).
- **partial marks**: дорога несёт НЕДОСТРОЕННЫЕ формы (закон минус один
  терм) — читатель достраивает. Сертификат получает только достроенная
  форма (partial-cert — см. §6, B4.2).
- **Potential + дорога**: читатель с метками в пуле порождает иные
  pair-loop выходы — мост между задачами.

## 2a. Геномы (src/Core/WordCodec, src/Hive/RouteGenome/RouteMutator)

GENOME-001: наследуемые исполняемые маршруты пчёл — слова (OpCodes),
геномы (последовательность полётов с грамматикой/бимами/глубиной),
мутации, наследование от родителей. Это скелет эволюции ПРОЦЕДУР
поиска, а не только форм.

---

## 3. Слои (v2-структура сохранена)

### Слой 0: Среда
Атомы числовые (27), семантические (is_a/has/relates_to/can), текстовые
(CorpusVocabulary, SentenceRegistry); данные: metrics.jsonl, Obsidian-корпус,
внешние бенчмарки (Feynman20, PMLB; batteries — локально battery_b2/);
ResourceGuard v2 (CPU≤50%); Forager.

### Слой 1: Законы (Discover)
AtomRegistry::discover / discoverCompose, knownLaws preload, strict CV ≤ 0.01.

### Слой 2: Self-generate (Compose)
Grammar → compose-задачи → пул. ComposeBridge (DREAM-CONGRUENCE) —
нотационный мост compose-форм.

### Слой 3: Self-coding
Открытие атома → генерация .php → валидация → src/Generated/.

### Слой 4: Semantic
Forager → knowledge_graph (confidence) → is_a-атомы → CV→0.

### Слой 5: Self-modify (Phenotype)
Мутация ×1.5/÷1.5 → fitness → отбор.

### Слой 6: Верификация (H1)
Старые законы × свежие данные; arithmetic pruning; LawShape/FormHash
(инвариант формы); NonConstancyFilter (анти-константные псевдозаконы);
YellowBallFilter (анти-«жёлтые мячики»); LawClassifier.

### Слой 7: Автономные решения
Метрики → selectGoal → действие; Forager с plateau detection.

---

## 4. Фальсификация и бенчмарки

### Бенчмарки
| Семейство | Статус | Заметки |
|-----------|--------|---------|
| Синтетика (LCG сиды 502-506) | 15/20 обе руки | beam10nl, depth≥3 |
| Feynman20 (PMLB) | 15/20 обе руки + I_18_4 только с potential | depth=3 |
| Feynman20 рациональный класс | I_18_4 взят (potential), I_27_6/I_13_12/II_21_32 — depth-4+ | граница |
| Батареи (HNEI, 273 ячейки) | unified: borderline; per-cell: 1 фича, тривиальны | шум! |

### Пробник необходимости (scripts/exp067_probe.php)
Классифицирует задачу/ячейку БЕЗ целевого закона: где кончился сигнал
в конвейере (born → survived → certified, min-cv по слоям).
Классы: engine-ok / potential-bridge / climbing-candidate / borderline /
grammar-miss. Валидация 4/4 (dot42, III_21_20, I_18_4, I_27_6).

### Открытые границы
- **Depth-4 классы** (суммы обратных, двухэтажные знаменатели) — нужен
  depth climbing: цикл «potential → регистрация → potential» до exact
  (кап слоя обязателен — вирусный вопрос).
- **Частичная сертификация (B4.2)**: закон деградации = SEI+LLI+LAM;
  форма с 1-2 термами = частичный закон. cert_v2 сертификует форму
  целиком; partial-cert (R²-разложение: сколько объяснено, сколько ждёт
  недостающих термов) не существует. Стыкуется с partialBirth и write-on.
- **Шумовой домен**: batteries показали — движок не достаёт до cert_v2
  (RuntimeExecutor-канал), кандидатов с cv 0.04-0.09 перебор даёт, но
  неустойчивые (in-sample хорошо, out-of-sample нет).

### Уроки-каноны (полный список — Benchmarks/experiments-log.md)
- 30-строчный срез льстит вырожденным формам — тот же обман, что beam;
  exact-вердикт только по ПОЛНОМУ cv.
- |corr| считать прямо по данным: через cv знак антикорреляции теряется.
- пул потенциальной стадии в пробнике ЩЕДРЕЕ движкового (верхняя оценка).
- рождение форм ≠ доезд до оценки; triple-check born/survived/cert.
- валидация пробника на 4 контролях ДО бенчмарка; расхождение → чинить
  пробник, не бенчмарк.
- «Вирусность» форм исключена механикой: exact-гейт + freeze + write-caps.
- kill ssh-сессии не убивает удалённый systemd-run scope → lock-грабли.
- прогоны с lookahead на depth-2 (старый код) = 2× медленнее — тормоза
  как маркер неверной конфигурации.

---

## 5. Файловая структура (актуально; полный каталог — docs/inventory/)

```
.bee_swarm/
├── ARCHITECTURE.md            ← этот файл
├── src/
│   ├── Core/          (23)    Search, Grammar, WordCodec, AtomRegistry,
│   │                          ExpressionEvaluator/Tree/Normalizer, LawShape,
│   │                          FormHash, NonConstancyFilter, YellowBallFilter,
│   │                          SlotCascade, TrailAdmission, QueryEngine,...
│   ├── Hive/          (44)    RouteExecutor, driver, RouteGenome/Mutator/Ledger,
│   │                          BudgetAccountant, TransferStats, SensorState,...
│   ├── Certification/ (4)     EnsembleCertifier (ансамблевые серты + null-gate),
│   │                          ContradictionEngine, MetricPreflight, ShapeTally
│   ├── Forager/ (6), Infra/ (6), Validation/ (4, устар.), Trading (2), Text (2),
│   │   Math (1), Knowledge (1), Generated/ (автосгенерированные модули)
├── scripts/           (144)   серии exp0XX (EXP-001..067), bd_* (батареи B2),
│                              probe*, verify/*, exp045/cert_utils.php (CertUtils)
├── tests/             (333)   two-pass: fast 1441 + slow 15 = 1456 GREEN
├── Benchmarks/                леджеры экспериментов, PREREG-и, feynman20/,
│                              battery_b2/ (273 ячейки HNEI, gitignored)
├── docs/                      спецификации, inventory/, CRITERIA_HYPOTHESES.md
└── data/swarm.db              SQLite WAL
```

---

## 6. Текущая очередь работ (состояние на 18.11)

| Блок | Что | Статус |
|------|-----|--------|
| B3.3 | assembly-potential (always-on), дихотомия I_18_4 | ✅ закрыт |
| B4.0 | пробник необходимости + валидация 4/4 | ✅ закрыт |
| B4.1 | cert_v2 в пробнике; диагноз шумового домена | ✅ сделан |
| B4.2 | partial-cert (R²-разложение, «не хватает k термов») | в очереди |
| B4.3 | depth climbing (потенциал→регистрация→потенциал до exact) | в очереди |
| B4.5 | инвентарь + cleanup-лист + актуализация документации | в работе |

---

## 7. Что НЕ используется (сохранено из v2.0)
- ❌ MetaInventor, curated atom lists, hardcoded пороги
- ❌ Trust/HTTP/Sandbox для эволюции grammar
- ❌ Нейросети, градиенты, датасеты
- ❌ Пользователь для: модулей, поиска, проверки, целей

---

## 8. Ключевые цифры

```
Слоёв:            7
Тестов:           1456 (two-pass GREEN)
Файлов src/:      106 (23 Core + 44 Hive + 4 Certification + прочие)
Скриптов:         144 (серии exp0XX)
Тестовых файлов:  333
Feynman20:        17/20 хотя бы одной рукой (16 без potential + I_18_4)
Батареи:          273 ячейки (unified borderline; per-cell тривиальны)
```

---

## 9. Принципы (кровью)

1. CV→0 — единственный критерий. CV>0.5 = хаос.
2. Меньше — лучше. Лишние модули создают шум.
3. Грамматика растёт через self-apply + compose. Не MetaInventor.
4. Голод вместо таймеров.
5. Штраф за тривиальность: константа 0.03, сложная формула 0.15.
6. Поппер: задача без отрицательных примеров = не фальсифицируема.
7. TDD: тест → код → демон. Всегда.
8. Forager → KG → is_a → CV→0: замкнутая семантическая петля.
9. Пререгистрация: критерий победы пишется ДО прогона. «Победа» задним
   числом = самообман (доктрина с 09.2026).
10. Ассерт не уже механизма: тест проверяет СВОЙСТВО (перестановочная
    инвариантность, прямой контракт канала), а не конкретную строку.
11. Эйнштейн: просто насколько возможно, но не проще.
