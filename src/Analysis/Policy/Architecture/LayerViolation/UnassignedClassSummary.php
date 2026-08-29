<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\LayerViolation;

use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Builds `architecture.unassigned-class`: the per-run count of analysed
 * declarations outside every declared layer — a verdict on the **code**, not
 * on the configuration.
 *
 * Kept apart from the per-edge diagnostic: this channel shares its sample
 * formatting with the unrelated `architecture.coverage` diagnostic (both
 * delegate to {@see DiagnosticSampleList}), but nothing else — it is debt a
 * project can record and pay down, gated by {@see UnassignedClassMode}, and
 * it is why this class has one consumer rather than sharing a class with the
 * five configuration-error diagnostics {@see LayerDeclarationValidator} owns.
 *
 * @internal Consumed by {@see UnassignedClassRule}.
 */
final class UnassignedClassSummary
{
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
     * The declaration keeps the default classification — findings acceptable
     * as debt — which is a deliberate difference from the five configuration
     * diagnostics {@see LayerDeclarationValidator} declares. A rule cannot
     * state the other classification at all: it follows from the producing
     * type, and this channel is declared by a rule. Those describe a policy
     * that no longer matches the code, which is never legitimate to accept. This
     * gate is off by default and turned on deliberately, so its findings are
     * the ordinary debt of a policy still being rolled out, and the ratchet is
     * the only way to adopt it on a live codebase.
     */
    public static function unassignedClassChannel(): ChannelDeclaration
    {
        return ChannelDeclaration::magnitude(WorseDirection::Higher, SymbolLevel::Project);
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
     * @return list<Finding>
     */
    public static function unassignedClasses(
        UnassignedClassMode $mode,
        array $unassigned,
        int $analysedDeclarations,
    ): array {
        if ($mode === UnassignedClassMode::Ignore || $unassigned === []) {
            return [];
        }

        // Exhaustive for the same reason {@see DeclaredLayerReachability::coverage()} is.
        $severity = match ($mode) {
            UnassignedClassMode::Warn => Severity::Warning,
            UnassignedClassMode::Error => Severity::Error,
        };

        $count = \count($unassigned);

        return [new Finding(
            location: Location::none(),
            subject: MetricSubject::aggregate(SymbolPath::forProject()),
            symbolPath: SymbolPath::forProject(),
            ruleName: LayerPolicyPreparationInterface::UNASSIGNED_CLASS_DIAGNOSTIC_NAME,
            code: LayerPolicyPreparationInterface::UNASSIGNED_CLASS_DIAGNOSTIC_NAME,
            message: \sprintf(
                '%d of %d analysed class-like declaration(s) (%.1f%%) are not assigned to any declared layer.',
                $count,
                $analysedDeclarations,
                $count / $analysedDeclarations * 100,
            ),
            severity: $severity,
            metricValue: $count,
            recommendation: 'Unassigned declarations: ' . (string) DiagnosticSampleList::format(array_values($unassigned))
                . '. Declare layers covering them, exclude them from analysis, or accept the current count in the'
                . ' baseline and reduce it over time.',
        )];
    }
}
