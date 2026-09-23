<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Layer;

/**
 * Immutable Value Object describing a single architectural layer: a
 * human-readable name plus the {@see MembershipSpec} that decides which
 * classes belong to it.
 *
 * Membership is evaluated by {@see matches()}, which returns a
 * {@see MembershipResult}. The Match variant carries one {@see MatchedCriterion}
 * per criterion kind that fired (in declaration order: patterns, suffix,
 * attributes, implements, extends). {@see LayerRegistry::resolveAll()} feeds
 * the descriptor list into {@see LayerMatch} so the finding message and the
 * {@code architecture.potential-shadow} diagnostic can report WHICH criterion
 * caught the class.
 *
 * When {@see MembershipSpec::$exclude} is declared, the
 * exclude clause is evaluated as a hard filter AFTER the positive match
 * succeeds. If the exclude criteria combine (per their own
 * {@see ExcludeSpec::$mode}) into a hit, {@see matches()} returns
 * {@see MembershipResult::excluded()} — exclusion overrides positive match
 * regardless of either side's match mode. Excluded classes are non-members
 * exactly like non-matching ones, and exclude surfaces no criterion
 * descriptor; the variant is separate only so
 * {@see LayerRegistry::excludedLayers()} can answer whether the clause ever
 * fired.
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
     * Strict regex applied to names declared directly in YAML (lowercase,
     * hyphens, underscores, digits). Keeps user-written
     * layer names predictable and grep-friendly.
     */
    private const string NAME_REGEX = '/^[a-z][a-z0-9_-]*$/';

    /**
     * Relaxed regex applied to names produced by template expansion.
     * Binding values are typically PascalCase namespace
     * segments ({@code Order}, {@code Audit}); requiring authors to
     * lowercase them in YAML would defeat the ergonomic point of templates.
     * Expansion-produced names still must start with a letter and contain
     * only letters, digits, hyphens, and underscores.
     */
    private const string EXPANDED_NAME_REGEX = '/^[A-Za-z][A-Za-z0-9_-]*$/';

    /**
     * @param string $name Layer identifier — must match `[a-z][a-z0-9_-]*`
     *                     for user-declared layers. For layers produced by
     *                     template expansion use the {@see expanded()} factory,
     *                     which applies the relaxed {@see EXPANDED_NAME_REGEX}
     *                     instead.
     * @param MembershipSpec $membership Criteria carrying at least one
     *                                   non-empty list.
     * @param LayerLifecycle $lifecycle Whether the layer describes code that
     *                                  exists yet — see {@see LayerLifecycle}.
     * @param bool $expanded Internal flag toggling the regex variant used
     *                       for name validation. Not exposed as a property —
     *                       no downstream code reads it after construction.
     * @param ?string $declaredAs The template this layer was expanded from,
     *                            or `null` for a layer written out in full.
     *                            A diagnostic about the *declaration* — an
     *                            `exclude:` clause the author wrote once —
     *                            must judge every instance the template
     *                            produced together, and after expansion the
     *                            instances are the only objects left.
     *
     * @throws InvalidLayerDefinitionException If the name is invalid.
     */
    public function __construct(
        public string $name,
        public MembershipSpec $membership,
        public LayerLifecycle $lifecycle = LayerLifecycle::Active,
        bool $expanded = false,
        private ?string $declaredAs = null,
    ) {
        $this->validateName($name, $expanded);
    }

    /**
     * Static factory used by {@see \Qualimetrix\Analysis\Policy\Architecture\Layer\Expansion\LayerExpansionStage}
     * when instantiating layers produced by template expansion. Applies the
     * relaxed {@see EXPANDED_NAME_REGEX} so PascalCase binding values do not
     * have to be lowercased.
     *
     * Expanded layers are always {@see LayerLifecycle::Active}: a concrete
     * instance exists only because a tuple was observed in the analysed code,
     * so it has matched something by construction.
     *
     * @throws InvalidLayerDefinitionException If the produced name still
     *                                         violates the relaxed regex
     *                                         (e.g. binding contains a
     *                                         backslash, dot, or starts
     *                                         with a digit).
     */
    public static function expanded(string $name, MembershipSpec $membership, ?string $declaredAs = null): self
    {
        return new self($name, $membership, expanded: true, declaredAs: $declaredAs);
    }

    /**
     * The declaration this layer came from: the template's name for an
     * expanded instance, the layer's own name otherwise.
     *
     * A judgement about what the author wrote — that an `exclude:` clause
     * removed nothing — is a judgement about this, not about the name. One
     * clause under `domain-{module}` becomes one instance per module, and
     * asking each instance separately accuses the author once per module that
     * happens to hold nothing to exclude.
     */
    public function declarationName(): string
    {
        return $this->declaredAs ?? $this->name;
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
     * result downgrades to {@see MembershipResult::excluded()} regardless of
     * the positive match. That variant is a non-match like any other for
     * every membership consumer; it is distinguishable only so that
     * `architecture.unmatched-exclude` can tell a clause that removed
     * something from one that removed nothing.
     *
     * Both combinations are three-valued: a positive kind the run has no facts
     * to decide ({@see CriterionOutcome::Undecidable}) neither makes the layer
     * match nor lets it report a non-match, and the result is
     * {@see MembershipResult::undecided()} — a non-member the run never
     * actually established. An exclude clause the run cannot decide does not
     * withdraw a match the positive criteria made: the result is
     * {@see MembershipResult::doubtedMatch()}, a member with the doubt
     * attached. See {@see CriteriaEvaluation::outcome()} for the rule and
     * {@see CriterionOutcome} for what produces the third state.
     *
     * An empty FQN is always a non-match. A {@see MembershipSpec} with all
     * five positive criterion lists empty cannot exist (constructor invariant).
     */
    public function matches(ClassContext $context): MembershipResult
    {
        if ($context->fqn === '') {
            return MembershipResult::noMatch();
        }

        $evaluation = self::evaluateMembership($context, $this->membership);
        $outcome = $evaluation->outcome(
            $this->membership->mode,
            LayerCriteriaMatcher::declaredKindCount(
                $this->membership->patterns,
                $this->membership->suffix,
                $this->membership->attributes,
                $this->membership->implements,
                $this->membership->extends,
            ),
        );

        if ($outcome !== CriterionOutcome::Matches) {
            return $outcome === CriterionOutcome::Undecidable
                ? MembershipResult::undecided()
                : MembershipResult::noMatch();
        }

        return match ($this->exclusionOutcome($context)) {
            CriterionOutcome::Matches => MembershipResult::excluded(),
            // The positive criteria caught the class and the clause that would
            // remove it cannot be answered. The match stands and carries the
            // doubt, as an unanswered earlier layer does beside a later match.
            CriterionOutcome::Undecidable => MembershipResult::doubtedMatch($evaluation->matched),
            CriterionOutcome::DoesNotMatch => MembershipResult::match($evaluation->matched),
        };
    }

    /**
     * Evaluates the positive criteria of an arbitrary membership spec.
     *
     * Public because template observation asks the same question of the same
     * spec before any concrete layer exists, and a second implementation of it
     * is how observation and matching drifted apart once already.
     *
     * @internal Consumed by {@see \Qualimetrix\Analysis\Policy\Architecture\Layer\Expansion\TupleExtractor}.
     */
    public static function evaluateMembership(ClassContext $context, MembershipSpec $membership): CriteriaEvaluation
    {
        return LayerCriteriaMatcher::evaluate(
            $context,
            $membership->patterns,
            $membership->suffix,
            $membership->attributes,
            $membership->implements,
            $membership->extends,
        );
    }

    /**
     * Whether the exclude clause fires, does not fire, or cannot be told
     * apart.
     *
     * @param list<string> $patterns Exclude patterns as the caller wants them
     *                               evaluated. Template observation passes the
     *                               substituted forms; a concrete layer passes
     *                               what the spec carries.
     *
     * @internal Consumed by {@see \Qualimetrix\Analysis\Policy\Architecture\Layer\Expansion\TupleExtractor}.
     */
    public static function excludeOutcome(ClassContext $context, ExcludeSpec $exclude, array $patterns): CriterionOutcome
    {
        $evaluation = LayerCriteriaMatcher::evaluate(
            $context,
            $patterns,
            $exclude->suffix,
            $exclude->attributes,
            $exclude->implements,
            $exclude->extends,
        );

        return $evaluation->outcome($exclude->mode, LayerCriteriaMatcher::declaredKindCount(
            $exclude->patterns,
            $exclude->suffix,
            $exclude->attributes,
            $exclude->implements,
            $exclude->extends,
        ));
    }

    private function exclusionOutcome(ClassContext $context): CriterionOutcome
    {
        $exclude = $this->membership->exclude;

        return $exclude === null
            ? CriterionOutcome::DoesNotMatch
            : self::excludeOutcome($context, $exclude, $exclude->patterns);
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
