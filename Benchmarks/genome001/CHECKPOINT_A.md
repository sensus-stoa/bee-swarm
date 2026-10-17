# GENOME-001 — CHECKPOINT A: ОТЧЁТ

Дата: 15.10.2026. Сессия-преемник HANDOFF (PROBE-0 → PROBE-1 → MOL-001 → GENOME-001).
HEAD: c807b11. Изменения в рабочем дереве (НЕ закоммичены — на ревью owner).

## 0. Вердикт

**CHECKPOINT A: PASS** — минимальный кодек реализован, все тесты §2 инструкции
зелёные, семантика не нарушена. STOP соблюдён: компрессионные измерения (§3-§5)
НЕ проводились (кроме черновой сводки размеров для артефакта).

## 1. Что сделано

### Файлы (5 новых + 1 гейт)

| Файл | Строк | Назначение |
|------|-------|------------|
| `src/Core/WordCodec.php` | 809 | Кодек: encode/decode/decodeExpanded, словарь, bundle, БД-мосты |
| `src/Core/WordCodecException.php` | 14 | Явный отказ (unknown/cycle/arity/malformed) |
| `tests/GenomeCodecTest.php` | 543 | 17 тестов / 154 assertions (GC-1…GC-7) |
| `scripts/genome001/decode_execute.php` | 68 | Subprocess-раннер чистой линии (H1 cross-process) |
| `scripts/genome001/make_families.php` | 68 | Генератор freeze-артефакта из реальных T₁/T₂ |
| `scripts/run_two_pass.sh` | +1 | TOTAL_EXPECTED 1267 → 1284 (гейт TotalExpectedInvariantTest) |

НЕ тронуто (граница HANDOFF): Search, applyN, bornBinary, механизмы рождения,
protect(), ExpressionNormalizer/Evaluator/Registry.

### Дизайн кодека (ключевые решения)

1. **Геном = JSON-дерево** над AST ExpressionNormalizer, не над строками:
   - `{atom}` — лист; `{op,l,r}` — операторный узел (born-op application
     проходит НАСКВОЗЬ байт-точно — faithful pass-through);
   - `{word,args}` — ссылка; возникает ТОЛЬКО при pattern-match поддерева
     против definition статьи словаря.
2. **Словарь** = `{name, arity, genome}` в порядке зависимостей; definition
   сами кодируются против предыдущих статей → T₂→T₁ и разделяемые
   подвыражения дают по одной статье (тест GC-4: 4 статьи, W2 сжался до
   одной ссылки S1(S1(x0,x1),S2(x0,x1))).
3. **Decode** — разворачивание ссылок одновременной подстановкой слотов
   (xN → args[N], та же конвенция, что evalNode row[N] у born-операторов),
   capture-free (аргументы не ре-обходятся), active-set против циклов.
   Строки не пере-парсятся → структурная эквивалентность построением;
   допустимое отклонение — порядок коммутативных операндов (рамка §3 HANDOFF).
4. **decodeExpanded()** дополнительно раскрывает born-op application известного
   слова → чистый AST базовой грамматики. Арность ≠2 → **явный отказ**:
   движок на этом месте молча подставлял бы x2→0 (Link2 FAIL,
   [FACT: ExpressionEvaluator.php:282]); кодек повторять дефект не имеет права.
5. **БД-мосты**: `dictionaryFromDb()` (birth-атомы по id), `registerDictionary()`
   (INSERT birth + сброс stale-кэша birthOpNames — питфолл 3 HANDOFF убит
   конструкцией), `serializeBundle()/loadBundle()` (format genome001, v1).

## 2. Тесты (17/17 GREEN, 154 assertions, 0.16s fast)

