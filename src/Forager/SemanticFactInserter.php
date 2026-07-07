<?php

declare(strict_types=1);

namespace BeeSwarm\Forager;

use BeeSwarm\Infra\Database;

/**
 * SemanticFactInserter — extracted addSemanticFact() from Forager (D10 Phase 3).
 *
 * Inserts (subject, predicate, object) triples into knowledge_graph
 * with confidence tracking. Filters out noise: short words, stop-words, numerics.
 */
class SemanticFactInserter
{
    private const MIN_WORD_LENGTH = 3;

    private const INITIAL_CONFIDENCE = 0.3;

    private const CONFIDENCE_BOOST = 0.15;

    private const MAX_CONFIDENCE = 1.0;

    /**
     * @var string[]
     */
    private const STOP_WORDS = [
        'и', 'в', 'на', 'с', 'не', 'то', 'же', 'как', 'так', 'он', 'она', 'оно', 'они', 'мы', 'вы',
        'это', 'этот', 'эта', 'эти', 'там', 'тут', 'ещё', 'уже', 'для', 'что', 'нет', 'или', 'да',
        'но', 'а', 'за', 'из', 'от', 'до', 'при', 'под', 'над', 'об', 'во', 'со', 'ко', 'по',
        'бы', 'ли', 'ль', 'б', 'false', 'true', 'null', 'none', 'undefined', 'NaN',
        'который', 'весь', 'твой', 'наш', 'один', 'два', 'три', 'себя', 'свой', 'какой', 'кто',
        'где', 'когда', 'почему', 'очень', 'быть', 'сказать', 'мочь', 'говорить', 'знать', 'стать',
        'есть', 'хотеть', 'видеть', 'идти', 'стоять', 'даже', 'если', 'также', 'вот', 'ну', 'ведь',
        'хоть', 'раз', 'про', 'лишь', 'более', 'менее', 'без', 'через', 'около', 'так', 'после',
        'перед', 'между', 'снова', 'опять', 'всё', 'чего', 'был', 'была', 'было', 'были', 'может',
        'будет', 'могут', 'себе', 'ж', 'мол', 'де', 'якобы', 'почти', 'вроде', 'именно', 'просто',
        'только', 'вообще', 'вдруг', 'значит', 'поэтому', 'однако', 'например', 'кстати', 'всего',
        'конечно', 'возможно', 'вероятно', 'точно', 'ровно', 'буквально', 'фактически', 'обычно',
        'иногда', 'редко', 'всегда', 'никогда', 'часто', 'давно', 'недавно', 'сейчас', 'теперь',
        'сегодня', 'завтра', 'вчера', 'потом', 'тогда', 'здесь', 'везде', 'нигде', 'где-то',
        'куда-то', 'откуда-то', 'почему-то', 'зачем-то', 'как-то', 'что-то', 'кто-то', 'чей-то',
        'сколько-то', 'никак', 'ничто', 'никто', 'некого', 'нечего', 'нечем', 'некуда', 'незачем',
    ];

    /**
     * Insert semantic fact into knowledge_graph with confidence tracking.
     */
    public function insert(string $subject, string $predicate, string $object): void
    {
        $subject = trim($subject);
        $object = trim($object);

        if (mb_strlen($subject) < self::MIN_WORD_LENGTH || mb_strlen($object) < self::MIN_WORD_LENGTH) {
            return;
        }
        if (in_array(mb_strtolower($subject), self::STOP_WORDS) || in_array(mb_strtolower($object), self::STOP_WORDS)) {
            return;
        }
        if (preg_match('/^[\d.]+$/', $subject) || preg_match('/^[\d.]+$/', $object)) {
            return;
        }

        try {
            $stmt = Database::get()->prepare(
                'SELECT confidence FROM knowledge_graph WHERE subject=? AND predicate=? AND object=?'
            );
            $stmt->execute([$subject, $predicate, $object]);
            $existing = $stmt->fetchColumn();

            if ($existing !== false) {
                Database::get()->prepare(
                    'UPDATE knowledge_graph SET confidence=MIN(1.0,?+0.15) WHERE subject=? AND predicate=? AND object=?'
                )->execute([(float) $existing, $subject, $predicate, $object]);
            } else {
                Database::get()->prepare(
                    'INSERT OR IGNORE INTO knowledge_graph (subject,predicate,object,confidence) VALUES (?,?,?,0.3)'
                )->execute([$subject, $predicate, $object]);
            }
        } catch (\PDOException) {
        }
    }
}
