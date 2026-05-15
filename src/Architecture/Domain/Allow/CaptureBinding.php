<?php

declare(strict_types=1);

namespace Qualimetrix\Architecture\Domain\Allow;

/**
 * Immutable map of capture-variable name → captured value, produced by
 * {@see LayerSelector::matchSource()} when a captured selector matches a
 * layer name. {@see LayerPolicy::isAllowed()} threads the binding into
 * {@see LayerSelector::matchesTarget()} so captured target selectors
 * substitute the bound values before matching.
 *
 * Exact and glob source selectors always emit an empty binding; captured
 * source selectors populate the map with one entry per
 * {@see SelectorSegment::capture()} segment in the parsed source string.
 *
 * Variable names follow the D4 grammar {@code [A-Za-z_][A-Za-z0-9_]*} and
 * are case-sensitive.
 */
final readonly class CaptureBinding
{
    /**
     * @param array<string, string> $values Map of variable name → captured value.
     */
    public function __construct(
        public array $values = [],
    ) {}

    public static function empty(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /**
     * Returns the captured value for {@code $variable}, or null when the
     * variable is not bound. Callers that depend on the value's existence
     * should treat {@code null} as "no binding" rather than "empty string".
     */
    public function get(string $variable): ?string
    {
        return $this->values[$variable] ?? null;
    }

    public function has(string $variable): bool
    {
        return \array_key_exists($variable, $this->values);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->values;
    }
}
