<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Architecture\Layer;

use InvalidArgumentException;

/**
 * Immutable specification of the criteria a class must satisfy to belong to
 * a layer.
 *
 * Step A carries only the {@code patterns} criterion (FQN globs) plus the
 * {@see MatchMode}. Step B adds {@code suffix}, {@code attributes},
 * {@code implements} and {@code extends}; Step F adds the optional
 * {@code exclude} clause. The final shape is documented in
 * {@see /docs/internal/plans/architecture-rules-phase2.md} Direction 1.
 *
 * Within a single criterion kind, list entries are always OR'd
 * ({@code patterns: ['A', 'B']} means "FQN matches A or B"). The
 * {@see MatchMode} only controls cross-kind combination.
 *
 * Validation at construction time enforces the documented invariant: at
 * least one non-empty criterion list. Step A enforces this through the
 * single {@code patterns} list; Step B will broaden the invariant to "at
 * least one of the five criterion lists is non-empty".
 */
final readonly class MembershipSpec
{
    /**
     * @param list<string> $patterns Non-empty list of FQN glob patterns.
     *                               Pattern semantics are delegated to
     *                               {@see \Qualimetrix\Core\Util\NamespaceMatcher::matchesSingle()}.
     * @param MatchMode $mode How multi-kind criteria combine. Single-kind
     *                        specs in Step A see no observable effect
     *                        from this flag.
     *
     * @throws InvalidArgumentException If {@code $patterns} is empty or contains
     *                                  a non-string / empty-string entry.
     */
    public function __construct(
        public array $patterns,
        public MatchMode $mode = MatchMode::Any,
    ) {
        if ($patterns === []) {
            throw new InvalidArgumentException(
                'MembershipSpec must declare at least one non-empty criterion list.',
            );
        }

        foreach ($patterns as $index => $pattern) {
            if (!\is_string($pattern)) {
                throw new InvalidArgumentException(\sprintf(
                    'MembershipSpec pattern at index %d must be a string, %s given.',
                    $index,
                    get_debug_type($pattern),
                ));
            }

            if ($pattern === '') {
                throw new InvalidArgumentException(\sprintf(
                    'MembershipSpec pattern at index %d must not be empty.',
                    $index,
                ));
            }
        }
    }
}
