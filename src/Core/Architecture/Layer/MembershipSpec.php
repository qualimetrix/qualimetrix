<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Architecture\Layer;

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
 * the five criterion lists must be non-empty. Step F (Phase 2 direction 3) will
 * relax this by also accepting a spec carrying only an `exclude` clause.
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
    ) {
        $this->validateList('patterns', $patterns);
        $this->validateList('suffix', $suffix);
        $this->validateList('attributes', $attributes);
        $this->validateList('implements', $implements);
        $this->validateList('extends', $extends);

        if ($patterns === [] && $suffix === [] && $attributes === [] && $implements === [] && $extends === []) {
            throw new InvalidArgumentException(
                'MembershipSpec must declare at least one non-empty criterion list '
                . '(patterns, suffix, attributes, implements, or extends).',
            );
        }
    }

    /**
     * @param list<mixed> $values
     */
    private function validateList(string $kind, array $values): void
    {
        foreach ($values as $index => $value) {
            if (!\is_string($value)) {
                throw new InvalidArgumentException(\sprintf(
                    'MembershipSpec %s[%d] must be a string, %s given.',
                    $kind,
                    $index,
                    get_debug_type($value),
                ));
            }

            if ($value === '') {
                throw new InvalidArgumentException(\sprintf(
                    'MembershipSpec %s[%d] must not be empty.',
                    $kind,
                    $index,
                ));
            }
        }
    }
}
