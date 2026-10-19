#!/bin/bash
# scripts/run_two_pass.sh — канонический прогон suite (V0.17-SLOW-GROUP WU-2)
#
# Пасс 1: fast — paratest -p8 (xml-exclude группы slow).
# Пасс 2: slow — phpunit --group slow СЕРИЙНО (бюджетные тесты
#         контеншн-чувствительны по построению; параллелить = воспроизводить болезнь).
#
# Вердикт = ОБА лога зелёные. Вердикт о завершении прогона — ТОЛЬКО по
# маркерам лога (^OK / ^FAILURES / ^Errors: / ^Tests:), НЕ pgrep (pgrep-f
# self-match). Exit 0 = оба пасса зелёные, exit 1 = красное/не дошло/таймаут.
#
# Защиты (premortem + прогоны 19.09):
#  - setsid nohup: nohup без setsid умирает при закрытии ssh-сессии
#    (2 молчаливые смерти прогонов 19.09, OOM чист).
#  - mkdir лог-директории НА НОУТЕ: редирект фонового запуска падает молча,
#    если её там нет.
#  - singleton: второй экземпляр скрипта не стартует (stale-lock-гвард),
#    иначе два параллельных прогона воюют за воркеры.
#  - max-wait 20 мин/пасс: зависший прогон (watcher не увидел маркер,
#    phpunit не завершился) не превращает скрипт в вечный.
#  - парсинг счётчика по ^Tests: N («OK (N tests...)» не матчится в
#    skipped-прогонах — лов run 1).
#
# Использование (с локальной машины, ssh-алиас laptop):
#   bash scripts/run_two_pass.sh [LOGDIR]

set -u

LOGDIR="${1:-/tmp/v017_$(date +%s)}"
FLAGF="$LOGDIR.fast.flag"
FLAGS="$LOGDIR.slow.flag"
LOCK="/tmp/v017_two_pass.lock"
MAX_WAIT=1200  # 20 мин на пасс

mkdir -p "$LOGDIR"
FAST_LOG="$LOGDIR/fast.log"
SLOW_LOG="$LOGDIR/slow.log"
SUMMARY="$LOGDIR/summary.txt"

# ---- Singleton-гвард ----
if mkdir "$LOCK" 2>/dev/null; then
    trap 'rmdir "$LOCK" 2>/dev/null' EXIT
else
    echo "BLOCKED: another two_pass is running (lock: $LOCK)"
    exit 1
fi

echo "=== V0.17 TWO-PASS SUITE $(date +%H:%M) ==="
echo "Logs: $LOGDIR"

# Лог-директория обязана существовать НА НОУТЕ
ssh -o ConnectTimeout=10 laptop "mkdir -p $(dirname "$FAST_LOG") $(dirname "$SLOW_LOG")" || { echo "SSH FAIL"; exit 1; }

# ---- Watcher-скрипт на ноут (создание отдельным ssh-вызовом, запуск другим:
#      heredoc+запуск в одной строке = зомби-родитель, pgrep-ловушка канона) ----
ssh -o ConnectTimeout=10 laptop "cat > /tmp/v017_watch.sh << 'WEOF'
#!/bin/bash
LOG=\$1; FLAG=\$2
while ! grep -qE '^OK \(|^FAILURES|^Errors:|^Tests:' \"\$LOG\" 2>/dev/null; do sleep 20; done
echo \"DONE \$(date +%H:%M)\" > \"\$FLAG\"
WEOF
chmod +x /tmp/v017_watch.sh" || { echo "SSH FAIL (watcher create)"; exit 1; }

# ---- PASS 1: fast ----
echo "[PASS 1/2] fast: paratest -p8 (xml excludes slow)"
rm -f "$FLAGF"
ssh -o ConnectTimeout=10 laptop "rm -f $FLAGF; cd ~/.bee_swarm && setsid nohup vendor/bin/paratest -p8 tests/ --configuration phpunit.xml --no-progress --log-junit $LOGDIR/fast_junit.xml > $FAST_LOG 2>&1 < /dev/null &"
sleep 3
if ! ssh -o ConnectTimeout=10 laptop "grep -q 'Configuration:' $FAST_LOG 2>/dev/null"; then
    echo "FAIL: paratest не стартовал"  # review#2a: ssh-exit обязан абортить ЛОКАЛЬНЫЙ скрипт
    exit 1
fi
ssh -o ConnectTimeout=10 laptop "setsid nohup bash /tmp/v017_watch.sh $FAST_LOG $FLAGF > /dev/null 2>&1 < /dev/null &"

