<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\LayerViolation;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\CoverageMode;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerDefinition;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Builds the five diagnostics that compare the declared layer policy against
 * what the run actually reached.
 *
 * They share one question — "does the declaration still describe the code?" —
 * and one answer shape: a project-subject finding that reports a mistake in
 * the configuration rather than debt in the code. None of them can be
 * accepted by a baseline. Four of the five also share {@see DIAGNOSTIC_SEVERITY}
 * instead of taking a severity option; `coverage()` is the exception, because
 * its severity has always come from the three-state `coverage:` mode
 * (`ignore`/`warn`/`error`) rather than a fixed value.
 *
 * - `architecture.coverage` — dependency-edge ends and analysed classes that
 *   fall outside every declared layer, seen through the mode that also
 *   classifies out-of-tree namespaces. That breadth is what makes the number
 *   unusable as a gate on one's own code — see `architecture.unassigned-class`
 *   in {@see UnassignedClassSummary} for the narrower one.
 * - `architecture.unreachable-layer` — a declared layer that was ASSIGNED
 *   nothing: no class and no dependency-edge end landed in it.
 * - `architecture.pending-layer-matched` — the opposite reading of the same
 *   evidence for a layer declared `pending: true`. Its predicate is MATCHED,
 *   not assigned, and {@see pendingLayersMatched()} explains why the
 *   difference is the whole point of the channel.
 * - `architecture.potential-shadow` — a layer that can never win in its own
 *   area because a broader one is declared earlier.
 * - `architecture.empty-template` — a template that expanded to no layers at
 *   all.
 *
 * Extracted from {@see LayerViolationRule}: the rule carries seven channels,
 * and the per-edge policy decision is the only one that needs the rule's own
 * options and collaborators. Keeping the declaration diagnostics here is what
 * lets it stay within its coupling ceiling.
 *
 * @internal Consumed by {@see LayerDeclarationValidator}.
 */
final class DeclaredLayerReachability
{
    private const int SHADOW_SAMPLE_LIMIT = 5;

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
     * @return list<Finding>
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

        $sampleList = DiagnosticSampleList::format($unmatched);

