<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Serializer;

use RuntimeException;

/**
 * Standard PHP serializer.
 *
 * Uses built-in serialize()/unserialize() functions.
 * Always available, but slower than alternatives (e.g., igbinary).
 */
final class PhpSerializer implements SerializerInterface
{
    public function getName(): string
    {
        return 'php';
    }

    /**
     * PHP serialize/unserialize are always available.
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * Low priority - used as a fallback.
     */
    public function getPriority(): int
    {
        return 0;
    }

    public function serialize(mixed $data): string
    {
        return serialize($data);
    }

    /**
     * @throws RuntimeException on deserialization failure
     */
    public function unserialize(string $data): mixed
    {
        // Suppress warnings and use error handler to detect failures.
        // This serializer is used for AST cache (PhpParser nodes) and parallel worker data,
        // so we must allow PhpParser classes.
        $result = @unserialize($data);

        // unserialize returns false on failure, but false is also a valid value
        // Check if the data could legitimately be false
        if ($result === false && $data !== serialize(false)) {
            throw new RuntimeException('Failed to unserialize data');
        }

        return $result;
    }
}