START=$(date +%s)
while ! ssh -o ConnectTimeout=10 laptop "test -f $FLAGF" 2>/dev/null; do
    sleep 45
    if [ $(( $(date +%s) - START )) -gt $MAX_WAIT ]; then
        echo "TIMEOUT: fast-пасс не завершился за ${MAX_WAIT}s — прогресс:"
        ssh -o ConnectTimeout=10 laptop "tail -3 $FAST_LOG"
        exit 1
    fi
done
FAST_TAIL=$(ssh -o ConnectTimeout=10 laptop "tail -5 $FAST_LOG")
echo "$FAST_TAIL" | tail -3

# ---- PASS 2: slow (serial) ----
echo "[PASS 2/2] slow: phpunit --group slow (serial)"
rm -f "$FLAGS"
ssh -o ConnectTimeout=10 laptop "rm -f $FLAGS; cd ~/.bee_swarm && setsid nohup vendor/bin/phpunit tests/ --configuration phpunit.xml --no-progress --group slow --log-junit $LOGDIR/slow_junit.xml > $SLOW_LOG 2>&1 < /dev/null &"
sleep 3
if ! ssh -o ConnectTimeout=10 laptop "grep -q 'Configuration:' $SLOW_LOG 2>/dev/null"; then
    echo "FAIL: phpunit не стартовал"  # review#2a
    exit 1
fi
ssh -o ConnectTimeout=10 laptop "setsid nohup bash /tmp/v017_watch.sh $SLOW_LOG $FLAGS > /dev/null 2>&1 < /dev/null &"

START=$(date +%s)
while ! ssh -o ConnectTimeout=10 laptop "test -f $FLAGS" 2>/dev/null; do
    sleep 45
    if [ $(( $(date +%s) - START )) -gt $MAX_WAIT ]; then
        echo "TIMEOUT: slow-пасс не завершился за ${MAX_WAIT}s — прогресс:"
        ssh -o ConnectTimeout=10 laptop "tail -3 $SLOW_LOG"
        exit 1
    fi
done
SLOW_TAIL=$(ssh -o ConnectTimeout=10 laptop "tail -5 $SLOW_LOG")
echo "$SLOW_TAIL" | tail -3

