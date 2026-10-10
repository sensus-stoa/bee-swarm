<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

/**
 * S2.3-IGNORANCE: verify_2_3 — измерение гейта §3.3 протокола.
 *
 * «Система сообщает категорию неудачи... и категория верна чаще, чем
 * случайный угадыватель». Операционализация (prereg 10.10, progress.md):
 * ≥7/10 верных диагнозов на контрольном наборе из 10 задач с ИЗВЕСТНОЙ
 * истинной категорией, ≥2 различных категории (4 категории → угадыватель
 * 25%, гейт 70% — строго).
 *
 * Контрольный набор (probe 10.10: 10/10 на MT и LCG): DATA (8 строк),
 * GRAMMAR (logistic, 1/x² — формы вне AtomRegistry UNARY/BINARY, грамматика
 * без '/'), NOISE (y~U(0,1)), DEPTH (dot-пары при SEARCH_DEPTH_MAX=2 +
 * валидация «MAX=3 → найдено» — буквальная процедура §3.3).
 *
 * Логика отделена от CLI (scripts/verify_2_3.php — тонкая обёртка,
 * EnvPressureVerify/FormInvarianceVerify прецедент).
 *
 * ГВАРД: Search пишет preregistrations в живую таблицу → персистентная БД
 * без opt-in (V23_VERIFY_OK=1) отклоняется. Деструктива НЕТ (никаких
 * DELETE, словарь birth не трогается — NO_BIRTH=1).
 */
final class IgnoranceAccuracyVerify
{
    private const ENV_DESTRUCTIVE_OK = 'V23_VERIFY_OK';

    /**
     * Гейт §3.3: верных диагнозов из 10.
     */
    private const GATE_MIN_SCORE = 7;

    /**
     * Гейт §3.3: различных категорий (угадыватель одной категории не 25%).
     */
    private const GATE_MIN_KINDS = 2;

    public const REPORT_ENV_KEYS = ['SWARM_DB_PATH', 'FORAGER_SOURCES', 'SEARCH_BEAM_K', 'SEARCH_DEPTH', 'SEARCH_DEPTH_MAX', 'NO_BIRTH'];

    private static ?array $lastReport = null;

    /**
     * Отчёт (кэшируется в процессе; reset() для повторного прогона).
     *
     * @return array{pass: bool, score: int, kinds: int, cases: list<array{id: string, truth: string, diagnosis: ?string, ok: bool, depth_validation: string}>, pending: list<string>, env: array<string, ?string>}
     */
    public static function report(): array
    {
        if (self::$lastReport !== null) {
            return self::$lastReport;
        }
        return self::$lastReport = self::runOnce();
    }

    public static function reset(): void
    {
        self::$lastReport = null;
    }

    /**
     * Вердикт-гейт §3.3 (изолирован для юнит-теста).
     *
     * @param list<string> $kinds категории, встретившиеся среди ВЕРНЫХ диагнозов
     */
    public static function verdict(int $score, array $kinds): bool
    {
        return $score >= self::GATE_MIN_SCORE
            && count(array_unique($kinds)) >= self::GATE_MIN_KINDS;
    }

    /**
     * Premortem 10.10 HIGH: verify мутирует process-wide env (SEARCH_DEPTH_MAX,
     * NO_BIRTH) и держит static $lastReport — вызов из живого daemon отравил бы
     * эскалацию depth. CLI-only: agreement → enforcement (FormInvarianceVerify
     * прецедента не имеет; здесь живой Hive-путь рядом — гвард обязателен).
     */
    private static function assertCliContext(): void
    {
        if (PHP_SAPI !== 'cli') {
            throw new \RuntimeException('IgnoranceAccuracyVerify mutates process env (SEARCH_DEPTH_MAX/NO_BIRTH); CLI-only (PHP_SAPI=' . PHP_SAPI . ')');
        }
    }

