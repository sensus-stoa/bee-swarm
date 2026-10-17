<?php

declare(strict_types=1);

namespace BeeSwarm\Core;

/**
 * Отказ кодека GENOME-001: неизвестное слово, циклическая зависимость,
 * несоответствие арности, malformed-геном. Явный отказ вместо молчаливой
 * порчи семантики (урок Link2: движок молча подставляет x2→0 при арности ≥3).
 */
final class WordCodecException extends \RuntimeException
{
}
