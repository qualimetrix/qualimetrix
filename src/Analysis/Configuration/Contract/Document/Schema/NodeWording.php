<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Document\Schema;

/**
 * What the engine tells an author about one node beyond the form of its
 * value: the likely intent it adds when it refuses a value written there, and
 * the warning it raises when a written empty list replaces a non-empty one.
 */
final readonly class NodeWording
{
    public function __construct(
        public ?string $hint = null,
        public ?string $emptyOverrideNotice = null,
    ) {}

    /** This wording with the sentences given replaced; one not given stays. */
    public function with(?string $hint = null, ?string $emptyOverrideNotice = null): self
    {
        return new self($hint ?? $this->hint, $emptyOverrideNotice ?? $this->emptyOverrideNotice);
    }
}
