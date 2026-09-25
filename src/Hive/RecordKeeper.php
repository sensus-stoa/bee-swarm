<?php

declare(strict_types=1);

namespace BeeSwarm\Hive;

use BeeSwarm\Core\CongruenceDetector;
use BeeSwarm\Infra\Database;

/**
 * RecordKeeper — запись открытий в БД.
 *
 * Извлечён из Hive::recordDiscovery(). D18: dedup + DB insert + cross-domain.
 */
class RecordKeeper
{
    /**
     * @var array<string, true>
     */
    private array $knownLaws = [];

    /**
     * T5-post-3: операторы, получающие культурный вес от durable-законов.
     */
    // 'sq' НЕ в списке: квадрат в канон-форме записывается как (x*x), токена 'sq'
    // нет, а str_contains('sq') ловил бы 'sqrt' (двойной буст, self-check 05.09).
    private const CULTURE_OPS = ['+', '×', '−', '/', 'max', 'min', 'sqrt'];

    /**
     * ЭКСП-014: cap против заморозки грамматики (квадратичный отрыв базовых ops).
     */
    private const MAX_CULTURE_WEIGHT = 50;

    /**
     * T5-post-4: cap набора виденных fingerprint'ов на закон.
     */
    private const SEEN_FP_CAP = 10;

    /**
     * V0.15 WU-2: опциональный лог-приёмник (CONGRUENCE-CLASS и др. события).
     * null = без лога (обратная совместимость: существующие new RecordKeeper()
     * без аргументов работают как раньше).
     */
    private ?\Closure $log = null;

    /**
     * @param ?\Closure $log опциональный лог-приёмник (null = без лога)
     */
    public function __construct(?\Closure $log = null)
    {
        $this->log = $log;
    }

    private function logEvent(string $message): void
    {
        if ($this->log !== null) {
            ($this->log)($message);
        }
    }

    /**
     * V0.15 WU-2: конгруэнтное поглощение. Форма с ДРУГИМ каноном, но
     * предсказательно эквивалентная существующему закону домена, не
     * удваивает наблюдательное явление: usage_count существующего растёт
     * с атрибуцией (confirmed-семантика T5-post — новый fp на других данных),
     * класс фиксируется (class_id = канон закона, members в class_domain_json),
     * эмитится CONGRUENCE-CLASS.
     *
     * Инконклюзив (нет данных, null-вектор) = поглощения нет («не доказана
     * эквивалентность» ≠ «доказана») — форма честно своя.
     *
     * @return array{inserted: bool, confirmed: bool, cross_domains: list<string>, key: string, congruence: array{class_id: string, absorbed: bool}}|null null = поглощения нет
     */
    private function tryAbsorbCongruent(string $canonFormula, string $domain, array $task, float $cv): ?array
    {
        $hit = $this->findClassHit($canonFormula, $domain, $task);
        if ($hit === null) {
            return null;
        }
        if ($hit['congruent'] === false) {
            // V0.15 WU-3: член класса разошёлся за границей домена → split.
            return $this->emitClassSplit(
                $canonFormula,
                $domain,
                (string) $hit['class_id'],
                is_array($hit['split_point'] ?? null) ? $hit['split_point'] : [],
                $this->domainRows((array) ($task['data'] ?? []))
            );
        }

        return $this->absorbCongruent($canonFormula, $domain, (string) $hit['class_id'], $task, $cv);
    }

    /**
     * V0.15 WU-2: поглощение конгруэнтной формы в класс носителя.
     *
     * @param array<string, mixed> $task
     *
     * @return array{inserted: bool, confirmed: bool, cross_domains: list<string>, key: string, congruence: array{class_id: string, absorbed: bool}}|null
     */
    private function absorbCongruent(string $canonFormula, string $domain, string $classId, array $task, float $cv): ?array
    {
        $row = $this->classCarrierRow($classId, $domain);
        if ($row === null) {
            return null; // закон исчез — форма вставится обычным путём
        }
        $members = $this->classMembers((string) ($row['class_domain_json'] ?? ''));
        $members = $members === [] ? [$classId] : $members;
        $members[] = $canonFormula;
        $fp = $this->resolveFingerprint($row, $task);
        [$splitAt, $domainBounds] = $this->mergedClassState($row, $task);
        $this->updateCarrier($classId, $domain, $cv, $fp, $members, $domainBounds, $splitAt);
        $this->logEvent(sprintf(
            'CONGRUENCE-CLASS: %s absorbed into %s [%s] class_size=%d confirm=%d',
            $canonFormula,
            $classId,
            $domain,
            count($members),
            $fp['confirm'] ? 1 : 0
        ));

        return $this->absorbedResult($domain, $classId, $fp['confirm']);
    }

