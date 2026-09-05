<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\FindingProjection;

/**
 * A configured suppressor that excluded nothing in this run.
 *
 * A composition keyed by what fired cannot distinguish "this pattern exists
 * and matched zero findings" from "this pattern does not exist" — both are
 * simply absent from the list. Publishing the zero-count entries separately
 * is what makes a dead `suppress_paths` line (a typo'd path, a file the
 * project deleted) visible instead of silently indistinguishable from never
 * having been written.
 *
 * Scoped to the four pattern-based mechanisms — {@see SuppressionMechanism::PathExclusion},
 * {@see SuppressionMechanism::NamespaceExclusion} and their per-rule ledger
 * counterparts — because those are the only ones a configured entry
 * enumerates independently of whether it fired. A `@qmx-ignore` directive
 * that silenced nothing is a different question (`annotation.unused-directive`,
 * Ш8's audit); a baseline entry nothing measured is already reported as a
 * stale entry (ADR 0017) with its own remediation story.
 */
final readonly class InertSuppressor
{
    public function __construct(
        public SuppressionMechanism $mechanism,
        public string $suppressor,
    ) {}
}
