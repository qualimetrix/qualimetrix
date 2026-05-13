<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Architecture\Layer;

/**
 * Immutable Value Object describing a single architectural layer: a human-readable
 * name plus the list of namespace patterns whose classes belong to that layer.
 *
 * Two responsibilities:
 * 1. Boolean membership check via {@see match()}.
 * 2. Specificity computation: how "tight" the matching pattern is. Specificity is
 *    used by {@see LayerRegistry} to disambiguate between overlapping layers
 *    (e.g. `App\**` vs `App\Service\**` — the latter is more specific).
 *
 * Specificity semantics:
 * - A pattern's specificity is the length (in characters) of its literal prefix
 *   before the first wildcard character (`*`, `?`, `[`).
 * - For a pattern with no wildcards (pure prefix mode), specificity equals the
 *   pattern's full length after trailing-backslash normalization.
 * - If the layer holds several patterns and more than one matches, the highest
 *   specificity wins. The maximum value is returned.
 *
 * Specificity limitation:
 * - Specificity is computed as the length of the literal prefix before the first
 *   wildcard character (`*`, `?`, `[`). This means `App\**\Foo` and `App\**\Bar`
 *   have the same specificity (4). When two layers have patterns with identical
 *   prefix-specificity that overlap, a {@see LayerCollisionException} is thrown
 *   at resolution time. Users should design patterns with unique literal prefixes
 *   (e.g., `App\Service\**\Repository` vs `App\Repository\**`).
 *
 * Note: {@see patternMatches()} intentionally duplicates the matching logic from
 * {@see \Qualimetrix\Core\Util\NamespaceMatcher} to avoid coupling Core to that
 * widely-used utility's API for a single-pattern, per-call need. The two
 * implementations must be kept behaviourally consistent.
 */
final readonly class LayerDefinition
{
    private const string NAME_REGEX = '/^[a-z][a-z0-9_-]*$/';

    private const array WILDCARD_CHARS = ['*', '?', '['];

    /**
     * Per-pattern specificity, indexed parallel to {@see $normalizedPatterns}.
     *
     * @var list<int>
     */
    private array $specificities;

    /**
     * Patterns with trailing backslashes stripped, used for matching/specificity.
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
        $specificities = [];
        foreach ($patterns as $pattern) {
            $normalizedPattern = rtrim($pattern, '\\');
            $normalized[] = $normalizedPattern;
            $specificities[] = self::specificityOf($normalizedPattern);
        }

        $this->normalizedPatterns = $normalized;
        $this->specificities = $specificities;
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
     * Returns the specificity of the most specific pattern that matches `$fqn`,
     * or null if no pattern matches.
     *
     * Specificity is always a positive integer when a match exists — patterns
     * are required to be non-empty by the constructor.
     */
    public function match(string $fqn): ?int
    {
        if ($fqn === '') {
            return null;
        }

        $best = null;
        foreach ($this->normalizedPatterns as $index => $pattern) {
            if ($pattern === '' || !$this->patternMatches($pattern, $fqn)) {
                continue;
            }

            $specificity = $this->specificities[$index];
            if ($best === null || $specificity > $best) {
                $best = $specificity;
            }
        }

        return $best;
    }

    /**
     * Computes specificity for a single normalized pattern.
     */
    private static function specificityOf(string $pattern): int
    {
        $firstWildcard = self::firstWildcardPosition($pattern);

        if ($firstWildcard === null) {
            return \strlen($pattern);
        }

        return $firstWildcard;
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

    private function patternMatches(string $pattern, string $fqn): bool
    {
        if (self::firstWildcardPosition($pattern) !== null) {
            return fnmatch($pattern, $fqn, \FNM_NOESCAPE);
        }

        return $fqn === $pattern || str_starts_with($fqn, $pattern . '\\');
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
