<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Cache;

/**
 * Simple cache interface for storing parsed AST.
 */
interface CacheInterface
{
    /**
     * Get a value from cache.
     *
     * @return mixed|null Returns null if key not found
     */
    public function get(string $key): mixed;

    /**
     * Store a value in cache.
     */
    public function set(string $key, mixed $value): void;

    /**
     * Check if key exists in cache.
     */
    public function has(string $key): bool;

    /**
     * Delete a key from cache.
     */
    public function delete(string $key): void;

    /**
     * Clear all cache entries.
     */
    public function clear(): void;
}
