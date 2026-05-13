<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Architecture\Layer;

/**
 * Immutable Value Object describing a single architectural layer: a
 * human-readable name plus the list of namespace patterns whose classes
 * belong to that layer.
 *
 * Boolean membership check is provided via {@see matches()}. Diagnostics
 * that need to report which specific pattern matched a class (the debug
 * command, the `architecture.potential-shadow` diagnostic) call
 * {@see firstMatchingPattern()}.
 *
 * Under declaration-order matching ({@see LayerRegistry}), the layer's
 * patterns are scanned in declaration order; the first matching pattern
 * decides the class's layer. There is no specificity scoring — the user's
 * declaration order is the disambiguation rule.
 *
 * Note: {@see patternMatches()} intentionally duplicates the matching logic
 * from {@see \Qualimetrix\Core\Util\NamespaceMatcher} to avoid coupling
 * Core to that widely-used utility's API for a single-pattern, per-call
 * need. The two implementations must be kept behaviourally consistent.
 * Consolidation is tracked as a follow-up (see Step 2 of the architecture
 * rules follow-up plan).
 */
final readonly class LayerDefinition
{
    private const string NAME_REGEX = '/^[a-z][a-z0-9_-]*$/';

    private const array WILDCARD_CHARS = ['*', '?', '['];

    /**
     * Patterns with trailing backslashes stripped, used for matching.
     *
     * @var list<string>
     */
    private array $normalizedPatterns;

    /**
     * @param string $name Layer identifier — must match `[a-z][a-z0-9_-]*`.
     * @param list<string> $patterns Non-empty list of non-empty namespace patterns.
     *
     * @throws InvalidLayerDefinitionException If the name or any pattern is invalid.
     */
    public function __construct(
        public string $name,
        public array $patterns,
    ) {
        $this->validateName($name);
        $this->validatePatterns($patterns);

        $normalized = [];
        foreach ($patterns as $pattern) {
            $normalized[] = rtrim($pattern, '\\');
        }

        $this->normalizedPatterns = $normalized;
    }

    /**
     * Returns the layer name.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Returns the original (non-normalized) pattern list for diagnostics.
     *
     * @return list<string>
     */
    public function patterns(): array
    {
        return $this->patterns;
    }

    /**
     * Returns true when at least one of this layer's patterns matches `$fqn`.
     *
     * Empty `$fqn` is never a match.
     */
    public function matches(string $fqn): bool
    {
        if ($fqn === '') {
            return false;
        }

        foreach ($this->normalizedPatterns as $pattern) {
            if ($pattern === '') {
                continue;
            }

            if ($this->patternMatches($pattern, $fqn)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the FIRST pattern (in declaration order) that matches `$fqn`,
     * or null if no pattern matches.
     *
     * The returned string is the user-supplied original pattern (with any
     * trailing backslash preserved), so diagnostics quote the exact text
     * the user wrote in YAML.
     */
    public function firstMatchingPattern(string $fqn): ?string
    {
        if ($fqn === '') {
            return null;
        }

        foreach ($this->normalizedPatterns as $index => $pattern) {
            if ($pattern === '') {
                continue;
            }

            if ($this->patternMatches($pattern, $fqn)) {
                return $this->patterns[$index];
            }
        }

        return null;
    }

    private function patternMatches(string $pattern, string $fqn): bool
    {
        if (self::firstWildcardPosition($pattern) !== null) {
            return fnmatch($pattern, $fqn, \FNM_NOESCAPE);
        }

        return $fqn === $pattern || str_starts_with($fqn, $pattern . '\\');
    }

    private static function firstWildcardPosition(string $pattern): ?int
    {
        $minPosition = null;
        foreach (self::WILDCARD_CHARS as $wildcard) {
            $position = strpos($pattern, $wildcard);
            if ($position === false) {
                continue;
            }
            if ($minPosition === null || $position < $minPosition) {
                $minPosition = $position;
            }
        }

        return $minPosition;
    }

    private function validateName(string $name): void
    {
        if ($name === '') {
            throw new InvalidLayerDefinitionException('Layer name must not be empty.');
        }

        if (preg_match(self::NAME_REGEX, $name) !== 1) {
            throw new InvalidLayerDefinitionException(\sprintf(
                'Layer name "%s" must match pattern %s (lowercase letter followed by lowercase letters, digits, underscores, or hyphens).',
                $name,
                self::NAME_REGEX,
            ));
        }
    }

    /**
     * @param list<string> $patterns
     */
    private function validatePatterns(array $patterns): void
    {
        if ($patterns === []) {
            throw new InvalidLayerDefinitionException(\sprintf(
                'Layer "%s" must declare at least one pattern.',
                $this->name,
            ));
        }

        foreach ($patterns as $index => $pattern) {
            if (!\is_string($pattern)) {
                throw new InvalidLayerDefinitionException(\sprintf(
                    'Layer "%s" pattern at index %d must be a string, %s given.',
                    $this->name,
                    $index,
                    get_debug_type($pattern),
                ));
            }

            if ($pattern === '') {
                throw new InvalidLayerDefinitionException(\sprintf(
                    'Layer "%s" pattern at index %d must not be empty.',
                    $this->name,
                    $index,
                ));
            }
        }
    }
}