    /**
     * V0.15 WU-2: return-shape поглощения (единый контракт).
     *
     * @return array{inserted: bool, confirmed: bool, cross_domains: list<string>, key: string, congruence: array{class_id: string, absorbed: bool}}
     */
    private function absorbedResult(string $domain, string $classId, bool $confirm): array
    {
        return [
            'inserted' => false,
            'confirmed' => $confirm,
            'cross_domains' => [],
            'key' => $domain . '::' . $classId,
            'congruence' => [
                'class_id' => $classId,
                'absorbed' => true,
            ],
        ];
    }

    /**
     * V0.15 (R1/R3): слияние состояния класса при поглощении — split_at
     * сохраняется, domain-границы аккумулируются с границами абсорбируемых
     * данных. Состояние двуписательное: писатели (split/absorb) не затирают
     * ключи друг друга.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $task
     *
     * @return array{0: array|string|null, 1: array<int|string, array{float, float}>}
     */
    private function mergedClassState(array $row, array $task): array
    {
        $decoded = json_decode((string) ($row['class_domain_json'] ?? ''), true);
        $decoded = is_array($decoded) ? $decoded : [];
        $bounds = is_array($decoded['domain'] ?? null) ? $decoded['domain'] : null;

        return [
            $decoded['split_at'] ?? null,
            $this->mergeDomainBounds($bounds, $this->domainRows((array) ($task['data'] ?? []))),
        ];
    }

