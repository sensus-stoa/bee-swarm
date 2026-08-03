# V0.10 — HiveWatcher: структурный мониторинг улья

## Статус: ⬜

## Проблема

Сейчас мониторинг — это `tail -f logs/agenda.log`. Пользователь видит поток строк:
`ROUTE: task -> bee#2`, `DEATH: bee#1 energy=0`, `PRE_FILTER: skipped 6...`

Нет агрегации. Нет истории. Нет трендов. Чтобы узнать сколько законов открыто —
нужно лезть в SQLite вручную. Чтобы понять динамику смертей/рождений —
нужно grep'ать логи и считать.

## Что нужно

Структурный вотчер который агрегирует ключевые метрики улья в реальном времени
и экспортирует их в машиночитаемом формате (JSONL, как metrics.jsonl).

### Метрики
- **Жизнь**: deaths, spawns, generation, population, diversity
- **Открытия**: total_laws, laws_by_domain, discoveries_per_hour, plateau_status
- **Энергия**: mean_energy, energy_variance, starvation_events
- **Грамматика**: mean_grammar_size, unique_grammars, new_atoms_per_gen
- **Overlap**: measured_pairs, agreements, disagreements
- **Система**: uptime, ticks_per_second, memory_usage, peak_memory, log_errors
- **Память (добавлено 04.08)**: memory_usage, peak_memory, oom_warnings
  - Критично после V0.8.5: cross-pair создаёт O(N²) txt_pair задач
  - 800 атомов → 640K задач → ~1GB RAM. При 2000 атомах → 4M задач → OOM.
  - Вотчер должен слать alert при memory_usage > 2GB или росте >100MB/min

## Фазы

### Phase 0: Тактический MEM-лог (✅ 04.08)
- Уже в коде: `MEM: tick=N mem=XMB peak=YMB` каждые 100 тиков
- `MEM_FIRST_TASKS` после первой генерации задач (момент cross-pair взрыва)
- `Forager startup: N tasks, mem=XMB peak=YMB`
- Это временный мост до полноценного HiveWatcher

### Phase 1: MetricsCollector (1.5h)
- Класс `HiveWatcher` в `src/Hive/`
- Собирает метрики из Hive на каждом тике
- Пишет в `data/metrics.jsonl` (append-only, одна строка = один тик)
- Формат: `{"ts":"2026-08-03T14:31:00","tick":1234,"deaths":6,"spawns":0,...}`

### Phase 2: Trends analysis (1h)
- `HiveWatcher::trends()` — агрегация за последние N тиков
- Скользящее среднее для ключевых метрик
- Обнаружение аномалий: sudden death spike, grammar explosion, energy collapse

### Phase 3: Вывод для пользователя (0.5h)
- `hive-watch` CLI команда: `php hive-watch.php --live`
- Показывает dashboard в терминале (как htop, но для улья)
- Или JSON-эндпоинт для интеграции с ExoCortex

### Phase 4: Интеграция с verify-скриптами (0.5h)
- verify-скрипты читают metrics.jsonl вместо grep'анья логов
- `verify_1_2` (spawns) → читает `spawn_count` из metrics

## Сложность: ⭐⭐⭐ | 3.5h
