<?php

declare(strict_types=1);

namespace Qualimetrix\Architecture\Domain\Layer;

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
 * When {@see MembershipSpec::$exclude} is declared (Phase 2 direction 3), the
 * exclude clause is evaluated as a hard filter AFTER the positive match
 * succeeds. If the exclude criteria combine (per their own
 * {@see ExcludeSpec::$mode}) into a hit, {@see matches()} returns
 * {@see MembershipResult::noMatch()} — exclusion overrides positive match
 * regardless of either side's match mode. Excluded classes are
 * indistinguishable from non-matching classes at the rule layer; exclude
 * does not surface a separate descriptor.
 *
 * Under declaration-order resolution ({@see LayerRegistry}), layer entries are
 * scanned in declared order and the first matching entry decides the class's
 * layer (ADR 0006). Within each criterion list, entries are scanned in their
 * declared order and the first matching entry is recorded.
 *
 * Criterion-walking and per-pattern FQN matching are delegated to
 * {@see LayerCriteriaMatcher}, which is the single source of truth for
 * positive- and exclude-side evaluation alike.
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
     * Positive patterns with trailing backslashes stripped, used for matching.
     *
     * @var list<string>
     */
    private array $normalizedPatterns;

    /**
     * Exclude patterns with trailing backslashes stripped, used for matching.
     * Empty list when no exclude clause is declared or the exclude clause
     * has no pattern criteria.
     *
     * @var list<string>
     */
    private array $normalizedExcludePatterns;

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

        $this->normalizedPatterns = LayerCriteriaMatcher::normalizePatterns($membership->patterns);
        $this->normalizedExcludePatterns = $membership->exclude !== null
            ? LayerCriteriaMatcher::normalizePatterns($membership->exclude->patterns)
            : [];
    }

    /**
     * Static factory used by {@see \Qualimetrix\Architecture\Processing\LayerExpansionStage}
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
     * When {@see MembershipSpec::$exclude} is declared, the exclude clause
     * is evaluated AFTER positive criteria succeed and acts as a hard
     * filter — if exclusion fires (per its own {@see MatchMode}), the
     * result downgrades to {@see MembershipResult::noMatch()} regardless of
     * the positive match.
     *
     * An empty FQN is always a non-match. A {@see MembershipSpec} with all
     * five positive criterion lists empty cannot exist (constructor invariant).
     */
    public function matches(ClassContext $context): MembershipResult
    {
        if ($context->fqn === '') {
            return MembershipResult::noMatch();
        }

        $matched = LayerCriteriaMatcher::collectMatches(
            $context,
            $this->normalizedPatterns,
            $this->membership->patterns,
            $this->membership->suffix,
            $this->membership->attributes,
            $this->membership->implements,
            $this->membership->extends,
        );

        if ($matched === []) {
            return MembershipResult::noMatch();
        }

        if ($this->membership->mode === MatchMode::All) {
            $declaredKinds = LayerCriteriaMatcher::declaredKindCount(
                $this->membership->patterns,
                $this->membership->suffix,
                $this->membership->attributes,
                $this->membership->implements,
                $this->membership->extends,
            );
            if (\count($matched) !== $declaredKinds) {
                return MembershipResult::noMatch();
            }
        }

        if ($this->exclusionFires($context)) {
            return MembershipResult::noMatch();
        }

        return MembershipResult::match($matched);
    }

    /**
     * Returns true when the exclude clause is declared AND its criteria
     * combine into a hit under {@see ExcludeSpec::$mode}.
     */
    private function exclusionFires(ClassContext $context): bool
    {
        $exclude = $this->membership->exclude;
        if ($exclude === null) {
            return false;
        }

        $matched = LayerCriteriaMatcher::collectMatches(
            $context,
            $this->normalizedExcludePatterns,
            $exclude->patterns,
            $exclude->suffix,
            $exclude->attributes,
            $exclude->implements,
            $exclude->extends,
        );

        if ($matched === []) {
            return false;
        }

        if ($exclude->mode === MatchMode::Any) {
            return true;
        }

        $declaredKinds = LayerCriteriaMatcher::declaredKindCount(
            $exclude->patterns,
            $exclude->suffix,
            $exclude->attributes,
            $exclude->implements,
            $exclude->extends,
        );

        return \count($matched) === $declaredKinds;
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
