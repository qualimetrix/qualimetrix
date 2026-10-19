<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Layer;

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
 * Both readings are three-valued, because a criterion kind may also be
 * unanswerable: see {@see CriteriaEvaluation::outcome()}, which is the single
 * place either rule is applied.
 *
 * The same rules hold for a template layer. An earlier docblock here said the
 * opposite — that non-capturing criteria always act as AND-filters whatever the
 * mode — and cited ADR 0059, which carries no statement about criterion
 * combination at all; the implementation has been mode-aware since 0.18. What
 * a template adds is a refusal rather than an exception:
 * {@see \Qualimetrix\Analysis\Policy\Architecture\Configuration\LayersValidator}
 * rejects a non-pattern criterion under {@see Any} on a template, because only
 * patterns carry the capture variables that would bind it to an instance.
 */
enum MatchMode: string
{
    case Any = 'any';
    case All = 'all';
}
