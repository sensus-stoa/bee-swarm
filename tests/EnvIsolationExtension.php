<?php

declare(strict_types=1);

namespace BeeSwarm\Tests;

use PHPUnit\Event\Event;
use PHPUnit\Event\Test\Finished as TestFinished;
use PHPUnit\Event\Test\FinishedSubscriber as TestFinishedSubscriber;
use PHPUnit\Event\Test\PreparationStarted as TestPreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber as TestPreparationStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * ENV-ISOLATION (30.10, root cause cb7c8b5): putenv() — глобальное состояние
 * ПРОЦЕССА, а paratest-воркер гонит много классов в одном процессе.
 * putenv('X') (unset) в setUp/tearDown одного класса убивает ambient
 * phpunit.xml (force=true работает только на bootstrap) для соседей —
 * «плавающие» фейлы, зависящие от чанкинга воркеров (TrailFixTest 20.10
 * и 30.10 — один и тот же класс уязвимости).
 *
 * Решение — тотальный snapshot/restore env вокруг КАЖДОГО теста:
 *  - PreparationStarted: снять копию всего getenv();
 *  - Finished: убрать ключи, появившиеся за тест; вернуть изменившиеся/
 *    исчезнувшие. Каждый тест видит чистый bootstrap-env — изоляция
 *    по построению, без ручных списков (списки дрейфуют — урок).
 *
 * Регистрация: phpunit.xml <extensions> (работает и под paratest —
 * каждый воркер читает тот же phpunit.xml).
 */
final class EnvIsolationExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new class() implements TestPreparationStartedSubscriber
        {
            public function notify(TestPreparationStarted $event): void
            {
                EnvIsolationState::snapshot();
            }

            public function consume(Event $event): void
            {
            }
        });

        $facade->registerSubscriber(new class() implements TestFinishedSubscriber
        {
            public function notify(TestFinished $event): void
            {
                EnvIsolationState::restore();
            }

            public function consume(Event $event): void
            {
            }
        });
    }
}

/**
 * Отдельный статический state: subscribers в PHPUnit — анонимные классы,
 * хранить snapshot в них нельзя (каждый — свой инстанс без жизненного цикла).
 */
final class EnvIsolationState
{
    private static ?array $snapshot = null;

    public static function snapshot(): void
    {
        self::$snapshot = getenv();
    }

    public static function restore(): void
    {
        if (self::$snapshot === null) {
            return;
        }
        // Появившиеся за тест ключи — unset (нет в bootstrap-снимке).
        foreach (getenv() as $k => $v) {
            if (! array_key_exists($k, self::$snapshot)) {
                putenv($k);
            }
        }
        // Исчезнувшие/изменённые — вернуть к снимку.
        foreach (self::$snapshot as $k => $v) {
            $cur = getenv($k);
            if ($cur === false || $cur !== $v) {
                putenv($k . '=' . $v);
            }
        }
        self::$snapshot = null;
    }
}
