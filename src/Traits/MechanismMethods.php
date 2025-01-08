<?php

declare(strict_types=1);

namespace Workerman\RabbitMQ\Traits;

trait MechanismMethods
{
    /**
     * @var array<string, callable>
     */
    protected static array $mechanismHandlers = [];

    /**
     * @param string $mechanism
     * @param callable $handler
     */
    public static function registerMechanismHandler(string $mechanism, callable $handler): void
    {
        self::$mechanismHandlers[$mechanism] = $handler;
    }

    /**
     * @return array
     */
    public static function getMechanismHandlers(): array
    {
        return self::$mechanismHandlers;
    }

    /**
     * @param string $mechanism
     * @return callable|null
     */
    public static function getMechanismHandler(string $mechanism): callable|null
    {
        return self::$mechanismHandlers[$mechanism] ?? null;
    }
}
