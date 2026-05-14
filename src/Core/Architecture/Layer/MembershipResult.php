<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Architecture\Layer;

/**
 * Outcome of {@see LayerDefinition::matches()}.
 *
 * Step A carries only the matched pattern string on the Match variant —
 * just enough for {@see LayerRegistry::resolveAll()} to feed
 * {@see LayerMatch} and the {@code architecture.potential-shadow}
 * diagnostic. Step B will extend the Match variant with a list of matched
 * criterion descriptors so the violation message can report WHICH criterion
 * caught the class under {@code match: any} semantics (Direction 1).
 *
 * Modelled as a single value object with two static factories rather than a
 * sealed hierarchy: the field count is small and the additional indirection
 * adds no clarity. The {@see matched} flag is the discriminant; the
 * {@see matchedPattern} field is {@code null} on the NoMatch variant.
 */
final readonly class MembershipResult
{
    private function __construct(
        public bool $matched,
        public ?string $matchedPattern,
    ) {}

    /**
     * Returns a Match result carrying the FIRST pattern (in declaration order)
     * that satisfied the layer's membership criteria.
     */
    public static function match(string $pattern): self
    {
        return new self(true, $pattern);
    }

    public static function noMatch(): self
    {
        return new self(false, null);
    }
}
