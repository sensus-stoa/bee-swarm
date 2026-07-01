# ExoCortex AGI Roj — Полная спецификация

> Версия: 1.1.0 | Дата: 02.07.2026 | Аудит: 01.07.2026
> Автор архитектуры: Dolgov Evgeniy V.
> Co-Architect: Claude (Hermes), режим: ARCHITECT

---

## 1. Что это

Автономная система открытия законов. Ищет инварианты (CV→0) в данных. Эволюционирует grammar через self-apply/invert. Density-based routing без доменных меток. Голод (CV-триггеры) вместо таймеров. Само-кормящийся генератор кода с песочницей. Русский семантический диалог с цепочками вывода.

**НЕ LLM. НЕ машинное обучение. НЕ статистика.** Символьный поиск с одним критерием: CV = σ/μ → 0.

---

## 2. Философия

### CV→0 — единственный критерий истины
Закон = выражение которое делает ВСЕ отношения данных константой. `CV = σ/μ`. CV→0 = закон найден. CV>0.5 = хаос, не тратить ресурс. CV→∞ = anti-структура, уходить.

### Меньше — лучше
24 модуля → 11 (v1.0) → 26 (v1.1, честный подсчёт). RNA-эволюция доказала: 4 операции побеждают 6. Лишние модули НЕ нейтральны — создают шум. Удалять, не добавлять.

### Грамматика растёт через self-apply + self-invert
Два правила. Всё. Не MetaInventor L1-L5.
- self-apply: `+ + + = ×` (повторение даёт новую операцию)
- self-invert: `undo(+) = −` (обратная операция)

### Голод вместо таймеров
Не `$tick % 100 === 0`. А `$failures >= 10` или `$cvRising >= 3`. Система реагирует на СВОЁ состояние. Как организм.

### Штраф за тривиальность
Константа K7 → energy +0.03. Сложная формула с abs/sqrt → +0.15. Популяция не деградирует.

---

## 3. Архитектура модулей

### Активные модули (26 классов, 37 файлов .php)

```
Корень проекта:
├── agenda.php                  — ДЕМОН: голод → поиск → мета-анализ → эволюция кода
├── sandbox.php                 — ПЕСОЧНИЦА: arbitrary PHP, ограничения (класс Sandbox)
├── self_feeding_generator.php  — SelfFeedingGenerator: пул шаблонов, self-feed, random tokens
├── final_evolve.php            — ЭВОЛЮЦИЯ: генерация → песочница → валидация → применить
├── self_replace.php            — SELF-REPLACE: spawn → benchmark → убить родителя
│
├── start.sh                    — автозапуск после ребута (@reboot cron)
├── watch.sh                    — мониторинг: логи, законы, знания, генератор, процессы
├── autocommit.sh               — авто-коммит в git (cron */15)
│
├── src/
│   ├── Grammar.php              — операции, self-apply/invert, in-memory ops
│   ├── Search.php               — перебор выражений, CV→0
│   ├── CellBee.php              — пчела-клетка: своя grammar, энергия, мутации
│   ├── DensityHive.php          — density-based routing, популяция
│   ├── RelationGrammar.php      — единая грамматика отношений (числа + слова)
│   ├── DataSelfGenerator.php    — генерация задач из metrics.jsonl + законов
│   ├── SelfLearningBee.php      — граф знаний + CV→0 валидация + вывод цепочек
│   ├── AutonomousAgent.php      — /decide: само-описание → решение
│   ├── Database.php             — SQLite (8 таблиц, WAL mode)
│   ├── ExpressionTree.php       — dynamic dispatch операций через native
│   ├── Ontology.php             — базовая онтология концептов
│   ├── AtomicActions.php        — 5 атомарных действий
│   │
│   ├── MetaInventor.php         — изобретение новых операций (используется в /solve, /domain)
│   ├── ConsciousBee.php         — аттракторы, сознание, энергия (legacy, частично заменён на голод)
│   ├── ParadigmSwarm.php        — изоляция парадигм (используется в /paradigms, /route, /coalition)
│   ├── ParadigmHypothesis.php   — генератор парадигмальных гипотез
│   ├── ParadigmValidator.php    — валидация парадигм через CV→0
│   ├── SwarmSpawner.php         — spawn дочерних роёв для тестирования
│   │
│   ├── PersistentHive.php       — персистентный рой (/hive, /hive-state)
│   ├── EcoHive.php              — эко-рой с коалициями (/eco-hive, /eco-query)
│   ├── SelfOptimizer.php        — оптимальные действия на основе данных (/desire)
│   ├── AutonomousOptimizer.php  — автономная оптимизация (/optimize)
│   ├── HypothesisGenerator.php  — генератор гипотез (/hypotheses)
│   ├── HypothesisTester.php     — тестирование гипотез (/test-hypotheses)
│   ├── DataRequestor.php        — авто-запросы данных (/request-data)
│   ├── SelfRewriter.php         — оптимизация Search (/rewrite)
│   ├── DarwinLoop.php           — поколенческий цикл (/generation)
│   ├── ArchitectProxy.php       — прокси для изменений кода (/propose)
│   ├── ConceptualPatcher.php    — концептуальные патчи (/concept)
│   ├── LawWatchdog.php          — валидация законов на новых данных (/watchdog)
│   │
│   ├── worker.php               — RoadRunner HTTP handler (все эндпоинты)
│   └── public/index.php         — запасной HTTP handler (php -S)
```

