<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Metric;

/**
 * Static holder for collector-level configuration.
 *
 * Allows passing configuration options to metric collectors without
 * constructor injection (collectors are registered via autoconfiguration).
 */
final class CollectorConfigHolder
{
    public const string LCOM_EXCLUDE_METHODS = 'lcom.exclude_methods';

    /** @var array<string, mixed> */
    private static array $config = [];

    public static function set(string $key, mixed $value): void
    {
        self::$config[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$config[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return self::$config;
    }

    public static function reset(): void
    {
        self::$config = [];
    }
}