    /**
     * V0.15 WU-2: строка закона-носителя класса (для UPDATE при поглощении).
     *
     * @return array<string, mixed>|null
     */
    private function classCarrierRow(string $classId, string $domain): ?array
    {
        $sel = Database::get()->prepare(
            'SELECT usage_count, last_fingerprint, seen_fingerprints, class_domain_json FROM laws WHERE formula=? AND domain=?'
        );
        $sel->execute([$classId, $domain]);
        $row = $sel->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * V0.15 WU-2: fp-атрибуция поглощения. T5-post-4: confirm = fp НОВЫЙ
     * для закона-носителя. И3-прецедент: пустой fp не затирает сохранённый.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $task
     *
     * @return array{fingerprint: string, fpStored: string, seen: list<string>, confirm: bool}
     */
    private function resolveFingerprint(array $row, array $task): array
    {
        $fingerprint = (string) ($task['fingerprint'] ?? '');
        $seen = (array) (json_decode((string) ($row['seen_fingerprints'] ?? '[]'), true) ?: []);
        $confirm = $fingerprint !== ''
            && ! in_array($fingerprint, $seen, true)
            && (string) ($row['last_fingerprint'] ?? '') !== $fingerprint;
        $fpStored = $fingerprint !== '' ? $fingerprint : (string) ($row['last_fingerprint'] ?? '');

        return [
            'fingerprint' => $fingerprint,
            'fpStored' => $fpStored,
            'seen' => $seen,
            'confirm' => $confirm,
        ];
    }

    /**
     * V0.15 WU-2: UPDATE закона-носителя при поглощении члена класса.
     * R1/R3: class_domain_json пишется ПОЛНЫМ состоянием (members + domain +
     * split_at) — split-статус переживает абсорбцию.
     *
     * @param array{fingerprint: string, fpStored: string, seen: list<string>, confirm: bool} $fp
     * @param list<string> $members
     * @param array<int|string, array{float, float}> $domainBounds
     */
    private function updateCarrier(string $classId, string $domain, float $cv, array $fp, array $members, array $domainBounds, array|string|null $splitAt): void
    {
        Database::get()->prepare(
            'UPDATE laws SET
               usage_count = MIN(usage_count + 1, ?),
               cv = ?,
               last_fingerprint = ?,
               seen_fingerprints = ?,
               confirmed_count = MIN(confirmed_count + ?, ?),
               class_id = ?,
               class_domain_json = ?
             WHERE formula = ? AND domain = ?'
        )->execute([
            self::MAX_CULTURE_WEIGHT,
            $cv,
            $fp['fpStored'],
            json_encode(self::updateSeenSet($fp['fingerprint'], $fp['seen'])),
            $fp['confirm'] ? 1 : 0,
            self::MAX_CULTURE_WEIGHT,
            $classId,
            json_encode([
                'members' => $members,
                'domain' => $domainBounds,
                'split_at' => $splitAt,
            ]),
            $classId,
            $domain,
        ]);
    }

    /**
     * V0.15 WU-2/WU-3: поиск класса-кандидата. Приоритет — существующие
     * классы домена (класс живёт у канона); если классов нет — первичный
     * кластер против безклассовых законов домена. Численный тест — только
     * здесь, после дешёвого SQL-отсева (экономика вызовов детектора).
     *
     * WU-3: форма-член класса (канон в members) при РАСХОЖДЕНИИ предсказаний
     * = split-hit (congruent=false + split_point) — граница класса.
     *
     * @return array{class_id: string, congruent: bool, split_point?: array{index?: int, a?: float, b?: float, diff?: float}}|null
     */
    private function findClassHit(string $canonFormula, string $domain, array $task): ?array
    {
        $data = $task['data'] ?? null;
        if (! is_array($data) || count($data) === 0) {
            return null;
        }
        $rows = $this->domainRows($data);
        if ($rows === []) {
            return null;
        }
        [$candidates, $truncated] = $this->classCandidates($canonFormula, $domain);
        $hit = $this->firstCongruenceHit($canonFormula, $candidates, $rows);
        if ($hit !== null || ! $truncated) {
            return $hit;
        }
        // R2/H2 (dual-review 25.09): окно кандидатов усечено и хита нет —
        // эквивалентность НЕ проверялась против невошедших классов. Маркер
        // наблюдаемости: дедуп-пропуск в этом домене возможен.
        $this->logEvent(sprintf(
            'CONGRUENCE-UNTESTED: %s [%s] candidate window exhausted (%d tested); equivalence not verified',
            $canonFormula,
            $domain,
            count($candidates)
        ));

        return null;
    }

    /**
     * V0.15 WU-2: первый результат численного теста по кандидатам.
     *
     * @param list<array{formula: string, class_id: ?string, in_class: bool}> $candidates
     * @param list<list<float>> $rows
     *
     * @return array{class_id: string, congruent: bool, split_point?: array{index?: int, a?: float, b?: float, diff?: float}}|null
     */
    private function firstCongruenceHit(string $canonFormula, array $candidates, array $rows): ?array
    {
        $detector = new CongruenceDetector();
        foreach ($candidates as $c) {
            $r = $detector->test($canonFormula, (string) $c['formula'], $rows);
            if ($r['inconclusive']) {
                continue;
            }
            if ($r['congruent']) {
                return [
                    'class_id' => $c['class_id'] ?? $c['formula'],
                    'congruent' => true,
                ];
            }
            // Расхождение с законом, чьим членом форма уже является → split.
            if ($c['in_class']) {
                return [
                    'class_id' => $c['formula'],
                    'congruent' => false,
                    'split_point' => is_array($r['split_point']) ? $r['split_point'] : [],
                ];
            }
        }

        return null;
    }

    /**
     * V0.15 WU-2: кандидаты для численного теста. Приоритет — существующие
     * классы домена; классов нет → первичный кластер против безклассовых
     * законов (in_class=false: расхождение с ними — не split, а норма).
     * Второй элемент — флаг усечения окна: кандидатов в домене БОЛЬШЕ, чем
     * проверено (R2/H2: конгруэнтность против хвоста не гарантирована).
     *
     * @return array{0: list<array{formula: string, class_id: ?string, in_class: bool}>, 1: bool}
     */
    private function classCandidates(string $canonFormula, string $domain): array
    {
        // LIMIT N+1 (window+1): шестая строка = доказательство усечения.
        // formula ASC — детерминированный tie-break (found_at секундный).
        $rows = Database::run(
            'SELECT formula, class_id, class_domain_json FROM laws WHERE domain = ? AND class_id IS NOT NULL ORDER BY found_at DESC, formula ASC LIMIT 6',
            [$domain]
        )->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows !== []) {
            return [$this->mapClassCandidates($canonFormula, array_slice($rows, 0, 5)), count($rows) > 5];
        }
        $list = Database::run(
            'SELECT formula FROM laws WHERE domain = ? AND formula != ? ORDER BY found_at DESC, formula ASC LIMIT 4',
            [$domain, $canonFormula]
        )->fetchAll(\PDO::FETCH_COLUMN);

        return [
            array_map(
                static fn (string $f): array => [
                    'formula' => $f,
                    'class_id' => null,
                    'in_class' => false,
                ],
                array_slice($list, 0, 3)
            ),
            count($list) > 3,
        ];
    }