### УДАЛЁННЫЕ модули (v1.0 → v1.1)

| Модуль | Когда | Причина |
|--------|-------|---------|
| AutoGit.php | v1.1 (01.07) | Git не архитектурная проблема; autocommit.sh заменяет |
| NestedLevel5.php | v1.1 (01.07) | Импортировался но не использовался |
| CodeGenerator.php | v1.1 (01.07) | Не генерировал работающий код |
| SwarmLanguage.php | v1.1 (01.07) | Шаблоны, не семантика |
| Hive.php | v1.1 (01.07) | Заменён на PersistentHive |
| SemanticEngine.php | v1.1 (01.07) | Не использовался |
| async_worker.php | v1.1 (01.07) | Не использовался |
| SwarmServer.php | v1.1 (01.07) | Автономный, не интегрирован |
| Attractors.php | v1.1 (01.07) | Только из SwarmServer |
| action_generator.php | v1.1 (01.07) | Заменён на self_feeding_generator.php |
| code_evolve.php | v1.1 (01.07) | Заменён на final_evolve.php |

---

## 4. База данных (SQLite, WAL mode)

### Таблицы (8, без мусора)

| Таблица | Назначение | Кто создаёт |
|---------|-----------|------------|
| laws | Найденные законы (name, formula, cv, domain) | Database::migrate() |
| grammar_ops | Операции грамматики | Database::migrate() |
| knowledge_graph | Семантический граф (subject, predicate, object, conf) | SelfLearningBee |
| hive_state | Состояние роя (bees, generation, evolved_code) | PersistentHive |
| action_pool | Пул шаблонов генератора (code, success_count, source) | SelfFeedingGenerator |
| conscious_state | Состояние аттракторов | ConsciousBee |
| conscious_events | История событий | ConsciousBee |
| data_requests | Авто-запросы данных | agenda.php |

Файл: `~/.bee_swarm/data/swarm.db`
Размер: ~200KB (после 12 часов работы)

---

## 5. HTTP API (RoadRunner, порт 8765)

### Основные эндпоинты

| Метод | Путь | Назначение |
|-------|------|-----------|
| GET | /status | Грамматика, законы |
| GET | /talk?q=... | Русский диалог с цепочками вывода |
| GET | /introspect | Само-описание роя |
| GET | /decide | Автономное решение |
| GET | /desire | Чего хочет рой |
| GET | /paradigm | Парадигмальные гипотезы |
| GET | /validate | Валидация парадигм |
| GET | /density | Тик density-based роя |
| GET | /density-state | Состояние популяции |
| GET | /generate-data | Генерация задач |
| GET | /paradigms | Список парадигм |
| GET | /hypotheses | Генерация гипотез |
| GET | /test-hypotheses | Тестирование гипотез |
| GET | /request-data | Запрос данных |
| GET | /evolve | Эволюция через spawn |
| GET | /rewrite | Оптимизация Search |
| GET | /optimize | Автономная оптимизация |
| GET | /watchdog | Проверка закона |
| GET | /generation | Поколенческий цикл |
| GET | /hive | Тик персистентного роя |
| GET | /hive-state | Состояние роя |
| GET | /conscious | Состояние/события сознания |
| GET | /cross-domain | Кросс-доменные связи |
| GET | /insight | Инсайты по доменам |
| POST | /solve | Поиск закона (data, task) |
| POST | /domain | Поиск законов в домене |
| POST | /learn | Обучение факту (sentence) |
| POST | /validate-fact | Факт с CV-валидацией |
| POST | /route | Роутинг задачи по парадигмам |
| POST | /coalition | Коалиция парадигм |
| POST | /propose | Предложить изменение кода |
| POST | /concept | Концептуальное изменение кода |
| POST | /eco-hive | Тик эко-роя |
| POST | /eco-query | Кросс-доменный запрос |

