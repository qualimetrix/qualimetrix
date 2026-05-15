<?php

declare(strict_types=1);

namespace Qualimetrix\Architecture\Domain\Layer;

/**
 * Controls how multi-kind membership criteria combine inside a single
 * {@see MembershipSpec}.
 *
 * Cross-kind combination only — within a single criterion kind, list entries
 * are always OR'd. {@see Any} (the default) means at least one declared
 * criterion kind must match; {@see All} requires every declared criterion
 * kind to match. A criterion that is empty/unset is trivially satisfied
 * under {@see All}.
 *
 * For template layers (Phase 2 direction 2), this enum controls only how
 * capture-producing criteria combine. Non-capturing criteria always act as
 * AND-filters regardless of mode — see ADR 0007, locked decision D7.
 */
enum MatchMode: string
{
    case Any = 'any';
    case All = 'all';
}
