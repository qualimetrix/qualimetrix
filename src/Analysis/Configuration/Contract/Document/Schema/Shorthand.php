<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Document\Schema;

/**
 * A key that stands for declared paths of the same entry: `threshold: 5` is
 * `warning: 5` plus `error: 5`. It is expanded inside the layer that wrote it,
 * before any merge, so a later layer's `warning` overrides only `warning`.
 * Writing the shorthand and one of its targets in the same layer is refused.
 */
final readonly class Shorthand
{
    /** @param non-empty-list<string> $targets */
    private function __construct(
        public string $key,
        public array $targets,
    ) {}

    /**
     * The written value is copied to every target key.
     *
     * @param non-empty-list<string> $targets canonical key paths in this entry
     */
    public static function spreading(string $key, array $targets): self
    {
        return new self($key, $targets);
    }
}
