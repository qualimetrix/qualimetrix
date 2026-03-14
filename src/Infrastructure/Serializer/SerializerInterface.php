<?php

declare(strict_types=1);

namespace AiMessDetector\Infrastructure\Serializer;

/**
 * Interface for data serialization in inter-process communication.
 *
 * Used in parallel strategies to convert data
 * to string representation and back.
 */
interface SerializerInterface
{
    /**
     * Returns a unique identifier for this serializer.
     *
     * Used by FileCache to detect serializer changes and invalidate cache.
     */
    public function getName(): string;

    /**
     * Checks if the serializer is available in the current environment.
     *
     * For example, IgbinarySerializer checks for ext-igbinary availability.
     */
    public function isAvailable(): bool;

    /**
     * Returns the serializer priority.
     *
     * Higher priority means better performance.
     * Used for automatic selection of the optimal serializer.
     *
     * @return int Priority (0 = low, 100+ = high)
     */
    public function getPriority(): int;

    /**
     * Serializes data to a string.
     *
     * @param mixed $data Data to serialize
     *
     * @return string Serialized representation
     */
    public function serialize(mixed $data): string;

    /**
     * Deserializes a string back to data.
     *
     * @param string $data Serialized string
     *
     * @return mixed Restored data
     */
    public function unserialize(string $data): mixed;
}
