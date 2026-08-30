<?php
declare(strict_types=1);

namespace BeeSwarm\Hive;

use BeeSwarm\Core\ExpressionNormalizer;
use BeeSwarm\Infra\Database;

/**
 * FLOOR-EMERGENCE M1 (EXP-038): LawCompressor в рантайме.
 *
 * §3.8 протокола CV→0: законы L1..Ln из РАЗНЫХ доменов со структурным
 * изоморфизмом (одинаковая форма expression-tree после нормализации)
 * сжимаются в атом-слово. Атом добавляется в grammar_ops → участвует
 * в поиске → depth_потребная = depth_сырая − сжатие.
 *
 * Инсайт (EXP-038 pre-reg): этажи не открываются — ИСЧЕЗАЮТ по мере
 * роста словаря. Сжатие дешевле эскалации: пространство СЖИМАЕТСЯ.
 *
 * Контракты:
 * - B-AS-ARGUMENT: definition-атомы канонизируются xN→x0/x1 (порядок
 *   появления), иначе def молча возвращает мусор.
 * - Идемпотентность: повторный compress() не плодит дубли (уникальный
 *   ключ = fingerprint структуры).
 * - Изоморфизм = переименование переменных сохраняет структуру
 *   (grounding, не memorization); порядок некоммутативных операторов
 *   значим.
 */
final class LawIsomorphismCompressor
{
    /**
     * Минимум законов для рождения атома (§3.8: ≥2 из разных доменов).
     */
    private const MIN_ISOMORPHS = 2;

    /**
     * Префикс имени атома-слова (Birth Word).
     */
    private const ATOM_PREFIX = 'BW';

    /**
     * Один проход сжатия. Возвращает число рождённых атомов.
     *
     * @param array<string> $domains ограничение скоупа (null = все домены).
     *     Runtime: весь мир. Тесты: изоляция через домен-фильтр (кросс-тест
     *     pollution: SELECT без фильтра видит законы чужих тестов).
     */
    public function compress(?array $domains = null): int
    {
        $groups = $this->groupByFingerprint($domains);
        $born = 0;
        foreach ($groups as $fingerprint => $laws) {
            $lawDomains = array_unique(array_column($laws, 'domain'));
            if (count($laws) < self::MIN_ISOMORPHS || count($lawDomains) < self::MIN_ISOMORPHS) {
                continue;
            }
            if ($this->atomExists($fingerprint)) {
                continue;
            }
            $this->birthAtom($fingerprint, $laws);
            $born++;
        }
        return $born;
    }

    /**
     * Группировка законов по структурному fingerprint: переменные
     * переименовываются в x0..xN по порядку появления → изоморфные
     * деревья (разные имена переменных) попадают в одну группу;
     * порядок некоммутативных операций сохранён.
     *
     * @return array<string, array<int, array{name: string, formula: string, domain: string, canonical: string}>>
     */
    private function groupByFingerprint(?array $domains): array
    {
        $db = Database::get();
        // ORDER BY formula: детерминированный tie-break для definition
        // (зеркальные формы (x0−x1)/(x1−x0) в одной fp-группе — шаблоном
        // становится лексикографически первая). CONCERNS deleg_3302236f п.2.
        if ($domains === null) {
            $rows = $db->query('SELECT name, formula, domain FROM laws ORDER BY formula ASC')
                ->fetchAll(\PDO::FETCH_ASSOC);
        } else {
            $placeholders = implode(',', array_fill(0, count($domains), '?'));
            $stmt = $db->prepare("SELECT name, formula, domain FROM laws WHERE domain IN ({$placeholders}) ORDER BY formula ASC");
            $stmt->execute($domains);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }
        $groups = [];
        foreach ($rows as $row) {
            $canonical = self::canonize($row['formula']);
            if ($canonical === null) {
                continue; // не выражается деревом (R-атомы и т.п.) — не язык
            }
            $fp = ExpressionNormalizer::fingerprint($canonical);
            $groups[$fp][] = [
                'name' => $row['name'],
                'formula' => $row['formula'],
                'domain' => $row['domain'],
                'canonical' => $canonical,
            ];
        }
        return $groups;
    }

