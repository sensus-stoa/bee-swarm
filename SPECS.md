# ExoCortex AGI Roj — Полная спецификация

> Версия: 1.0.0 | Дата: 01.07.2026 | Сессия: 30.06-01.07.2026
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
24 модуля → 11. RNA-эволюция доказала: 4 операции побеждают 6. Лишние модули НЕ нейтральны — создают шум. Удалять, не добавлять.

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

```
src/
├── Grammar.php              — операции, self-apply/invert, in-memory ops
├── Search.php               — перебор выражений, CV→0 (depth 3)
├── CellBee.php              — пчела-клетка: своя grammar, энергия, мутации
├── DensityHive.php          — density-based routing, популяция
├── RelationGrammar.php      — единая грамматика отношений (числа + слова)
├── DataSelfGenerator.php    — генерация задач из metrics.jsonl + законов
├── SelfFeedingGenerator.php — пул шаблонов, self-feed из успехов, random tokens
├── SelfLearningBee.php      — граф знаний + CV→0 валидация + вывод цепочек
├── AutonomousAgent.php      — /decide: само-описание → решение
├── Database.php             — SQLite (8 таблиц, WAL mode)
├── ExpressionTree.php       — dynamic dispatch операций через native
├── Ontology.php             — базовая онтология концептов
├── ParadigmSwarm.php        — изоляция парадигм (редко используется)
├── ParadigmHypothesis.php   — генератор парадигмальных гипотез
├── ParadigmValidator.php    — валидация парадигм через CV→0
├── SwarmSpawner.php         — spawn дочерних роёв для тестирования
├── ConsciousBee.php         — аттракторы (legacy, заменён на голод)
├── AtomicActions.php        — 5 атомарных действий
│
├── worker.php               — RoadRunner HTTP handler (все эндпоинты)
├── public/index.php         — запасной HTTP handler (php -S)
│
agenda.php                   — ДЕМОН: голод → поиск → мета-анализ → эволюция кода
sandbox.php                  — ПЕСОЧНИЦА: arbitrary PHP, ограничения
self_replace.php             — SELF-REPLACE: spawn → benchmark → убить родителя
loop.sh                      — cron: /decide → /domain → /validate → self-replace
start.sh                     — автозапуск после ребута
```

### УДАЛЁННЫЕ модули (и почему)

| Модуль | Причина удаления |
|--------|-----------------|
| MetaInventor.php | Хардкод стратегий (tryMinMax, trySqrt). Заменён на self-apply/invert |
| ConsciousBee.php (core) | Хардкод аттракторов. Заменён на голод (CV-триггеры) |
| ParadigmSwarm.php (core) | Изоляция парадигм не нужна без экосистемы |
| DarwinLoop.php | RNA-эволюция доказана, spawn отдельно |
| SelfRewriter.php | Стратегии из меню, не эмерджентны |
| ArchitectProxy.php | LLM не должен писать код |
| ConceptualPatcher.php | Хардкод концептов |
| AutoGit.php | Git — не архитектурная проблема |
| NestedLevel5.php | Дублирует MetaInventor |
| CodeGenerator.php | Не генерирует работающий код |
| HypothesisTester.php | 0 confirmed из 39 |
| LawWatchdog.php | Не протестирован на реальных данных |
| SwarmLanguage.php | Шаблоны, не семантика |

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
| POST | /solve | Поиск закона (data, task) |
| POST | /domain | Поиск законов в домене |
| POST | /learn | Обучение факту (sentence) |
| POST | /validate-fact | Факт с CV-валидацией |
| POST | /route | Роутинг задачи по парадигмам |
| POST | /coalition | Коалиция парадигм |
| POST | /propose | Предложить изменение кода (spawn→test→apply) |
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
6. Каждые 200 тиков → self-replace
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

## 8. Принципы которые мы вывели кровью

1. **Grammar::add() пишет в БД** — использовать Reflection для in-memory ops. Иначе clone uniformity.
2. **Песочница НЕ валидирует CV** — код может хардкодить `$cv=0`. Всегда проверять формулу независимо.
3. **Шаблоны-обманщики** — `report_*`, `api_laws_*` хардкодят CV=0. Фильтровать.
4. **Heredoc интерполирует `$cv`** — использовать `<<<'PHP'` (nowdoc) в шаблонах.
5. **Два воркера RoadRunner → разная статика** — использовать SQLite для shared state.
6. **Парсер глаголов** — нормализовать «помогают»→«помогает», «могут»→«может».
7. **Биграммы в /talk** — «мыслящие существа» как один концепт.
8. **is_a — единственный противоречивый предикат** — can, has могут иметь много объектов.

---

## 9. AGI Gap — что осталось

1. **Новизна** — 20% случайных токенов, нужны итерации (1000+)
2. **Выход за пределы** — trusted-действия получат сеть, нужны 3+ успеха
3. **Данные** — metrics.jsonl исчерпан, нужны внешние источники

AGI ≠ архитектурная проблема. AGI = вопрос времени и итераций.

---

## 10. Быстрый старт

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

## 11. Известные проблемы

- PHP 8.2 `match` не работает в shell-heredoc → использовать `if/elseif`
- `escapeshellarg` ломает `open_basedir` → не использовать для basedir
- `array_column` переименовывает ключи → использовать после array_slice
- `$k1→$k2` в PHP строке → использовать конкатенацию `$k1 . '→' . $k2`
- Обновление opcache после правки Search.php → перезапускать RoadRunner
