<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\FindingProjection;

use Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterStage;

/**
 * Every way a finding can fail to reach the report, named once so the
 * `suppressed` format has a closed vocabulary to publish against.
 *
 * Seven values, and the enum cannot answer with fewer: the five
 * {@see FindingFilterStage} cases plus the two halves of the per-rule
 * exclusion ledger ({@see \Qualimetrix\Analysis\Finding\Contract\RuleExclusionStats}
 * — `suppress_namespaces`/`suppress_namespace_channels` and `suppress_paths`,
 * configured under `rules: {<rule-name>: {...}}`). The ledger halves are
 * distinct from {@see PathSuppression}/{@see NamespaceSuppression} above: those
 * two run once, globally, inside {@see \Qualimetrix\Reporting\FindingProjection\FindingProjector};
 * the ledger runs per rule, inside rule execution itself, before a finding
 * ever reaches the projector. Collapsing the two into one "ledger" value
 * would discard a distinction the product already measures separately.
 *
 * {@see fromStage()} is exhaustive over {@see FindingFilterStage}, so
 * PHPStan's match-exhaustiveness check fails the build the moment a sixth
 * stage is declared without a matching case here — the enum cannot silently
 * fall behind the vocabulary it is derived from.
 *
 * The mapping there is deliberately not name-for-name. This enum publishes
 * what the report says happened to a finding, so it speaks the suppression
 * vocabulary of ADR 0047; {@see FindingFilterStage} names the pipeline stage
 * that did it and keeps its own `*Exclusion` spelling, which is why the two
 * enums have cases of the same standing under different names.
 */
enum SuppressionMechanism: string
{
    /** `@qmx-ignore` / `@qmx-ignore-file` / `@qmx-ignore-next-line`. */
    case Suppression = 'suppression';

    /** Global `suppress_paths` (config or `--suppress-path`). */
    case PathSuppression = 'path-suppression';

    /** Global `suppress_namespaces` (config or `--suppress-namespace`). */
    case NamespaceSuppression = 'namespace-suppression';

    /** The accepted-level ceiling (ADR 0017). */
    case Baseline = 'baseline';

    /** `--report=git:*` narrowing. */
    case GitScope = 'git-scope';

    /** Per-rule `suppress_namespaces` / `suppress_namespace_channels`. */
    case RuleNamespaceSuppression = 'rule-namespace-suppression';

    /** Per-rule `suppress_paths`. */
    case RulePathSuppression = 'rule-path-suppression';

    public static function fromStage(FindingFilterStage $stage): self
    {
        return match ($stage) {
            FindingFilterStage::Suppression => self::Suppression,
            FindingFilterStage::PathExclusion => self::PathSuppression,
            FindingFilterStage::NamespaceExclusion => self::NamespaceSuppression,
            FindingFilterStage::Baseline => self::Baseline,
            FindingFilterStage::GitScope => self::GitScope,
        };
    }

    /**
     * The two halves of the per-rule exclusion ledger — the mechanisms
     * {@see fromStage()} cannot reach because no {@see FindingFilterStage}
     * names them.
     *
     * @return list<self>
     */
    public static function ledgerHalves(): array
    {
        return [self::RuleNamespaceSuppression, self::RulePathSuppression];
    }
}