    /**
     * Переименование терминалов xN → x0..xK по порядку первого появления.
     * (x7−x3) → (x0−x1); (x1−x0) → (x0−x1)? НЕТ: (x1−x0) → (x0−x1)
     * по переименованию, но порядок операндов в дереве не меняется:
     * (x1−x0) канонизируется как (x0−x1) c ЗАМЕНОЙ РОЛЕЙ. Чтобы не
     * склеивать зеркальные некоммутативные формы, canonize переименовывает
     * ТОЛЬКО имена, не порядок: (x1−x0) → (x0−x1) НЕ даёт тот же
     * fingerprint, что (x0−x1)? Даёт. Решение: fingerprint строится по
     * дереву С ПОЗИЦИОННЫМИ ролями: терминал = его ранг появления.
     * (x0−x1): left=x0(rank0), right=x1(rank1) → роль left=0,right=1.
     * (x1−x0): left=x1(rank0 после ренейма), right=x0(rank1) → left=0,right=1.
     * Оба дают (x0−x1) — зеркальные формы склеиваются. Это ПРАВИЛЬНО
     * для языка: закон «разность A и B» не зависит от того, какая
     * колонка называлась x0. Коммутативная пара + и × склеивается
     * нормализатором и так.
     */
    public static function canonize(string $formula): ?string
    {
        // A1 rename-fix (EXP-039): парс С birth-операторами — иначе
        // "x1BWdiff0001x2" склеивается в атом и слоты молекулы
        // переименовываются отдельно от слова → каша при apply.
        $birthOps = \BeeSwarm\Core\ExpressionEvaluator::birthOpNames();
        $tree = ExpressionNormalizer::parse($formula, $birthOps);
        if ($tree === null) {
            return null;
        }
        if (isset($tree['atom'])) {
            return null; // атом без структуры — не изоморфизм
        }
        // Формула с BW-словом внутри = молекула: терминалы — СЛОТЫ
        // (роли переносимости), renaming их НЕ трогает (решение юзера
        // 30.08, fix-проба EXP-039: def без renaming → cvH=0).
        if (self::containsBirthOp($tree, $birthOps)) {
            return ExpressionNormalizer::render($tree);
        }
        $map = [];
        $renamed = self::renameTerminals($tree, $map);
        return ExpressionNormalizer::render($renamed);
    }

    /**
     * A1: содержит ли дерево birth-слово (B/BW-оператор из grammar_ops).
     *
     * @param array $node дерево ExpressionNormalizer::parse
     * @param array<string> $birthOps имена birth-операторов
     */
    private static function containsBirthOp(array $node, array $birthOps): bool
    {
        if ($birthOps === []) {
            return false;
        }
        if (isset($node['op']) && in_array($node['op'], $birthOps, true)) {
            return true;
        }
        if (isset($node['l']) && self::containsBirthOp($node['l'], $birthOps)) {
            return true;
        }
        return isset($node['r']) && $node['r'] !== null && self::containsBirthOp($node['r'], $birthOps);
    }

    /**
     * @param array $node дерево ExpressionNormalizer::parse
     * @param array<string, string> $map xN → xK (K = порядок появления)
     */
    private static function renameTerminals(array $node, array &$map): array
    {
        if (isset($node['atom'])) {
            $atom = $node['atom'];
            if (preg_match('/^x(\d+)$/', $atom) !== 1) {
                return $node; // не терминал-переменная (K1, константа) — не трогаем
            }
            if (! isset($map[$atom])) {
                $map[$atom] = 'x' . count($map);
            }
            return [
                'atom' => $map[$atom],
            ];
        }
        $node['l'] = self::renameTerminals($node['l'], $map);
        if ($node['r'] !== null) {
            $node['r'] = self::renameTerminals($node['r'], $map);
        }
        return $node;
    }

    private function atomExists(string $fingerprint): bool
    {
        $stmt = Database::get()->prepare('SELECT COUNT(*) FROM grammar_ops WHERE name = ?');
        $stmt->execute([self::atomName($fingerprint)]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Имя атома детерминировано структурой: тот же изоморфизм в другом
     * прогоне/роe получает то же имя → атомы совместимы между ульями.
     */
    private static function atomName(string $fingerprint): string
    {
        // Полный md5: 4 символа = 65 536 пространств — birthday-коллизия
        // при сотнях атомов даёт молчаливое НЕ-рождение (atomExists ложен).
        // CONCERNS deleg_3302236f п.1.
        return self::ATOM_PREFIX . md5($fingerprint);
    }

    /**
     * @param array<int, array{name: string, formula: string, domain: string, canonical: string}> $laws
     */
    private function birthAtom(string $fingerprint, array $laws): void
    {
        $db = Database::get();
        $name = self::atomName($fingerprint);
        // definition = канонизированная форма первого закона группы.
        // Схема grammar_ops: (name, source, definition, usage_count, status).
        // REUSE-CRITERION (10.08): статус 'candidate' → active после reuse≥1.
        $definition = $laws[0]['canonical'];
        $stmt = $db->prepare(
            'INSERT INTO grammar_ops (name, source, definition, status) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$name, 'birth', $definition, 'candidate']);
    }
}
