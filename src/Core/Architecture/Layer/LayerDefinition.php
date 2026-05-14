<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Architecture\Layer;

use Qualimetrix\Core\Util\NamespaceMatcher;

/**
 * Immutable Value Object describing a single architectural layer: a
 * human-readable name plus the {@see MembershipSpec} that decides which
 * classes belong to it.
 *
 * Membership is evaluated by {@see matches()}, which returns a
 * {@see MembershipResult}. On a Match the result carries the FIRST matching
 * pattern (declaration order) so {@see LayerRegistry::resolveAll()} can
 * record it on {@see LayerMatch} for the {@code architecture.potential-shadow}
 * diagnostic and the {@code debug:layer-assignment} command.
 *
 * Under declaration-order resolution ({@see LayerRegistry}), the layer's
 * patterns are scanned in declaration order; the first matching pattern
 * decides the class's layer. There is no specificity scoring — the user's
 * declaration order is the disambiguation rule (see ADR 0006).
 *
 * Per-pattern matching is delegated to {@see NamespaceMatcher::matchesSingle()}
 * so this class shares a single source of truth with the wider namespace
 * matching utility — no local copy of the glob-vs-prefix decision logic.
 */
final readonly class LayerDefinition
{
    private const string NAME_REGEX = '/^[a-z][a-z0-9_-]*$/';

    /**
     * Patterns with trailing backslashes stripped, used for matching.
     *
     * @var list<string>
     */
    private array $normalizedPatterns;

    /**
     * @param string $name Layer identifier — must match `[a-z][a-z0-9_-]*`.
     * @param MembershipSpec $membership Criteria carrying at least one pattern.
     *
     * @throws InvalidLayerDefinitionException If the name is invalid.
     */
    public function __construct(
        public string $name,
        public MembershipSpec $membership,
    ) {
        $this->validateName($name);

        $normalized = [];
        foreach ($membership->patterns as $pattern) {
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
     * Returns the membership spec.
     */
    public function membership(): MembershipSpec
    {
        return $this->membership;
    }

    /**
     * Returns the original (non-normalized) pattern list for diagnostics.
     *
     * @return list<string>
     */
    public function patterns(): array
    {
        return $this->membership->patterns;
    }

    /**
     * Evaluates the membership criteria against the given class context.
     *
     * Step A walks only the {@code patterns} list and returns the FIRST
     * matching pattern (declaration order) on a Match. An empty FQN is
     * always a non-match.
     */
    public function matches(ClassContext $context): MembershipResult
    {
        if ($context->fqn === '') {
            return MembershipResult::noMatch();
        }

        foreach ($this->normalizedPatterns as $index => $pattern) {
            if (NamespaceMatcher::matchesSingle($pattern, $context->fqn)) {
                return MembershipResult::match($this->membership->patterns[$index]);
            }
        }

        return MembershipResult::noMatch();
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
}
