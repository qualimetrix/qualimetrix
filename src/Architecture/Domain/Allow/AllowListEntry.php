<?php

declare(strict_types=1);

namespace Qualimetrix\Architecture\Domain\Allow;

/**
 * One row of the {@code architecture.allow} block: a source {@see LayerSelector}
 * and the list of {@see AllowTarget}s it is permitted to depend on.
 *
 * The entry list preserves user declaration order; {@see \Qualimetrix\Architecture\Domain\Layer\LayerPolicy}
 * walks it linearly and short-circuits on the first matching source-target
 * pair. Order is significant when glob / captured selectors overlap (e.g. an
 * exact entry and a broader glob entry for the same source name), but Step C's
 * boolean policy is order-insensitive — order matters in Step G where
 * {@code relations:} filters can carve out exceptions.
 */
final readonly class AllowListEntry
{
    /**
     * @param list<AllowTarget> $targets
     */
    public function __construct(
        public LayerSelector $source,
        public array $targets,
    ) {}
}
