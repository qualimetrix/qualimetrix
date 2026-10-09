<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Design\Inheritance;

/** External tail evidence; an incomplete finite depth is a lower bound, while a loop has no depth. */
final readonly class ExternalDepth
{
    private function __construct(
        public ?int $depth,
        public ExternalChainOutcome $outcome,
        public ?string $unresolved,
        public ?bool $reachesThrowable,
    ) {}

    /** @qmx-ignore code-smell.boolean-argument -- reachesThrowable records measured ancestry truth in immutable evidence, not a behavior switch. */
    public static function reachedRoot(int $depth, bool $reachesThrowable = false): self
    {
        return new self($depth, ExternalChainOutcome::ReachedRoot, null, $reachesThrowable);
    }

    /** @qmx-ignore code-smell.boolean-argument -- reachesThrowable records measured ancestry truth in immutable evidence, not a behavior switch. */
    public static function noMap(int $depth = 0, ?bool $reachesThrowable = null): self
    {
        return new self($depth, ExternalChainOutcome::NoMapForIt, null, $reachesThrowable);
    }

    /** @qmx-ignore code-smell.boolean-argument -- reachesThrowable records measured ancestry truth in immutable evidence, not a behavior switch. */
    public static function brokeAt(int $depth, string $fqcn, ?bool $reachesThrowable = null): self
    {
        return new self($depth, ExternalChainOutcome::BrokeAt, $fqcn, $reachesThrowable);
    }

    /** @qmx-ignore code-smell.boolean-argument -- reachesThrowable records measured ancestry truth in immutable evidence, not a behavior switch. */
    public static function loop(string $fqcn, ?bool $reachesThrowable = null): self
    {
        return new self(null, ExternalChainOutcome::Loop, $fqcn, $reachesThrowable);
    }

    public function isComplete(): bool
    {
        return $this->outcome === ExternalChainOutcome::ReachedRoot;
    }
}