    /**
     * V0.15 WU-2: маппинг строк-классов в кандидатную структуру.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{formula: string, class_id: ?string, in_class: bool}>
     */
    private function mapClassCandidates(string $canonFormula, array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'formula' => (string) $r['formula'],
                'class_id' => $r['class_id'] !== null ? (string) $r['class_id'] : null,
                'in_class' => in_array($canonFormula, $this->classMembers((string) ($r['class_domain_json'] ?? '')), true)
                    || (string) $r['formula'] === (string) $r['class_id'],
            ];
        }

        return $out;
    }

    /**
     * V0.15 WU-3: члены класса из class_domain_json (устойчиво к мусору).
     *
     * @return list<string>
     */
    private function classMembers(string $json): array
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded) || ! isset($decoded['members']) || ! is_array($decoded['members'])) {
            return [];
        }

        return array_values(array_filter($decoded['members'], 'is_string'));
    }

    /**
     * V0.15 WU-3: событие CLASS-SPLIT + атрибуция класса. Форма встаёт
     * обычным путём (return null) — anomaly-уровень 3 (расхождение за
     * границей D = разные законы). Класс канона помечается split_at +
     * исторический domain (min/max по фичам подтверждённых данных).
     *
     * @param array{index?: int, a?: float, b?: float, diff?: float} $splitPoint
     * @param list<list<float>> $rows
     */
    private function emitClassSplit(string $canonFormula, string $domain, string $classId, array $splitPoint, array $rows): ?array
    {
        $this->markClassSplit($classId, $domain, $splitPoint, $rows);
        $this->logEvent(sprintf(
            'CLASS-SPLIT: %s diverges from %s [%s] at point #%d (diff=%.3g) beyond domain boundary; class splits',
            $canonFormula,
            $classId,
            $domain,
            (int) ($splitPoint['index'] ?? -1),
            (float) ($splitPoint['diff'] ?? 0.0)
        ));

        return null; // форма встаёт обычным путём — anomaly-уровень 3
    }

    /**
     * V0.15 WU-3: персист состояния класса при расщеплении (split_at +
     * исторический домен; members сохраняются).
     *
     * @param array{index?: int, a?: float, b?: float, diff?: float} $splitPoint
     * @param list<list<float>> $rows
     */
    private function markClassSplit(string $classId, string $domain, array $splitPoint, array $rows): void
    {
        $sel = Database::get()->prepare(
            'SELECT class_domain_json FROM laws WHERE formula = ? AND domain = ?'
        );
        $sel->execute([$classId, $domain]);
        $row = $sel->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return;
        }
        $decoded = json_decode((string) ($row['class_domain_json'] ?? ''), true);
        $decoded = is_array($decoded) ? $decoded : [];
        $domainBounds = is_array($decoded['domain'] ?? null)
            ? $decoded['domain']
            : $this->mergeDomainBounds(null, $rows);
        $this->updateClassState(
            $classId,
            $domain,
            $this->classMembers((string) ($row['class_domain_json'] ?? '')),
            $domainBounds,
            [
                'index' => (int) ($splitPoint['index'] ?? -1),
            ]
        );
    }

    /**
     * V0.15 WU-3: персистентность состояния класса (members/domain/split_at).
     *
     * @param list<string> $members
     * @param array<string, list<float>> $domainBounds
     */
    private function updateClassState(string $classId, string $domain, array $members, array $domainBounds, array $split): void
    {
        Database::get()->prepare(
            'UPDATE laws SET class_id = ?, class_domain_json = ? WHERE formula = ? AND domain = ?'
        )->execute([
            $classId,
            json_encode([
                'members' => $members,
                'domain' => $domainBounds,
                'split_at' => $split,
            ]),
            $classId,
            $domain,
        ]);
    }

    /**
     * V0.15 WU-3: min/max по фичам домена класса (историческая граница D).
     *
     * @param array<string, list<float>>|null $prev
     * @param list<list<float>> $rows
     *
     * @return array<int|string, array{float, float}>
     */
    private function mergeDomainBounds(?array $prev, array $rows): array
    {
        $cur = [];
        foreach ($rows as $row) {
            foreach ($row as $j => $v) {
                $cur[$j][] = (float) $v;
            }
        }
        if ($prev !== null) {
            foreach ($prev as $j => $pair) {
                if (is_array($pair) && count($pair) === 2) {
                    $cur[$j][] = (float) $pair[0];
                    $cur[$j][] = (float) $pair[1];
                }
            }
        }
        $out = [];
        foreach ($cur as $j => $vals) {
            $out[$j] = [min($vals), max($vals)];
        }

        return $out;
    }

    /**
     * V0.15 WU-2: строки домена для детектора — фичи без y (последний
     * столбец данных = target, предсказательные классы от него не зависят).
     * Детерминированный stride-сэмпл, cap 150: перф-дельта suite (V0.16-урок).
     *
     * @return list<list<float>>
     */
    private function domainRows(array $data): array
    {
        $cap = 150;
        $n = count($data);
        if ($n <= $cap) {
            $sample = $data;
        } else {
            $step = (int) ceil($n / $cap);
            $sample = [];
            for ($i = 0; $i < $n; $i += $step) {
                $sample[] = $data[$i];
            }
        }
        $rows = [];
        foreach ($sample as $row) {
            if (! is_array($row) || count($row) < 2) {
                continue;
            }
            $rows[] = array_map('floatval', array_slice($row, 0, count($row) - 1));
        }

        return $rows;
    }

    /**
     * @param array $d ['atom' => string, 'cv' => float, 'mode' => string]
     * @param array $task ['name' => string, 'source_path' => string, 'content' => string, 'col_labels' => array]
     * @return array{inserted: bool, cross_domains: list<string>, key: string, confirmed?: bool, congruence?: array{class_id: string, absorbed: bool}}
     */
    public function record(array $d, array $task, string $domain): array
    {
        // FORMAL-LAYER Ф1: каноническая форма формулы — (x1+x0) ≡ (x0+x1)
        $canonFormula = \BeeSwarm\Core\ExpressionNormalizer::normalize($d['atom']);

        // Ключ БЕЗ name (CONCERNS Ф1 05.08): разные задачи с одинаковой
        // формулой в одном домене — один закон, не дубли
        $key = $domain . '::' . $canonFormula;
        $known = isset($this->knownLaws[$key]);
        $this->knownLaws[$key] = true;

        $lawClass = $d['class'] ?? 'EMPIRICAL';

        // Cross-domain detection
        $crossDomains = [];
        if (($d['mode'] ?? '') === 'compose') {
            $other = Database::get()->prepare(
                'SELECT DISTINCT domain FROM laws WHERE formula=? AND domain!=?'
            );
            $other->execute([$canonFormula, $domain]);
            $crossDomains = $other->fetchAll(\PDO::FETCH_COLUMN);
        }

        // Ф1: дедуп по (formula,domain); повторное открытие = usage_count+1
        // (сохраняет частотность для Grammar::capped)
        // T5-post: confirmed_count растёт ТОЛЬКО при повторе на ДРУГИХ данных
        // (другой task fingerprint). Повтор на тех же данных — не подтверждение
        // (unlucky-seed защита: EXP3 congruence, выборочная корреляция).
        $fingerprint = (string) ($task['fingerprint'] ?? '');
        $stmt = Database::get()->prepare(
            'SELECT last_fingerprint, confirmed_count, seen_fingerprints FROM laws WHERE formula=? AND domain=?'
        );
        $stmt->execute([$canonFormula, $domain]);
        $prev = $stmt->fetch(\PDO::FETCH_ASSOC);
        $isRepeat = $prev !== false;
        // V0.15 WU-2: конгруэнтное поглощение ДО insert — форма с другим
        // каноном, предсказательно эквивалентная существующему закону домена,
        // не создаёт вторую строку («не удваивать наблюдательное явление»).
        // Строго после синтаксического дедупа: normalize склеил — сюда не доходим.
        // V0.15 WU-3: гвард ТОЛЬКО по факту существования строки (isRepeat).
        // knownLaws-кэш не участвует ни здесь, ни в inserted: абсорбированная
        // форма не обязана остаться конгруэнтной — за границей класса
        // детектор обязан увидеть расхождение (split); «известность» формы
        // глушила бы split-детекцию (самопойман RED WU-3).
        if (! $isRepeat) {
            $absorbed = $this->tryAbsorbCongruent(
                $canonFormula,
                $domain,
                $task,
                (float) ($d['cv'] ?? 0.0)
            );
            if ($absorbed !== null) {
                return $absorbed;
            }
        }
        // T5-post-4 (ЭКСП-037): confirm = fp НОВЫЙ для закона (не в seen-наборе).
        // Повтор той же fp-пары не несёт новой информации — буста нет.
        $seen = [];
        if ($isRepeat) {
            $seen = (array) (json_decode((string) ($prev['seen_fingerprints'] ?? '[]'), true) ?: []);
        }
        $isNewPair = $isRepeat
            && $fingerprint !== ''
            && ! in_array($fingerprint, $seen, true)
            && (string) ($prev['last_fingerprint'] ?? '') !== $fingerprint;
        $confirm = $isNewPair;
        // И3 (премортем deleg_e8b0e05b): пустой fp не затирает сохранённый
        $fpToStore = ($fingerprint !== '' || ! $isRepeat)
            ? $fingerprint
            : (string) ($prev['last_fingerprint'] ?? '');

        Database::get()->prepare(
            'INSERT INTO laws (name,formula,cv,domain,source_path,content_sample,col_labels,law_class,usage_count,last_fingerprint,seen_fingerprints)
             VALUES (?,?,?,?,?,?,?,?,1,?,?)
             ON CONFLICT(formula,domain) DO UPDATE SET
               usage_count = MIN(usage_count + 1, ?),
               cv = excluded.cv,
               last_fingerprint = excluded.last_fingerprint,
               seen_fingerprints = excluded.seen_fingerprints,
               confirmed_count = MIN(confirmed_count + ?, ?)'
        )->execute([
            $task['name'], $canonFormula, $d['cv'], $domain,
            $task['source_path'] ?? '',
            mb_substr($task['content'] ?? '', 0, 200),
            json_encode($task['col_labels'] ?? []),
            $lawClass,
            $fpToStore,
            json_encode(self::updateSeenSet($fingerprint, $seen)),
            self::MAX_CULTURE_WEIGHT,
            $confirm ? 1 : 0,
            self::MAX_CULTURE_WEIGHT,
        ]);

        // T5-post-3 (премортем И3 deleg_122a0816): культура следует за durable-знанием.
        // Подтверждение закона бустит usage_count операторов его формулы —
        // weightedPick ведёт рой к строительным операторам. Graduated: каждый
        // confirm = +1 (не бинарный флаг).
        if ($confirm) {
            $this->boostOperators($canonFormula);
        }

        // knownLaws больше НЕ глушит запись: usage_count/confirmed_count обязаны
        // расти на повторах (T5-post). inserted = факт новой строки (V0.15:
        // knownLaws-кэш из статуса убран — фантомный inserted=false глушит
        // split-детекцию абсорбированных форм, RED WU-3).
        return [
            'inserted' => ! $isRepeat,
            'confirmed' => $confirm,
            'cross_domains' => $crossDomains,
            'key' => $key,
        ];
    }

    /**
     * T5-post: durable-законы домена — подтверждённые повторным открытием
     * на разных данных (confirmed_count >= 1).
     *
     * @return list<array<string, mixed>>
     */
    public function confirmedLaws(string $domain): array
    {
        $stmt = Database::get()->prepare(
            'SELECT name, formula, cv, usage_count, confirmed_count
             FROM laws
             WHERE domain = ? AND confirmed_count >= 1
             ORDER BY confirmed_count DESC, usage_count DESC'
        );
        $stmt->execute([$domain]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * T5-post-2: презентация — durable-законы для экспорта/отчётов.
     * Семантика та же, что confirmedLaws; отдельное имя для читаемости
     * call-site (презентация ≠ внутренний durable-список).
     * NOTE (премортем И4): call-site появится в v4-экспорте; до тех пор
     * метод остаётся документированным API-контрактом durable-семантики.
     *
     * @return list<array<string, mixed>>
     */
    public function presentable(string $domain): array
    {
        return $this->confirmedLaws($domain);
    }

    /**
     * T5-post-3: +1 к usage_count операторов, присутствующих в формуле.
     * grammar_ops имеет UNIQUE(name) — один оп = одна строка, source = первое
     * происхождение. Буст: UPDATE по имени; оп не в грамматике — создаётся.
     * Чужие токены (колонки, константы) молча игнорируются.
     */
    private function boostOperators(string $canonFormula): void
    {
        $db = Database::get();
        foreach (self::CULTURE_OPS as $op) {
            if (! str_contains($canonFormula, $op)) {
                continue;
            }
            // ЭКСП-014 урок (премортем И2 deleg_cca310fb): монотонный буст →
            // базовые ops квадратично отрываются → грамматика замерзает.
            // Cap ограничивает отрыв, сохраняя graduated-порядок.
            $upd = $db->prepare(
                'UPDATE grammar_ops SET usage_count = usage_count + 1
                 WHERE name = ? AND usage_count < ?'
            );
            $upd->execute([$op, self::MAX_CULTURE_WEIGHT]);
            if ($upd->rowCount() === 0) {
                // op отсутствует ИЛИ на cap. INSERT только для отсутствующих.
                $exists = $db->prepare('SELECT 1 FROM grammar_ops WHERE name = ?');
                $exists->execute([$op]);
                if ($exists->fetchColumn() === false) {
                    $db->prepare(
                        'INSERT INTO grammar_ops (name, source, usage_count) VALUES (?, ?, 1)'
                    )->execute([$op, 'culture']);
                }
            }
        }
    }

    /**
     * T5-post-4: обновить набор виденных fingerprint'ов (cap 10).
     * Новый fp добавляется; при переполнении вытесняется самый старый.
     * fp, уже в наборе, не добавляется.
     *
     * @param list<string> $seen
     * @return list<string>
     */
    private static function updateSeenSet(string $fingerprint, array $seen): array
    {
        if ($fingerprint === '' || in_array($fingerprint, $seen, true)) {
            return $seen;
        }
        $seen[] = $fingerprint;
        if (count($seen) > self::SEEN_FP_CAP) {
            array_shift($seen); // вытесняем самый старый
        }
        return $seen;
    }

    public function preloadKnown(): int
    {
        $rows = Database::get()->query('SELECT name, formula, domain FROM laws')->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $this->knownLaws[($r['domain'] ?? 'unknown') . '::' . $r['formula']] = true;
        }
        return count($this->knownLaws);
    }
}
