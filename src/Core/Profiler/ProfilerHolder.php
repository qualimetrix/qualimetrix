<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Profiler;

/**
 * Synthetic service holder for global profiler access.
 *
 * This class provides static access to the profiler instance across the application.
 * The profiler instance is injected via set() during container initialization.
 *
 * Usage:
 *   ProfilerHolder::get()->start('operation');
 *   // ... do work ...
 *   ProfilerHolder::get()->stop('operation');
 */
final class ProfilerHolder
{
    private static ?ProfilerInterface $profiler = null;

    /**
     * Set the profiler instance.
     *
     * This method should be called during container initialization.
     */
    public static function set(ProfilerInterface $profiler): void
    {
        self::$profiler = $profiler;
    }

    /**
     * Get the current profiler instance.
     *
     * Returns a NullProfiler if no profiler has been set.
     */
    public static function get(): ProfilerInterface
    {
        return self::$profiler ??= new NullProfiler();
    }

    /**
     * Reset the profiler instance (useful for testing).
     */
    public static function reset(): void
    {
        self::$profiler = null;
    }
}
