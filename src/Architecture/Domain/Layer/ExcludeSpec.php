<?php

declare(strict_types=1);

namespace Qualimetrix\Architecture\Domain\Layer;

use InvalidArgumentException;

/**
 * Immutable specification of the criteria that DISQUALIFY a class from a
 * layer (Phase 2 direction 3 — see ADR 0007). Attached to a
 * {@see MembershipSpec} via its {@code exclude} field; evaluated by
 * {@see LayerDefinition::matches()} as a hard filter AFTER the positive
 * criteria succeed.
 *
 * The criterion shape mirrors {@see MembershipSpec} exactly (five criterion
 * lists + {@see MatchMode}) but {@see ExcludeSpec} cannot nest another
 * exclude — the filter is single-level. Within a single criterion kind, list
 * entries are always OR'd. Cross-kind combination is controlled by
 * {@see MatchMode}: {@see MatchMode::Any} (default) excludes if at least one
 * declared kind matches; {@see MatchMode::All} requires every declared kind
 * to match before exclusion fires.
 *
 * Construction enforces "at least one non-empty criterion list" — an
 * exclude clause that excludes nothing has no purpose and is almost
 * certainly a config error.
 */
final readonly class ExcludeSpec
{
    /**
     * @param list<string> $patterns FQN glob patterns. Empty when the
     *                               exclude relies on other criterion kinds.
     * @param list<string> $suffix Class-name suffixes. Short names only —
     *                             no backslash.
     * @param list<string> $attributes Attribute class FQNs (require a
     *                                 namespace separator).
     * @param list<string> $implements Interface FQNs (require a namespace
     *                                 separator).
     * @param list<string> $extends Parent-class FQNs (require a namespace
     *                              separator).
     * @param MatchMode $mode Cross-kind combination strategy. Defaults to
     *                        {@see MatchMode::Any} — exclude as soon as
     *                        any criterion fires.
     *
     * @throws InvalidArgumentException If every criterion list is empty or
     *                                  any entry is a non-string /
     *                                  empty-string.
     */
    public function __construct(
        public array $patterns = [],
        public array $suffix = [],
        public array $attributes = [],
        public array $implements = [],
        public array $extends = [],
        public MatchMode $mode = MatchMode::Any,
    ) {
        CriterionListValidator::validate('ExcludeSpec', 'patterns', $patterns);
        CriterionListValidator::validate('ExcludeSpec', 'suffix', $suffix);
        CriterionListValidator::validate('ExcludeSpec', 'attributes', $attributes);
        CriterionListValidator::validate('ExcludeSpec', 'implements', $implements);
        CriterionListValidator::validate('ExcludeSpec', 'extends', $extends);

        if ($patterns === [] && $suffix === [] && $attributes === [] && $implements === [] && $extends === []) {
            throw new InvalidArgumentException(
                'ExcludeSpec must declare at least one non-empty criterion list '
                . '(patterns, suffix, attributes, implements, or extends).',
            );
        }
    }
}
