<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Serializer;

use RuntimeException;

/**
 * Serializer based on the igbinary extension.
 *
 * Igbinary provides:
 * - Higher performance (2-5x faster than serialize)
 * - Smaller data size (~50% of serialize output)
 *
 * Requires the ext-igbinary extension to be installed.
 */
final class IgbinarySerializer implements SerializerInterface
{
    /**
     * Igbinary v2 serialized representation of `false`.
     * Format: header (4 bytes: \x00\x00\x00\x02) + false type byte (\x04).
     */
    private const string IGBINARY_FALSE = "\x00\x00\x00\x02\x04";

    /**
     * Igbinary v2 serialized representation of `null`.
     * Format: header (4 bytes: \x00\x00\x00\x02) + null type byte (\x00).
     */
    private const string IGBINARY_NULL = "\x00\x00\x00\x02\x00";

    public function getName(): string
    {
        return 'igbinary';
    }

    /**
     * Checks if the igbinary extension is available.
     */
    public function isAvailable(): bool
    {
        return \extension_loaded('igbinary');
    }

    /**
     * High priority - used by default when available.
     */
    public function getPriority(): int
    {
        return 100;
    }

    /**
     *
     */
    public function serialize(mixed $data): string
    {
        $result = @igbinary_serialize($data);

        if ($result === null) {
            $error = error_get_last();
            throw new RuntimeException(
                \sprintf('Igbinary serialization failed: %s', $error['message'] ?? 'unknown error'),
            );
        }

        return $result;
    }

    /**
     *
     */
    public function unserialize(string $data): mixed
    {
        $result = @igbinary_unserialize($data);

        if ($result === false && $data !== self::IGBINARY_FALSE) {
            $error = error_get_last();
            throw new RuntimeException(
                \sprintf('Igbinary unserialization failed: %s', $error['message'] ?? 'unknown error'),
            );
        }

        if ($result === null && $data !== self::IGBINARY_NULL) {
            $error = error_get_last();
            throw new RuntimeException(
                \sprintf('Igbinary unserialization failed: %s', $error['message'] ?? 'unknown error'),
            );
        }

        return $result;
    }
}