| # | Тест | Что фиксирует |
|---|------|---------------|
| GC-1a | roundtrip T₁ exact | T₁ → 1 ссылка → точное дерево |
| GC-1b | roundtrip T₂ definition exact | вложенный T₁-application сохранён, арность 3 |
| GC-1c | roundtrip multi-level applications | application-узлы проходят насквозь; исполнение НЕ проверяется (арность-3 application инертна в движке — это граница движка, не кодека) |
| GC-1d | пустой словарь = lossless passthrough | геном ≡ AST без словаря |
| GC-5 | закон D₂ → 1 ссылка | каноническая эквивалентность (детей-порядок зеркален — задокументировано, не спрятано) |
| GC-2a | численная эквивалентность | decoded vs оракул vs original, 12 строк, 1e-9 |
| GC-2b | zero-denominator | x0==x1 → null на обоих деревьях; (5,3,5) валиден |
| GC-3a | variable hygiene | атомы decoded — только ^x\d+$, склеек BW нет |
| GC-3b | rename/permute binding | форма A на Xa ≡ форма B на Xb после архивации |
| GC-4 | вложенность + shared | T₂ deps=[T₁]; 4 статьи; W2 = 1 ссылка |
| GC-5a | чистая БД in-process | bundle → temp.sqlite → decode+execute ≡ оракул |
| GC-5b | чистая БД SUBPROCESS | bundle+геном → новый процесс → значения закона (H1 cross-line) |
| GC-5c | subprocess отказ unknown word | ok=false, диагноз 'unknown word' |
| GC-6a | unknown word in-process | decode И decodeExpanded отказывают |
| GC-6b | циклы | {word}-цикл A↔B, self-ref, определенческий цикл через born-op application (validateDictionary) |
| GC-6c | arity mismatch | ссылка 2≠3 отказывает; T₂-application бинарной формы отказывает (точка молчаливого x2→0) |
| GC-7 | полное раскрытие без БД | decodeExpanded без birth-атомов в grammar_ops → исполнение ≡ оракул; рендер `((x0−x2)+((x0+x1)/(x0−x1)))` |

### Расхождения с текстом HANDOFF (зафиксированы)

- **Имя T₂**: HANDOFF §1 пишет `BWcfe6b0cfe523fbf6b6c6f0c49cf372c0`;
  артефакт `g2_B_r1.json` и MolRoundtripTest — `BW59baeae13abe8cbdfae0c823ff207db2`.
  Взят код MolRoundtripTest (коммит MOL-001) + артефакт — они согласованы.
  Имя — опак-токен, на семантику кодека не влияет.
- **Definition T₂ в g2_B_r1.json**: `((x0−x1)+…)` — это pre-fix искажённый
  канон (rename-порча, починена в MOL-001). Использован post-fix канон
  `((x0−x2)+x0BW_T₁x1)` из MolRoundtripTest. Арность T₂ = 3 (x0,x1,x2) —
  совпадает с текстом HANDOFF («молекула арности 3»), против поля arity:2
  в артефакте.
- **Гейт suite**: экран полного прогона — ниже (заполнено по завершении).

## 3. Черновая сводка размеров (НЕ Checkpoint B — только для артефакта)

Словарь {T₁,T₂}: 1447 B (JSON). Одинаковые функции:

| Программа | AST узлов | AST JSON | Геном узлов | Геном JSON |
|-----------|-----------|----------|-------------|------------|
| law_x (D₂) | 7 | 147 B | 4 | 96 B |
| t1_plain | 7 | 115 B | 3 | 82 B |
| t2_definition | 7 | 147 B | 4 | 96 B |

Break-even словаря ≈ 28 программ (1447/51). Реальные метрики §5 (амортизация,
REGULAR/RANDOM/REAL, gzip-контроль) — Checkpoint B на замороженных наборах.

## 4. Питфоллы сессии (новые)

1. **render() скобует born-op application**: definition в чистой БД
   восстанавливается как `((x0−x2)+(x0BW_T₁x1))` (скобочная форма), не
   байт-в-байт со склеенной строкой. Инвариант — СТРУКТУРНЫЙ
   (parse(render(AST)) ≡ AST по MOL-001), тест GC-5a проверяет именно его.
   Для байт-точности пришлось бы хранить исходную строку в статье — не делал
   (избыточно при доказанном parse≡).
2. **entryDefAst и expandApps**: definition статьи при encode должен
   оставаться faithful (паттерн-матчер), при decodeExpanded — раскрываться
   рекурсивно. Ловится смоук-тестом (первый прогон дал containsApps=true).
3. **psalm-шейпы**: entry с ленивым 'def' → `def?: array` во ВСЕХ docblock
   сразу, иначе 8 InvalidReturnType. Решение — консистентный опциональный ключ.

## 5. Ограничения (честно)

- Кодек не ускоряет и не улучшает Search — это архивация (§7 HANDOFF).
- Арность-3 application в AST сохраняется faithfully, но в движке остаётся
  инертной (Link2) — fix-кандидат applyN-wiring вне GENOME-001.
- Matcher жадный (largest-first, детерминизм по имени) — оптимальность
  покрытия поддеревьями не доказывалась и не нужна для Checkpoint A.
- Полный suite гонялся на ноутбуке (desktop-совместимость теста — fast 0.16s,
  но гейт канона — two-pass на ноуте).
