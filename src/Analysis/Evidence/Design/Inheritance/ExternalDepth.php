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
        public ThrowableReach $reachesThrowable,
        public ?string $analysedName = null,
    ) {}

    public static function reachedRoot(int $depth, ThrowableReach $reachesThrowable = ThrowableReach::No): self
    {
        return new self($depth, ExternalChainOutcome::ReachedRoot, null, $reachesThrowable);
    }

    public static function noMap(int $depth = 0, ThrowableReach $reachesThrowable = ThrowableReach::Unknown, ?string $unresolved = null): self
    {
        return new self($depth, ExternalChainOutcome::NoMapForIt, $unresolved, $reachesThrowable);
    }

    public static function brokeAt(int $depth, string $fqcn, ThrowableReach $reachesThrowable = ThrowableReach::Unknown): self
    {
        return new self($depth, ExternalChainOutcome::BrokeAt, $fqcn, $reachesThrowable);
    }

    public static function loop(string $fqcn, ThrowableReach $reachesThrowable = ThrowableReach::Unknown): self
    {
        return new self(null, ExternalChainOutcome::Loop, $fqcn, $reachesThrowable);
    }

    public static function reachedAnalysedName(int $depth, string $fqcn, ThrowableReach $reachesThrowable = ThrowableReach::Unknown): self
    {
        return new self($depth, ExternalChainOutcome::ReachedAnalysedName, null, $reachesThrowable, $fqcn);
    }

    public function isComplete(): bool
    {
        return $this->outcome === ExternalChainOutcome::ReachedRoot;
    }
}