    private static function assertDatabaseIsDisposable(): void
    {
        $path = getenv('SWARM_DB_PATH');
        // Точный ':memory:' (review 10.10 LOW: str_ends_with пропускал
        // персистентный файл «…:memory:» и резал URI-форму file::memory:?cache=shared —
        // обе крайности закрыты: URI-форма считается персистентной-недоверенной).
        if ($path === ':memory:') {
            return;
        }
        if (getenv(self::ENV_DESTRUCTIVE_OK) === '1') {
            return;
        }
        throw new \RuntimeException(
            'IgnoranceAccuracyVerify runs Search (preregistrations writes); refusing persistent DB path '
            . var_export($path === false ? '(unset)' : $path, true)
            . ' - set ' . self::ENV_DESTRUCTIVE_OK . '=1 to override'
        );
    }

    private static function runOnce(): array
    {
        self::assertCliContext();
        self::assertDatabaseIsDisposable();

        $ctx = self::runControlSet();
        $envFacts = self::envFacts(); // ПОСЛЕ прогона: restore вернул pre-run контекст (review 10.10 LOW)

        $pass = self::verdict($ctx['score'], $ctx['kinds']);
        $pending = $ctx['pending'];
        if (! $pass) {
            $pending[] = "gate: score={$ctx['score']}/10 kinds=" . count(array_unique($ctx['kinds']))
                . ' (need >=' . self::GATE_MIN_SCORE . '/' . self::GATE_MIN_KINDS . ')';
        }

        return [
            'pass' => $pass,
            'score' => $ctx['score'],
            'kinds' => count(array_unique($ctx['kinds'])),
            'cases' => $ctx['cases'],
            'pending' => $pending,
            'env' => $envFacts,
        ];
    }

    /**
     * Снапшот env-фактов отчёта (каждый ключ: null=unset).
     *
     * @return array<string, ?string>
     */
    private static function envFacts(): array
    {
        $facts = [];
        foreach (self::REPORT_ENV_KEYS as $key) {
            $facts[$key] = getenv($key) === false ? null : (string) getenv($key);
        }

        return $facts;
    }

    /**
     * Прогон контрольного набора: score/kinds/cases + pending-диагностика.
     *
     * @return array{score: int, kinds: list<string>, cases: list<array{id: string, truth: string, diagnosis: ?string, ok: bool, depth_validation: string}>, pending: list<string>}
     */
    private static function runControlSet(): array
    {
        // Фикстуры не рожают атомы и не пишут prereg-мусор (prereg 10.10).
        $env = self::holdFixtureEnv();
        $engine = new DiscoveryEngine();
        $grammarOps = ['+', '×', '−']; // без '/': иначе x0/x0² решает 1/x² (prereg)

        $score = 0;
        $kinds = [];
        $cases = [];
        $pending = [];

        foreach (self::controlSet() as [$id, $kind, $seed, $truth, $maxDepth]) {
            $case = self::runCaseGuarded($engine, $grammarOps, $env, $id, $kind, $seed, $truth, $maxDepth);
            if ($case['ok']) {
                $score++;
                $kinds[] = $truth;
            }
            $pending = [...$pending, ...$case['pending']];
            $cases[] = $case['case'];
        }
        self::restoreFixtureEnv($env);

        return [
            'score' => $score,
            'kinds' => $kinds,
            'cases' => $cases,
            'pending' => $pending,
        ];
    }

    /**
     * runCase + finally-семантика env (исключение не течёт env-состоянием).
     *
     * @param array<string, string|false> $env
     * @param list<string> $grammarOps
     * @return array{ok: bool, pending: list<string>, case: array{id: string, truth: string, diagnosis: ?string, ok: bool, depth_validation: string}}
     */
    private static function runCaseGuarded(
        DiscoveryEngine $engine,
        array $grammarOps,
        array $env,
        string $id,
        string $kind,
        int $seed,
        string $truth,
        int $maxDepth
    ): array {
        try {
            return self::runCase($engine, $grammarOps, $id, $kind, $seed, $truth, $maxDepth);
        } catch (\Throwable $e) {
            self::restoreFixtureEnv($env);
            throw $e;
        }
    }

