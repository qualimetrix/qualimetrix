<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Design\Inheritance;

/** Complete answer for one declaration; classifier knowledge is independent of depth completeness. */
final readonly class InheritanceResolution
{
    /** @param list<array{cause: string, name: string}> $obstructions */
    public function __construct(
        public ?int $depth,
        public InheritanceOutcome $outcome,
        public ThrowableReach $reachesThrowable,
        public array $obstructions = [],
    ) {}

    public function withPrefix(int $links, ThrowableReach $prefixThrowable): self
    {
        return new self(
            $this->depth === null ? null : $links + $this->depth,
            $this->outcome,
            $prefixThrowable === ThrowableReach::Yes ? ThrowableReach::Yes : $this->reachesThrowable,
            $this->obstructions,
        );
    }
}
