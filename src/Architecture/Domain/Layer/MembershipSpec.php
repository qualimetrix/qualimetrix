<?php

declare(strict_types=1);

namespace Qualimetrix\Architecture\Domain\Layer;

use InvalidArgumentException;

/**
 * Immutable specification of the criteria a class must satisfy to belong to
 * a layer.
 *
 * Five criterion kinds (Phase 2 direction 1 — see ADR 0007):
 *
 * | Field        | Semantics                                                                                |
 * | ------------ | ---------------------------------------------------------------------------------------- |
 * | `patterns`   | FQN glob patterns; matched via {@see \Qualimetrix\Core\Util\NamespaceMatcher::matchesSingle()} |
 * | `suffix`     | Short-name suffixes ({@code 'Repository'}); matched via {@code str_ends_with()}          |
 * | `attributes` | Attribute FQNs; class has {@code #[Attr]}                                                 |
 * | `implements` | Interface FQNs; class implements the interface directly or transitively                 |
 * | `extends`    | Parent-class FQNs; class extends the parent directly or transitively                    |
 *
 * Within a single criterion kind, list entries are always OR'd
 * ({@code patterns: ['A', 'B']} means "FQN matches A or B"). Cross-kind
 * combination is controlled by {@see MatchMode}: {@see MatchMode::Any} (default)
 * succeeds if at least one declared criterion kind matches; {@see MatchMode::All}
 * requires every declared criterion kind to match. An empty/unset criterion is
 * trivially satisfied under {@see MatchMode::All}.
 *
 * Validation at construction enforces the documented invariant: at least one of
 * the five criterion lists must be non-empty. An exclude-only layer would have
 * no classes (exclude filters from a non-empty positive set), so the invariant
 * remains tight regardless of whether {@see $exclude} is declared.
 */
final readonly class MembershipSpec
{
    /**
     * @param list<string> $patterns FQN glob patterns. Empty when the layer
     *                               relies on other criterion kinds.
     * @param list<string> $suffix Class-name suffixes. Short names only — no
     *                             backslash. Validation lives in
     *                             {@see \Qualimetrix\Configuration\Architecture\Validation\LayersValidator}.
     * @param list<string> $attributes Attribute class FQNs. Validation enforces
     *                                 the presence of at least one segment
     *                                 (no short names).
     * @param list<string> $implements Interface class FQNs. Same FQN requirement
     *                                 as {@code attributes}.
     * @param list<string> $extends Parent-class FQNs. Same FQN requirement
     *                              as {@code attributes}.
     * @param MatchMode $mode Cross-kind combination strategy. Defaults to
     *                        {@see MatchMode::Any} (migration-friendly).
     * @param ExcludeSpec|null $exclude Optional hard-filter clause (Phase 2
     *                                  direction 3). When set, evaluated by
     *                                  {@see LayerDefinition::matches()}
     *                                  AFTER the positive criteria succeed
     *                                  — if exclude fires, the class does
     *                                  NOT belong to the layer regardless of
     *                                  the positive match. The exclude
     *                                  clause's own {@see MatchMode} governs
     *                                  how its criteria combine.
     *
     * @throws InvalidArgumentException If every criterion list is empty or any
     *                                  entry is a non-string / empty-string.
     */
    public function __construct(
        public array $patterns = [],
        public array $suffix = [],
        public array $attributes = [],
        public array $implements = [],
        public array $extends = [],
        public MatchMode $mode = MatchMode::Any,
        public ?ExcludeSpec $exclude = null,
    ) {
        CriterionListValidator::validate('MembershipSpec', 'patterns', $patterns);
        CriterionListValidator::validate('MembershipSpec', 'suffix', $suffix);
        CriterionListValidator::validate('MembershipSpec', 'attributes', $attributes);
        CriterionListValidator::validate('MembershipSpec', 'implements', $implements);
        CriterionListValidator::validate('MembershipSpec', 'extends', $extends);

        if ($patterns === [] && $suffix === [] && $attributes === [] && $implements === [] && $extends === []) {
            throw new InvalidArgumentException(
                'MembershipSpec must declare at least one non-empty criterion list '
                . '(patterns, suffix, attributes, implements, or extends).',
            );
        }
    }
}
