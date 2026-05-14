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
 * {@see MembershipResult}. The Match variant carries one {@see MatchedCriterion}
 * per criterion kind that fired (in declaration order: patterns, suffix,
 * attributes, implements, extends). {@see LayerRegistry::resolveAll()} feeds
 * the descriptor list into {@see LayerMatch} so the violation message and the
 * {@code architecture.potential-shadow} diagnostic can report WHICH criterion
 * caught the class.
 *
 * Under declaration-order resolution ({@see LayerRegistry}), layer entries are
 * scanned in declared order and the first matching entry decides the class's
 * layer (ADR 0006). Within each criterion list, entries are scanned in their
 * declared order and the first matching entry is recorded.
 *
 * Per-pattern FQN matching is delegated to {@see NamespaceMatcher::matchesSingle()}
 * so this class shares a single source of truth with the wider namespace
 * matching utility — no local copy of the glob-vs-prefix decision logic.
 */
final readonly class LayerDefinition
{
    /**
     * Strict regex applied to names declared directly in YAML (Phase-1
     * shape: lowercase, hyphens, underscores, digits). Keeps user-written
     * layer names predictable and grep-friendly.
     */
    private const string NAME_REGEX = '/^[a-z][a-z0-9_-]*$/';

    /**
     * Relaxed regex applied to names produced by template expansion (Phase 2
     * direction 2). Binding values are typically PascalCase namespace
     * segments ({@code Order}, {@code Audit}); requiring authors to
     * lowercase them in YAML would defeat the ergonomic point of templates.
     * Expansion-produced names still must start with a letter and contain
     * only letters, digits, hyphens, and underscores.
     */
    private const string EXPANDED_NAME_REGEX = '/^[A-Za-z][A-Za-z0-9_-]*$/';

    /**
     * Patterns with trailing backslashes stripped, used for matching.
     *
     * @var list<string>
     */
    private array $normalizedPatterns;

    /**
     * @param string $name Layer identifier — must match `[a-z][a-z0-9_-]*`
     *                     for user-declared layers, or
     *                     `[A-Za-z][A-Za-z0-9_-]*` for layers produced by
     *                     template expansion ({@see expanded()}).
     * @param MembershipSpec $membership Criteria carrying at least one
     *                                   non-empty list.
     * @param bool $expanded When true, the relaxed
     *                       {@see EXPANDED_NAME_REGEX} is applied; flag is
     *                       carried as a field because {@see LayerRegistry}
     *                       and the rule layer may want to surface it in
     *                       diagnostics (e.g. "{layer name} comes from
     *                       template expansion"). Phase D itself never
     *                       reads it.
     *
     * @throws InvalidLayerDefinitionException If the name is invalid.
     */
    public function __construct(
        public string $name,
        public MembershipSpec $membership,
        public bool $expanded = false,
    ) {
        $this->validateName($name, $expanded);

        $normalized = [];
        foreach ($membership->patterns as $pattern) {
            $normalized[] = rtrim($pattern, '\\');
        }

        $this->normalizedPatterns = $normalized;
    }

    /**
     * Static factory used by {@see \Qualimetrix\Analysis\Architecture\LayerExpansionStage}
     * when instantiating layers produced by template expansion. Applies the
     * relaxed {@see EXPANDED_NAME_REGEX} so PascalCase binding values do not
     * have to be lowercased.
     *
     * @throws InvalidLayerDefinitionException If the produced name still
     *                                         violates the relaxed regex
     *                                         (e.g. binding contains a
     *                                         backslash, dot, or starts
     *                                         with a digit).
     */
    public static function expanded(string $name, MembershipSpec $membership): self
    {
        return new self($name, $membership, true);
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
     * Walks the five criterion kinds in declaration order: patterns, suffix,
     * attributes, implements, extends. For each declared (non-empty) kind, the
     * first entry whose semantics matches the class produces a
     * {@see MatchedCriterion} descriptor.
     *
     * Under {@see MatchMode::Any} (default), the membership succeeds if at
     * least one declared kind produces a match. Under {@see MatchMode::All},
     * every declared kind must produce a match (empty kinds are trivially
     * satisfied and contribute nothing to the descriptor list).
     *
     * An empty FQN is always a non-match. A {@see MembershipSpec} with all
     * five criterion lists empty cannot exist (constructor invariant).
     */
    public function matches(ClassContext $context): MembershipResult
    {
        if ($context->fqn === '') {
            return MembershipResult::noMatch();
        }

        $patternMatch = $this->matchPatterns($context);
        $suffixMatch = $this->matchSuffix($context);
        $attributeMatch = $this->matchAttributes($context);
        $implementsMatch = $this->matchImplements($context);
        $extendsMatch = $this->matchExtends($context);

        $matched = array_values(array_filter(
            [$patternMatch, $suffixMatch, $attributeMatch, $implementsMatch, $extendsMatch],
            static fn(?MatchedCriterion $criterion): bool => $criterion !== null,
        ));

        if ($matched === []) {
            return MembershipResult::noMatch();
        }

        if ($this->membership->mode === MatchMode::All) {
            $declaredKinds = $this->declaredKindCount();
            if (\count($matched) !== $declaredKinds) {
                return MembershipResult::noMatch();
            }
        }

        return MembershipResult::match($matched);
    }

    private function matchPatterns(ClassContext $context): ?MatchedCriterion
    {
        if ($this->normalizedPatterns === []) {
            return null;
        }

        foreach ($this->normalizedPatterns as $index => $pattern) {
            if (NamespaceMatcher::matchesSingle($pattern, $context->fqn)) {
                return new MatchedCriterion(
                    MatchedCriterionKind::Pattern,
                    $this->membership->patterns[$index],
                );
            }
        }

        return null;
    }

    private function matchSuffix(ClassContext $context): ?MatchedCriterion
    {
        if ($this->membership->suffix === [] || $context->shortName === '') {
            return null;
        }

        foreach ($this->membership->suffix as $suffix) {
            if (str_ends_with($context->shortName, $suffix)) {
                return new MatchedCriterion(MatchedCriterionKind::Suffix, $suffix);
            }
        }

        return null;
    }

    private function matchAttributes(ClassContext $context): ?MatchedCriterion
    {
        if ($this->membership->attributes === [] || $context->attributeFqns === []) {
            return null;
        }

        $haystack = array_fill_keys($context->attributeFqns, true);
        foreach ($this->membership->attributes as $attributeFqn) {
            if (isset($haystack[$attributeFqn])) {
                return new MatchedCriterion(MatchedCriterionKind::Attribute, $attributeFqn);
            }
        }

        return null;
    }

    private function matchImplements(ClassContext $context): ?MatchedCriterion
    {
        if ($this->membership->implements === [] || $context->interfaces === []) {
            return null;
        }

        $haystack = array_fill_keys($context->interfaces, true);
        foreach ($this->membership->implements as $interfaceFqn) {
            if (isset($haystack[$interfaceFqn])) {
                return new MatchedCriterion(MatchedCriterionKind::Implements, $interfaceFqn);
            }
        }

        return null;
    }

    private function matchExtends(ClassContext $context): ?MatchedCriterion
    {
        if ($this->membership->extends === [] || $context->parentClasses === []) {
            return null;
        }

        $haystack = array_fill_keys($context->parentClasses, true);
        foreach ($this->membership->extends as $parentFqn) {
            if (isset($haystack[$parentFqn])) {
                return new MatchedCriterion(MatchedCriterionKind::Extends, $parentFqn);
            }
        }

        return null;
    }

    /**
     * Counts criterion kinds the spec actually declares (non-empty lists).
     * Used by {@see MatchMode::All} to enforce "every declared kind must
     * match" — empty kinds are trivially satisfied and excluded from both
     * the declared count and the matched count.
     */
    private function declaredKindCount(): int
    {
        $count = 0;
        if ($this->membership->patterns !== []) {
            $count++;
        }
        if ($this->membership->suffix !== []) {
            $count++;
        }
        if ($this->membership->attributes !== []) {
            $count++;
        }
        if ($this->membership->implements !== []) {
            $count++;
        }
        if ($this->membership->extends !== []) {
            $count++;
        }

        return $count;
    }

    private function validateName(string $name, bool $expanded): void
    {
        if ($name === '') {
            throw new InvalidLayerDefinitionException('Layer name must not be empty.');
        }

        $regex = $expanded ? self::EXPANDED_NAME_REGEX : self::NAME_REGEX;
        if (preg_match($regex, $name) === 1) {
            return;
        }

        $description = $expanded
            ? 'letter followed by letters, digits, underscores, or hyphens'
            : 'lowercase letter followed by lowercase letters, digits, underscores, or hyphens';

        throw new InvalidLayerDefinitionException(\sprintf(
            'Layer name "%s" must match pattern %s (%s).',
            $name,
            $regex,
            $description,
        ));
    }
}
