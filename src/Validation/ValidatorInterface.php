<?php

declare(strict_types=1);

namespace BeeSwarm\Validation;

/**
 * ValidatorInterface — контракт для валидаторов законов.
 * SOLID I: мелкие интерфейсы для тестируемости.
 */
interface ValidatorInterface
{
    /**
     * Валидировать набор кандидатов на данных.
     * @param array $candidates [{atom, cv, mode}, ...]
     * @return array [{atom, cv_train, cv_holdout, mode}, ...] — только прошедшие
     */
    public static function validate(array $candidates, array $X, array $y): array;
}
