<?php

declare(strict_types=1);

namespace BeeSwarm\Infra;

/**
 * RNG Isolation — предотвращает state leakage (srand poisoning array_rand).
 *
 * ПРОБЛЕМА: PHP использует глобальное состояние RNG. Вызов srand(42) в одной
 * функции делает array_rand() детерминированным во ВСЕЙ программе. Это silent bug.
 *
 * ПАТТЕРН: save-and-restore. Захватываем энтропию до детерминированного блока,
 * восстанавливаем после. Guard отслеживает активные незакрытые блоки.
 *
 * ИСПОЛЬЗОВАНИЕ:
 *   $guard = RngIsolation::deterministicSeed(42);
 *   try {
 *       // ... deterministic код ...
 *   } finally {
 *       $guard->restore();
 *   }
 *
 * DETECTION: assertClean() проверяет что ВСЕ guard'ы закрыты.
 * Это ловит забытый restore() в any code path.
 */
class RngIsolation
{
    /** @var array<int, self> active unrestored guards */
    private static array $activeGuards = [];

    private int $savedSeed;
    private int $guardId;

    private function __construct(int $savedSeed)
    {
        $this->savedSeed = $savedSeed;
        $this->guardId = \spl_object_id($this);
    }

    /**
     * Захватить энтропию и установить детерминированный seed.
     *
     * @param int $deterministicSeed seed для детерминированного блока
     * @return self guard с захваченной энтропией — обязательно вызвать ->restore()
     */
    public static function deterministicSeed(int $deterministicSeed): self
    {
        $savedSeed = mt_rand();           // захватываем один токен энтропии
        srand($deterministicSeed);        // детерминированный seed

        $guard = new self($savedSeed);
        self::$activeGuards[$guard->guardId] = $guard;
        return $guard;
    }

    /**
     * Восстановить RNG — srand() с захваченной энтропией.
     */
    public function restore(): void
    {
        srand($this->savedSeed);
        unset(self::$activeGuards[$this->guardId]);
    }

    /**
     * Проверить, что все guard'ы закрыты.
     *
     * Вызывать в tearDown() каждого теста.
     *
     * @throws \RuntimeException если есть незакрытые guard'ы
     */
    public static function assertClean(): void
    {
        if (! empty(self::$activeGuards)) {
            $count = count(self::$activeGuards);
            throw new \RuntimeException(
                "RNG POISONING: {$count} unrestored RngIsolation guard(s). " .
                'srand(N) was called without restore(). ' .
                'Every deterministicSeed() must have a matching restore().'
            );
        }
    }

    /**
     * Скриптовая проверка: есть ли незакрытые guard'ы?
     *
     * @return bool true если есть незакрытые guard'ы
     */
    public static function hasUnrestoredGuards(): bool
    {
        return ! empty(self::$activeGuards);
    }
}
