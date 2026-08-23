<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\LayerViolation;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\CoverageMode;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Builds the two per-run summaries of what fell outside every declared layer.
 *
 * They answer different questions and are gated separately, which is why both
 * exist and why neither can be derived from the other:
 *
 * - `architecture.coverage` counts dependency-edge ends as well as analysed
 *   classes, so it also sees code the project does not own — a target in
 *   `Symfony\...` is an unmatched end like any other. That breadth is the point
 *   for a policy that classifies out-of-tree namespaces, and it is exactly what
 *   makes the number unusable as a gate on one's own code.
 * - `architecture.unassigned-class` counts only declarations the run analysed,
 *   so it is the gate "every declaration I analysed is assigned to a layer".
 *
 * Extracted from {@see LayerViolationRule} rather than left beside the
 * per-edge and per-layer diagnostics: these two share their sample formatting
 * and their subject, and keeping them here is what lets the rule stay within
 * its own coupling and complexity ceilings while carrying every channel
 * {@see LayerViolationRule::channelDeclarations()} lists.
 *
 * @internal Consumed by {@see LayerViolationRule}.
 */
final class OutsideLayerSummary
{
    private const int SAMPLE_LIMIT = 10;

    /**
     * The `architecture.unassigned-class` channel declaration.
     *
     * A magnitude rather than an occurrence, and the absolute count rather
     * than the share the message also prints: a share normalised by project
     * size stands still while the number of unassigned declarations grows, and
     * tightens on its own when unrelated assigned declarations are deleted.
     * Only the count can be ratcheted down — ADR 0017's risk #5, the same
     * reason `coupling.class-rank` is deliberately an occurrence channel.
     *
     * Acceptability is the declaration default, `AcceptableAsDebt`, which is a
     * deliberate difference from the four configuration diagnostics of the same
     * rule. Those describe a policy that no longer matches the code, which is
     * never legitimate to accept. This gate is off by default and turned on
     * deliberately, so its findings are the ordinary debt of a policy still
     * being rolled out, and the ratchet is the only way to adopt it on a live
     * codebase.
     */
    public static function unassignedClassChannel(): ChannelDeclaration
    {
        return ChannelDeclaration::magnitude(WorseDirection::Higher, SymbolLevel::Project);
    }

    /**
     * The `architecture.coverage` diagnostic, or none when the mode declines
     * it or nothing was left out of a layer.
     *
     * Severity mirrors the mode name exactly (`warn` → {@see Severity::Warning},
     * `error` → {@see Severity::Error}) — an exhaustive `match` over the two
     * cases remaining after the {@see CoverageMode::Ignore} guard, rather than
     * a ternary, so a future {@see CoverageMode} case that isn't also added
     * here fails PHPStan's exhaustiveness check instead of silently falling
     * back to a stale default (fixed: `warn` used to report `Severity::Info`,
     * so `fail_on: warning` never caught it).
     *
     * @param array{sourceEdges: int, targetEdges: int, classes: array<string, string>} $state
     *
     * @return list<Violation>
     */
    public static function coverage(CoverageMode $mode, array $state): array
    {
        if ($mode === CoverageMode::Ignore) {
            return [];
        }

        $unmatched = array_values($state['classes']);
        if ($state['sourceEdges'] + $state['targetEdges'] === 0 && $unmatched === []) {
            return [];
        }

        $severity = match ($mode) {
            CoverageMode::Warn => Severity::Warning,
            CoverageMode::Error => Severity::Error,
        };

        $sampleList = self::sampleList($unmatched);

        return [new Violation(
            location: Location::none(),
            subject: MetricSubject::aggregate(SymbolPath::forProject()),
            symbolPath: SymbolPath::forProject(),
            ruleName: LayerPolicyPreparationInterface::COVERAGE_DIAGNOSTIC_NAME,
            violationCode: LayerPolicyPreparationInterface::COVERAGE_DIAGNOSTIC_NAME,
            message: \sprintf(
                'Architecture coverage: %d edge(s) with unmatched source layer, %d edge(s) with unmatched target layer, %d class(es) outside all declared layers.',
                $state['sourceEdges'],
                $state['targetEdges'],
                \count($unmatched),
            ),
            severity: $severity,
            recommendation: $sampleList === null
                ? 'Declare layers covering the remaining classes or accept the gap by leaving coverage on "ignore".'
                : 'Examples of unclassified classes: ' . $sampleList . '. Declare layers covering these classes or accept the gap by leaving coverage on "ignore".',
        )];
    }

    /**
     * The `architecture.unassigned-class` diagnostic, or none when the gate is
     * off or every analysed declaration is assigned.
     *
     * The zero-unassigned guard also covers the degenerate run that analysed
     * nothing at all, which is why the share is safe to compute below.
     *
     * @param array<string, string> $unassigned Canonical key => display FQN.
     *
     * @return list<Violation>
     */
    public static function unassignedClasses(
        UnassignedClassMode $mode,
        array $unassigned,
        int $analysedDeclarations,
    ): array {
        if ($mode === UnassignedClassMode::Ignore || $unassigned === []) {
            return [];
        }

        // Exhaustive for the same reason {@see coverage()} is.
        $severity = match ($mode) {
            UnassignedClassMode::Warn => Severity::Warning,
            UnassignedClassMode::Error => Severity::Error,
        };

        $count = \count($unassigned);

        return [new Violation(
            location: Location::none(),
            subject: MetricSubject::aggregate(SymbolPath::forProject()),
            symbolPath: SymbolPath::forProject(),
            ruleName: LayerPolicyPreparationInterface::UNASSIGNED_CLASS_DIAGNOSTIC_NAME,
            violationCode: LayerPolicyPreparationInterface::UNASSIGNED_CLASS_DIAGNOSTIC_NAME,
            message: \sprintf(
                '%d of %d analysed class-like declaration(s) (%.1f%%) are not assigned to any declared layer.',
                $count,
                $analysedDeclarations,
                $count / $analysedDeclarations * 100,
            ),
            severity: $severity,
            metricValue: $count,
            recommendation: 'Unassigned declarations: ' . (string) self::sampleList(array_values($unassigned))
                . '. Declare layers covering them, exclude them from analysis, or accept the current count in the'
                . ' baseline and reduce it over time.',
        )];
    }

    /**
     * Sorted so CI diffs stay stable: `metrics->all()` iteration order is not
     * stable under parallel collection.
     *
     * @param list<string> $fqns
     */
    private static function sampleList(array $fqns): ?string
    {
        if ($fqns === []) {
            return null;
        }

        sort($fqns);
        $sample = \array_slice($fqns, 0, self::SAMPLE_LIMIT);
        $remaining = \count($fqns) - \count($sample);

        $list = implode(', ', $sample);

        return $remaining > 0 ? $list . \sprintf(' ...and %d more', $remaining) : $list;
    }
}
