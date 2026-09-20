<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Design\Inheritance;

/**
 * How deep the part of a chain outside the analysed path went, and whether that
 * number is the whole answer.
 *
 * `$depth` is what was actually walked. When the outcome is not
 * {@see ExternalChainOutcome::ReachedRoot} the number is a floor rather than a
 * measurement: the chain continues somewhere this run could not read.
 */
final readonly class ExternalDepth
{
    private function __construct(
        public int $depth,
        public ExternalChainOutcome $outcome,
        /** The class the walk could not place, when it stopped early. */
        public ?string $unresolved = null,
    ) {}

    public static function reachedRoot(int $depth): self
    {
        return new self($depth, ExternalChainOutcome::ReachedRoot);
    }

    public static function noMap(): self
    {
        return new self(0, ExternalChainOutcome::NoMapForIt);
    }

    public static function brokeAt(int $depth, string $fqcn): self
    {
        return new self($depth, ExternalChainOutcome::BrokeAt, $fqcn);
    }

    public function isComplete(): bool
    {
        return $this->outcome === ExternalChainOutcome::ReachedRoot;
    }
}