    /**
     * Зафиксировать fixture-env (prereg: NO_BIRTH/SEARCH_NO_PREREG) и
     * SEARCH_DEPTH_MAX (restore НЕ хардкодом: phpunit.xml форсирует MAX=2 —
     * восстановление в prev, иначе cross-test pollution воркера).
     *
     * @return array<string, string|false>
     */
    private static function holdFixtureEnv(): array
    {
        $env = [
            'NO_BIRTH' => getenv('NO_BIRTH'),
            'SEARCH_NO_PREREG' => getenv('SEARCH_NO_PREREG'),
            'SEARCH_DEPTH_MAX' => getenv('SEARCH_DEPTH_MAX'),
        ];
        putenv('NO_BIRTH=1');
        putenv('SEARCH_NO_PREREG=1');

        return $env;
    }

    /**
     * @param array<string, string|false> $env
     */
    private static function restoreFixtureEnv(array $env): void
    {
        foreach ($env as $key => $val) {
            if ($val === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $val);
            }
        }
    }

    /**
     * Один кейс: discover + verdict + DEPTH-валидация.
     *
     * @param list<string> $grammarOps
     * @return array{ok: bool, pending: list<string>, case: array{id: string, truth: string, diagnosis: ?string, ok: bool, depth_validation: string}}
     */
    private static function runCase(
        DiscoveryEngine $engine,
        array $grammarOps,
        string $id,
        string $kind,
        int $seed,
        string $truth,
        int $maxDepth
    ): array {
        [$X, $y] = self::mkCase($kind, self::rng($seed));
        putenv('SEARCH_DEPTH_MAX=' . $maxDepth);
        [, , , $diagnosis] = $engine->discover($X, $y, $grammarOps, 0.15, null, 0.2, null, null);
        // restore делает restoreFixtureEnv (prev-значение, не хардкод —
        // phpunit.xml форсирует MAX=2; хардкод '4' оставлял pollution воркера).
        return self::caseVerdict($id, $kind, $truth, $diagnosis, $engine, $grammarOps, $X, $y);
    }

    /**
     * Вердикт кейса + DEPTH-валидация (после restore SEARCH_DEPTH_MAX=4).
     *
     * @param list<string> $grammarOps
     * @param list<list<float>> $X
     * @param list<float> $y
     * @return array{ok: bool, pending: list<string>, case: array{id: string, truth: string, diagnosis: ?string, ok: bool, depth_validation: string}}
     */
    private static function caseVerdict(
        string $id,
        string $kind,
        string $truth,
        ?string $diagnosis,
        DiscoveryEngine $engine,
        array $grammarOps,
        array $X,
        array $y
    ): array {
        $ok = $diagnosis === $truth;
        $depth = $kind === 'dot'
            ? self::validateDepthCategory($engine, $grammarOps, $X, $y, $id)
            : [
                'validation' => 'n/a',
                'pending' => [],
            ];

        return [
            'ok' => $ok,
            'pending' => $depth['pending'],
            'case' => [
                'id' => $id,
                'truth' => $truth,
                'diagnosis' => $diagnosis,
                'ok' => $ok,
                'depth_validation' => $depth['validation'],
            ],
        ];
    }

    /**
     * §3.3 валидация DEPTH-категории: «увеличить глубину → решается»
     * (dot при MAX=3 решается slot-каскадом). DEPTH без процедуры — метка,
     * не знание.
     *
     * @param list<string> $grammarOps
     * @param list<list<float>> $X
     * @param list<float> $y
     * @return array{validation: string, pending: list<string>}
     */
    private static function validateDepthCategory(
        DiscoveryEngine $engine,
        array $grammarOps,
        array $X,
        array $y,
        string $id
    ): array {
        putenv('SEARCH_DEPTH_MAX=3');
        [$found3] = $engine->discover($X, $y, $grammarOps, 0.15, null, 0.2, null, null);
        putenv('SEARCH_DEPTH_MAX=2'); // restore доphpunit-MAX; финальный restore в runControlSet
        if ($found3) {
            return [
                'validation' => 'D3-FOUND',
                'pending' => [],
            ];
        }

        return [
            'validation' => 'D3-FAIL',
            'pending' => ["{$id}: depth-3 validation failed (dot not solved at MAX=3)"],
        ];
    }

    /**
     * Контрольный набор (prereg 10.10 — НЕ менять без новой prereg-сессии).
     *
     * @return list<array{0: string, 1: string, 2: int, 3: string, 4: int}>
     */
    private static function controlSet(): array
    {
        return [
            ['c01', 'data', 21, 'DATA', 4],
            ['c02', 'logistic', 31, 'GRAMMAR', 4],
            ['c03', 'logistic', 32, 'GRAMMAR', 4],
            ['c04', 'noise', 41, 'NOISE', 4],
            ['c05', 'noise', 42, 'NOISE', 4],
            ['c06', 'dot', 51, 'DEPTH', 2],
            ['c07', 'dot', 52, 'DEPTH', 2],
            ['c08', 'invsq', 61, 'GRAMMAR', 4],
            ['c09', 'invsq', 62, 'GRAMMAR', 4],
            ['c10', 'noise', 71, 'NOISE', 4],
        ];
    }

    /**
     * Детерминированный LCG в [0,1): глобальный MT-поток воркера не трогаем
     * (LCG-инъекция вместо mt_srand — pitfall 09.10).
     */
    private static function rng(int $seed): callable
    {
        $state = $seed & 0x7FFFFFFF;
        if ($state === 0) {
            $state = 1;
        }

        return static function () use (&$state): float {
            $state = (int) (($state * 48271) % 2147483647);

            return $state / 2147483647;
        };
    }

    /**
     * Генераторы контрольного набора (probe 10.10, пробы s23_probe.php).
     *
     * @return array{0: list<list<float>>, 1: list<float>}
     */
    private static function mkCase(string $kind, callable $next): array
    {
        return match ($kind) {
            'data' => self::mkData(self::DATA_ROWS, $next),
            'logistic' => self::mkLogistic(80, $next),
            'noise' => self::mkNoise(60, $next),
            'dot' => self::mkDot(100, $next),
            'invsq' => self::mkInvSq(80, $next),
            default => throw new \InvalidArgumentException("unknown fixture kind: {$kind}"),
        };
    }

    /**
     * 8 строк < tMin=10 → DATA (tMin = max(10, nFeat×5) = 10 при 2 фичах).
     */
    private const DATA_ROWS = 8;

    /**
     * @return array{0: list<list<float>>, 1: list<float>}
     */
    private static function mkData(int $n, callable $next): array
    {
        $X = [];
        $y = [];
        for ($i = 0; $i < $n; $i++) {
            $a = 1.0 + $next() * 9.0;
            $b = 1.0 + $next() * 9.0;
            $X[] = [$a, $b];
            $y[] = $a + $b;
        }

        return [$X, $y];
    }

    /**
     * 1/(1+exp(−x)) — вне UNARY/BINARY const (GRAMMAR при грамматике без '/').
     */
    private static function mkLogistic(int $n, callable $next): array
    {
        $X = [];
        $y = [];
        for ($i = 0; $i < $n; $i++) {
            $x = -4.0 + $next() * 8.0;
            $X[] = [$x];
            $y[] = 1.0 / (1.0 + exp(-$x));
        }

        return [$X, $y];
    }

    /**
     * y ~ U(0,1) независимо от x (NOISE).
     */
    private static function mkNoise(int $n, callable $next): array
    {
        $X = [];
        $y = [];
        for ($i = 0; $i < $n; $i++) {
            $X[] = [$next()];
            $y[] = $next();
        }

        return [$X, $y];
    }

    /**
     * x0*x3+x1*x4+x2*x5 — dot-ПАРЫ (SelfDiagnosisTest-класс; depth-3).
     */
    private static function mkDot(int $n, callable $next): array
    {
        $X = [];
        $y = [];
        for ($i = 0; $i < $n; $i++) {
            $v = [];
            for ($j = 0; $j < 6; $j++) {
                $v[] = $next() * 10.0 - 5.0;
            }
            $X[] = $v;
            $y[] = $v[0] * $v[3] + $v[1] * $v[4] + $v[2] * $v[5];
        }

        return [$X, $y];
    }

    /**
     * 1/x² — GRAMMAR при грамматике без '/'.
     */
    private static function mkInvSq(int $n, callable $next): array
    {
        $X = [];
        $y = [];
        for ($i = 0; $i < $n; $i++) {
            $x = 1.0 + $next() * 9.0;
            $X[] = [$x];
            $y[] = 1.0 / ($x * $x);
        }

        return [$X, $y];
    }
}
