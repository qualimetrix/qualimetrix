<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Serializer;

use RuntimeException;

/**
 * Selects the optimal available serializer by priority.
 *
 * Automatically selects the best serializer from available ones:
 * 1. IgbinarySerializer (priority 100) - if ext-igbinary is installed
 * 2. PhpSerializer (priority 0) - always available as fallback
 */
final class SerializerSelector
{
    /**
     * @param list<SerializerInterface> $serializers Available serializers
     */
    public function __construct(
        private readonly array $serializers,
    ) {}

    /**
     * Selects the best available serializer.
     *
     * Sorts serializers by priority (highest to lowest)
     * and returns the first available one.
     *
     * @throws RuntimeException If no serializer is available (should not happen)
     */
    public function select(): SerializerInterface
    {
        $available = array_filter(
            $this->serializers,
            static fn(SerializerInterface $serializer): bool => $serializer->isAvailable(),
        );

        if ($available === []) {
            throw new RuntimeException('No serializer available');
        }

        usort(
            $available,
            static fn(SerializerInterface $a, SerializerInterface $b): int =>
                $b->getPriority() <=> $a->getPriority(),
        );

        return $available[0];
    }

    /**
     * Creates a selector with the default set of serializers.
     */
    public static function createDefault(): self
    {
        return new self([
            new IgbinarySerializer(),
            new PhpSerializer(),
        ]);
    }
}
