<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Architecture\Layer;

use RuntimeException;

/**
 * Thrown by {@see LayerRegistry::resolveLayer()} when a single class FQN
 * matches two or more layers with identical specificity, making the layer
 * assignment ambiguous.
 *
 * Carries the offending FQN and the full list of `[layerName, pattern]`
 * candidates so that callers (or configuration validators) can produce
 * actionable diagnostics.
 */
final class LayerCollisionException extends RuntimeException
{
    /**
     * @param list<array{0: string, 1: string}> $matches List of `[layerName, pattern]` tuples that tied on specificity.
     */
    public function __construct(
        private readonly string $fqn,
        private readonly array $matches,
    ) {
        parent::__construct($this->buildMessage());
    }

    public function getFqn(): string
    {
        return $this->fqn;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public function getMatches(): array
    {
        return $this->matches;
    }

    private function buildMessage(): string
    {
        $candidates = array_map(
            static fn(array $match): string => \sprintf('"%s" (pattern "%s")', $match[0], $match[1]),
            $this->matches,
        );

        return \sprintf(
            'Class "%s" matches multiple layers with equal specificity: %s. '
            . 'Refine the patterns so that at most one layer matches.',
            $this->fqn,
            implode(', ', $candidates),
        );
    }
}