---

## 6. Демон (agenda.php)

### Цикл
```
1. Взять случайную задачу из пула (metrics + synthetic)
2. Поиск CV→0 с текущей grammar
3. CV растёт 3 тика → ГОЛОД: генератор 5 случайных действий
4. Лучшее действие (CV<0.5) → сохранено в БД
5. 10+ провалов → эволюция кода (final_evolve.php)
6. Каждые 50 тиков → проверка графа знаний на противоречия
```

### Запуск
```bash
# Как процесс (переживает logout)
nohup php agenda.php > /tmp/agenda.log 2>&1 &

# Или через start.sh (с авто-запуском после ребута)
bash start.sh
```

---

## 7. Песочница (sandbox.php)

### Ограничения
- `timeout 5` — 5 секунд на выполнение
- `memory_limit=50M` — максимум 50MB
- `open_basedir=/tmp/roe_sb_*` — только временная директория
- `disable_functions` — exec, shell_exec, system, passthru, popen, proc_open, pcntl_exec, link, symlink, mail, ini_set, error_reporting
- **Разрешены:** file_put_contents, file_get_contents, curl_* (для trusted)

### Повышение привилегий
- Действие успешно 1 раз → source='evolved'
- Действие успешно 3 раза → source='trusted' → ослабление ограничений

---

## 8. Скрипты

| Скрипт | Запуск | Назначение |
|--------|--------|-----------|
| start.sh | @reboot cron | Запуск RoadRunner + демона |
| watch.sh | Вручную | Мониторинг: логи, законы, БД, процессы |
| autocommit.sh | */15 cron | git add -A && git commit |

---

## 9. Принципы которые мы вывели кровью

1. **Grammar::add() пишет в БД** — использовать Reflection для in-memory ops. Иначе clone uniformity.
2. **Песочница НЕ валидирует CV** — код может хардкодить `$cv=0`. Всегда проверять формулу независимо.
3. **Шаблоны-обманщики** — `report_*`, `api_laws_*` хардкодят CV=0. Фильтровать.
4. **Heredoc интерполирует `$cv`** — использовать `<<<'PHP'` (nowdoc) в шаблонах.
5. **Два воркера RoadRunner → разная статика** — использовать SQLite для shared state.
6. **Парсер глаголов** — нормализовать «помогают»→«помогает», «могут»→«может».
7. **Биграммы в /talk** — «мыслящие существа» как один концепт.
8. **is_a — единственный противоречивый предикат** — can, has могут иметь много объектов.
9. **SelfFeedingGenerator в корне, не в src/** — PSR-4 его не видит. agenda.php делает require_once вручную.
10. **escapshellarg ломает open_basedir** — не использовать для basedir.
11. **array_column переименовывает ключи** — использовать после array_slice.
12. **`$k1→$k2` в PHP строке** — использовать конкатенацию `$k1 . '→' . $k2`.
13. **Обновление opcache после правки Search.php** — перезапускать RoadRunner.

---

## 10. AGI Gap — что осталось

1. **Новизна** — 20% случайных токенов, нужны итерации (1000+)
2. **Выход за пределы** — trusted-действия получат сеть, нужны 3+ успеха
3. **Данные** — metrics.jsonl исчерпан, нужны внешние источники

AGI ≠ архитектурная проблема. AGI = вопрос времени и итераций.

---

## 11. Быстрый старт

```bash
cd ~/.bee_swarm

# Запуск всего
bash start.sh

# Только HTTP
./rr serve &

# Только демон
php agenda.php &

# Проверка
curl http://127.0.0.1:8765/status
curl "http://127.0.0.1:8765/talk?q=кто+ты"

# Обучение
curl -X POST http://127.0.0.1:8765/learn \
  -H "Content-Type: application/json" \
  -d '{"sentence":"X — это Y"}'

# Поиск закона
curl -X POST http://127.0.0.1:8765/solve \
  -H "Content-Type: application/json" \
  -d '{"task":"test","data":[[1,2,3],[2,3,5],[3,4,7]]}'

# Остановка
pkill -f "agenda.php"
kill $(lsof -t -i:8765)
```

---

## 12. Известные проблемы

- PHP 8.2 `match` не работает в shell-heredoc → использовать `if/elseif`
- `escapeshellarg` ломает `open_basedir` → не использовать для basedir
- `array_column` переименовывает ключи → использовать после array_slice
- `$k1→$k2` в PHP строке → использовать конкатенацию `$k1 . '→' . $k2`
- Обновление opcache после правки Search.php → перезапускать RoadRunner