        return [new Finding(
            location: Location::none(),
            subject: MetricSubject::aggregate(SymbolPath::forProject()),
            symbolPath: SymbolPath::forProject(),
            ruleName: LayerPolicyPreparationInterface::COVERAGE_DIAGNOSTIC_NAME,
            code: LayerPolicyPreparationInterface::COVERAGE_DIAGNOSTIC_NAME,
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
     * Emits one diagnostic per declared layer whose criteria were assigned
     * neither a class nor a dependency-edge end during analysis.
     *
     * A layer declared `pending: true` is skipped: its author has said the
     * code does not exist yet, which is the one case where an empty layer is
     * not a mistake. {@see pendingLayersMatched()} is the counterpart that
     * keeps that declaration honest.
     *
     * @param list<LayerDefinition> $definitions In declaration order.
     * @param array<string, int> $assignedHits Local map (NOT a field) of layerName → hit
     *                                         count, merged by the caller from the
     *                                         per-class and the per-edge walk.
     *
     * @return list<Finding>
     */
    public static function unreachableLayers(array $definitions, array $assignedHits): array
    {
        $findings = [];

        foreach ($definitions as $definition) {
            $layerName = $definition->name();
            if ($definition->lifecycle->isPending() || ($assignedHits[$layerName] ?? 0) > 0) {
                continue;
            }

            $findings[] = self::projectDiagnostic(
                LayerPolicyPreparationInterface::UNREACHABLE_LAYER_DIAGNOSTIC_NAME,
                \sprintf(
                    'Layer "%s" was never matched during analysis. Possible causes: (1) it is shadowed by a broader layer earlier in the declaration order, (2) the declared criteria (%s) match no class in the analysed codebase. Run "qmx debug:layer-assignment <class>" to inspect specific classes.',
                    $layerName,
                    $definition->membership()->describe(),
                ),
                'Move the layer above any broader layer that captures its classes, remove the layer if its pattern intentionally covers no class, or declare "pending: true" if the code it describes has not been written yet.',
            );
        }

        return $findings;
    }

    /**
     * Emits one diagnostic per layer declared `pending: true` whose criteria
     * matched something after all.
     *
     * **The predicate is "matched", not "was assigned".** A pending layer
     * whose patterns match classes that a broader layer declared earlier
     * always wins has an assignment count of zero — and that is precisely the
     * case where the declaration lies loudest: the code exists, and the layer
     * meant to own it is silently stealing nothing while
     * `architecture.unreachable-layer`, the diagnostic that would have said
     * so, stays suppressed by the very flag. Counting assignments would make
     * the channel silent exactly where it is needed.
     *
     * **The number reported is how many distinct symbols the layer's criteria
     * matched** — a class counted once however many dependency edges it sits
     * at an end of. The caller counts a set for exactly that reason
     * ({@see LayerEvidenceCollector::tallyMatchedEnd()}): tallying every match
     * event instead reported edge multiplicity, so two classes joined by four
     * edges read as eight and the number answered no question anyone asks.
     *
     * @param list<LayerDefinition> $definitions In declaration order.
     * @param array<string, int> $matchedCounts Local map of layerName → number of DISTINCT
     *                                          symbols (analysed declarations and
     *                                          dependency-edge ends alike) whose criteria
     *                                          the layer matched, winning or not.
     *
     * @return list<Finding>
     */
    public static function pendingLayersMatched(array $definitions, array $matchedCounts): array
    {
        $findings = [];

        foreach ($definitions as $definition) {
            $layerName = $definition->name();
            $hits = $matchedCounts[$layerName] ?? 0;
            if (!$definition->lifecycle->isPending() || $hits === 0) {
                continue;
            }

            $findings[] = self::projectDiagnostic(
                LayerPolicyPreparationInterface::PENDING_LAYER_MATCHED_DIAGNOSTIC_NAME,
                \sprintf(
                    'Layer "%s" is declared "pending: true" — code not written yet — but its criteria (%s) matched %d distinct symbol(s) during analysis (each counted once, whether seen as an analysed declaration or as an end of a dependency edge). A match counts even when a layer declared earlier won the assignment, so the layer may own nothing while the code it describes already exists. Run "qmx debug:layer-assignment <class>" to inspect specific classes.',
                    $layerName,
                    $definition->membership()->describe(),
                    $hits,
                ),
                \sprintf(
                    'Remove "pending: true" from layer "%s". The code it was reserved for exists, so architecture.unreachable-layer is the safety net that flag is now suppressing.',
                    $layerName,
                ),
            );
        }

        return $findings;
    }

    /**
     * Emits one diagnostic per template name that produced zero concrete
     * layers during expansion (Phase 2 direction 2).
     *
     * The list is populated by
     * {@see \Qualimetrix\Analysis\Policy\Architecture\Layer\Expansion\LayerExpansionStage} and
     * surfaced through
     * {@see \Qualimetrix\Analysis\Policy\Architecture\ArchitecturePolicy::getPreparedConfiguration()}
     * on the configuration the rule reads.
     *
     * An empty template is a typo, a missing dependency in the scanned paths,
     * or a recent refactor that removed the matching classes — in every case
     * the declared configuration no longer describes the code, which is what
     * a {@see \Qualimetrix\Analysis\Finding\Contract\ConfigurationValidatorInterface}'s
     * channels name.
     *
     * @param list<string> $emptyTemplateNames
     *
     * @return list<Finding>
     */
    public static function emptyTemplates(array $emptyTemplateNames): array
    {
        $findings = [];

        foreach ($emptyTemplateNames as $template) {
            $findings[] = self::projectDiagnostic(
                LayerPolicyPreparationInterface::EMPTY_TEMPLATE_DIAGNOSTIC_NAME,
                \sprintf(
                    'Template layer "%s" expanded to zero concrete layers — no class in the analysed codebase '
                    . 'matched the template\'s criteria. Common causes: (1) a typo in the template name or '
                    . 'pattern, (2) matching classes were filtered out by file discovery (`exclude_paths` / '
                    . '`exclude_namespaces` at top level or in rule options), (3) the module disappeared in a '
                    . 'recent refactor, or (4) a single-segment capture `{var}` is used where the binding spans '
                    . 'multiple namespace segments — try `{var:**}` for cross-segment captures.',
                    $template,
                ),
                'Verify the template patterns against the project structure, or remove the template if no longer relevant.',
            );
        }

        return $findings;
    }

    /**
     * Emits one diagnostic per (assigned, shadowed) layer pair observed
     * during the class iteration.
     *
     * Determinism: `metrics->all()` iteration order is not stable under
     * parallel collection. The per-pair sample is sorted lexicographically by
     * FQN and the pair list is sorted by (assigned, shadowed) before emission
     * so CI diffs are stable across runs.
     *
     * Each evidence entry already carries the primary criterion that matched
     * on each side (recorded during the rule's class walk), so no second walk
     * over the layer list is necessary at emission time.
     *
     * @param array<string, array<string, list<array{fqn: string, assignedCriterion: \Qualimetrix\Analysis\Policy\Architecture\Layer\MatchedCriterion, shadowedCriterion: \Qualimetrix\Analysis\Policy\Architecture\Layer\MatchedCriterion}>>> $shadowEvidence
     *
     * @return list<Finding>
     */
    public static function potentialShadows(array $shadowEvidence): array
    {
        $findings = [];

        foreach (self::sortedShadowPairs($shadowEvidence) as $pair) {
            $assignedLayer = $pair['assigned'];
            $shadowedLayer = $pair['shadowed'];
            $entries = $pair['entries'];

            $sample = \array_slice($entries, 0, self::SHADOW_SAMPLE_LIMIT);
            $remaining = \count($entries) - \count($sample);

            $sampleList = implode(', ', array_map(static fn(array $entry): string => $entry['fqn'], $sample));
            if ($remaining > 0) {
                $sampleList .= \sprintf(' ...and %d more', $remaining);
            }

            $findings[] = self::projectDiagnostic(
                LayerPolicyPreparationInterface::POTENTIAL_SHADOW_DIAGNOSTIC_NAME,
                \sprintf(
                    'Layer "%s" (%s) shadows layer "%s" (%s) for %d class(es) including %s. Run "qmx debug:layer-assignment <class>" to inspect specific cases.',
                    $assignedLayer,
                    $sample[0]['assignedCriterion']->describe(),
                    $shadowedLayer,
                    $sample[0]['shadowedCriterion']->describe(),
                    \count($entries),
                    $sampleList,
                ),
                \sprintf(
                    'If layer "%s" should own these classes, declare it BEFORE "%s" (declaration order, first match wins). Otherwise tighten the patterns so the layers no longer overlap.',
                    $shadowedLayer,
                    $assignedLayer,
                ),
            );
        }

        return $findings;
    }

    /**
     * Flattens the evidence map into pairs ordered by (assigned, shadowed),
     * each with its own sample ordered by FQN.
     *
     * @param array<string, array<string, list<array{fqn: string, assignedCriterion: \Qualimetrix\Analysis\Policy\Architecture\Layer\MatchedCriterion, shadowedCriterion: \Qualimetrix\Analysis\Policy\Architecture\Layer\MatchedCriterion}>>> $shadowEvidence
     *
     * @return list<array{assigned: string, shadowed: string, entries: non-empty-list<array{fqn: string, assignedCriterion: \Qualimetrix\Analysis\Policy\Architecture\Layer\MatchedCriterion, shadowedCriterion: \Qualimetrix\Analysis\Policy\Architecture\Layer\MatchedCriterion}>}>
     */
    private static function sortedShadowPairs(array $shadowEvidence): array
    {
        $pairs = [];
        foreach ($shadowEvidence as $assigned => $shadowedMap) {
            foreach ($shadowedMap as $shadowed => $entries) {
                // A pair exists only once a reportable shadow was recorded
                // for it, so the entry list is non-empty by construction.
                \assert($entries !== []);
                usort($entries, static fn(array $a, array $b): int => strcmp($a['fqn'], $b['fqn']));
                $pairs[] = [
                    'assigned' => (string) $assigned,
                    'shadowed' => (string) $shadowed,
                    'entries' => $entries,
                ];
            }
        }

        usort($pairs, static function (array $a, array $b): int {
            $cmp = strcmp($a['assigned'], $b['assigned']);

            return $cmp !== 0 ? $cmp : strcmp($a['shadowed'], $b['shadowed']);
        });

        return $pairs;
    }

    /**
     * All four diagnostics judge the declaration as a whole, so they carry
     * the project subject and no location — there is no single line to point
     * at, and pointing at one would make the finding look file-scoped when
     * {@see LayerPolicyPreparationInterface::PROJECT_SCOPED_CHANNELS} says it is not.
     */
    private static function projectDiagnostic(string $channelName, string $message, string $recommendation): Finding
    {
        return new Finding(
            location: Location::none(),
            subject: MetricSubject::aggregate(SymbolPath::forProject()),
            symbolPath: SymbolPath::forProject(),
            ruleName: $channelName,
            code: $channelName,
            message: $message,
            severity: self::DIAGNOSTIC_SEVERITY,
            recommendation: $recommendation,
        );
    }

    /**
     * The severity every layer-declaration diagnostic reports.
     *
     * It is a constant, and the three options that used to set it per
     * channel are gone, because these channels belong to
     * {@see LayerDeclarationValidator}: they fail the run
     * without consulting `fail_on` and cannot be accepted by the ratchet, so
     * a severity knob would have controlled nothing but the word printed
     * beside the finding while looking exactly like a behaviour setting.
     * {@see Severity::Error} is what that behaviour actually is.
     */
    private const Severity DIAGNOSTIC_SEVERITY = Severity::Error;
}