# ---- Summary ----
# Парсинг по ^Tests: N (финальная строка phpunit): «OK (N tests...)» не матчится
# при skipped-прогонах («OK, but some tests were skipped!» — лов run 1)
# GREEN-вариант печатает «OK (N tests, ...)" без ^Tests: строки (та — только
# в FAIL/skip-вариантах). Парсим оба формата (23.09: run2 green=NO n=? —
# ложный RED из-за однобокого парсера).
FAST_N=$(echo "$FAST_TAIL" | grep -oP '^Tests: \K[0-9]+' | head -1)
[ -z "$FAST_N" ] && FAST_N=$(echo "$FAST_TAIL" | grep -oP 'OK \(\K[0-9]+' | head -1)
SLOW_N=$(echo "$SLOW_TAIL" | grep -oP '^Tests: \K[0-9]+' | head -1)
[ -z "$SLOW_N" ] && SLOW_N=$(echo "$SLOW_TAIL" | grep -oP 'OK \(\K[0-9]+' | head -1)
# Red-детекция: ^FAILURES | ^ERRORS | ^WARNINGS! | Errors: N>0 в итоговой строке.
# review#1 (HIGH): error-only падение печатает «ERRORS!» и «Errors: 1.» ВНУТРИ
# строки ^Tests: — голый ^Errors: мёртв, FALSE-GREEN. Парсим оба источника.
FAST_RED=$(echo "$FAST_TAIL" | grep -cE '^FAILURES|^ERRORS|^WARNINGS|Errors: [1-9]')
SLOW_RED=$(echo "$SLOW_TAIL" | grep -cE '^FAILURES|^ERRORS|^WARNINGS|Errors: [1-9]')
# Парсер-гвард (review#3): пустой N = срыв парсинга = не green.
# ⚠️ bash-приоритет: (A + C == 0 ? 1 : 0) парсится как ((A+C)==0)?1:0 —
# гвард-надбавка ЗАТИРАЕТ red (поймано clean2: FAILURES! → green=YES).
# Только скобки вокруг сравнения.
# Гвард: итоговая строка существует в ЛЮБОМ формате: ^Tests: N (FAIL/skip)
# или OK (N tests (GREEN; 23.09: run2/run3 false-RED из-за однобокого гварда).
FAST_GUARD=$(echo "$FAST_TAIL" | grep -cE '^Tests:|OK \([0-9]+ tests')
SLOW_GUARD=$(echo "$SLOW_TAIL" | grep -cE '^Tests:|OK \([0-9]+ tests')
if [ "$FAST_GUARD" -eq 0 ]; then FAST_RED=$((FAST_RED + 1)); fi
if [ "$SLOW_GUARD" -eq 0 ]; then SLOW_RED=$((SLOW_RED + 1)); fi
# Инвариант fast+slow=total (review#3): enforced, не печать. total — снимок
# 19.09 (1084); при изменении числа тестов обновить (гейт при расхождении).
# Снимок 23.09 (bd78571): fast 1086 (ETB slow-маркеры сняты → в fast) + slow 11
# Снимок 25.09 (V0.15): +13 congruence-тестов (Detector 3, RKCong 4, Escrow 3, Report 3)
# Снимок 11.10 (M1-EXT v2): +9 trail-memory тестов (TrailMarks 3, RawPairPool 1, сворм/лесница — probe-скрипты вне suite; +5 прочих с 1210-снимка)
# Снимок 12.10 (A19): +5 baseline-compression/exact-floor тестов (BaselineCompressionGateTest)
# Снимок 17.10 (DREAM-HARDENING WU-3a/3b + WU-2): +7 (NormalizerUnaryBridge 1,
# ComposeBridgeUnaryMatrix 3, LawClassifierFormulaHash 3); declared 1307
# (1299 была stale на −2 ещё до — инвариант 16-17.10 красный, поднято до факта)
# Снимок 17.10 (DREAM-HARDENING WU-1): +3 DreamSpawnGuardTest → declared 1310
# Снимок 17.10 (ESCROW-ORPHAN-DEPOSITS WU-1): +4 EscrowOrphanDetectorTest → declared 1314
# Снимок 17.10 (ESCROW-ORPHAN-DEPOSITS WU-2): +3 EscrowNodepositTest −1 (пин-тесты болезни слиты в инъекционную канару) → declared 1316
# Снимок 17.10 (WU-2 dual-review): +1 search-path nodeposit-пин → declared 1317
# Снимок 17.10 (VERIF-TICK-WIRING): +5 VerificationTickWiringTest → declared 1322
# Снимок 17.10 (VERIF-TICK-WIRING D1-цепочка): +1 хедрум-гвард капа (VerificationExecutorTest) → declared 1323
# Снимок 18.10 (EXP-045 v1.1 write-on): +3 RouteExecutorWriteOnTest → declared 1326
# Снимок 18.10 (TEST-HYGIENE fast2min): +1 HiveEmptyPoolSleepTest, +1 LanguageVerify28GuardTest,
# -8 LanguageVerify28Test (cert-пайплайн вынесен в scripts/verify_2_8.php, решение owner) → declared 1321
# Снимок 18.10 (dual-review M1): +1 testGarbageEnvFallsBackToDefault (EMPTY_POOL_SLEEP_US парсинг) → declared 1322
# Снимок 19.10 (EXP-045 Road v4 WU): +3 TrailMarksV4SchemaTest, +14 TrailAdmissionTest, +15 TrailMarksV4Test → declared 1354
# Снимок 19.10 (dual-review deleg_231f9459 фиксы): +1 markInLaw, +1 confirmed-below-cap, +1 petal-only → declared 1357
# Снимок 19.10 (ШАГ III ablation WU): +6 TrailAblationTest → declared 1363
# 20.10: TrailFixTest (сид-102 фикс #1) PARKНУТ вне tests/ (вновь в tests/ — OOM, причина не выяснена; верификация fix #1 DISCONFIRMED) → канон остаётся 1363
TOTAL_EXPECTED=1363
SUM=$(( ${FAST_N:-0} + ${SLOW_N:-0} ))
INVARIANT_OK=$([ -n "$FAST_N" ] && [ -n "$SLOW_N" ] && [ "$SUM" -eq "$TOTAL_EXPECTED" ] && echo YES || echo NO)

{
  echo "=== SUMMARY $(date +%H:%M) ==="
  echo "fast: green=$([ "$FAST_RED" -eq 0 ] && echo YES || echo NO) n=${FAST_N:-?}"
  echo "slow: green=$([ "$SLOW_RED" -eq 0 ] && echo YES || echo NO) n=${SLOW_N:-?}"
  echo "fast+slow=$SUM / expected=$TOTAL_EXPECTED invariant=$INVARIANT_OK"
} > "$SUMMARY"
cat "$SUMMARY"

if [ "$FAST_RED" -eq 0 ] && [ "$SLOW_RED" -eq 0 ] && [ "$INVARIANT_OK" = "YES" ]; then
    echo "=== TWO-PASS GREEN ==="
    exit 0
fi
echo "=== TWO-PASS RED (или инвариант разошёлся — new test без маркера?) ==="
exit 1
