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
 * Builds four of the five diagnostics that compare the declared layer policy
 * against what the run actually reached. The fifth,
 * `architecture.potential-shadow`, renders shadow evidence no other verdict
 * reads, and is built by {@see PotentialShadowDiagnostic}.
 *
 * They share one question — "does the declaration still describe the code?" —
 * and one answer shape: a project-subject finding that reports a mistake in
 * the configuration rather than debt in the code. None of them can be
 * accepted by a baseline. The three besides `coverage()` share {@see DIAGNOSTIC_SEVERITY}
 * instead of taking a severity option; `coverage()` is the exception, because
 * its severity has always come from the three-state `coverage-gap:` mode
 * (`ignore`/`warn`/`error`) rather than a fixed value.
 *
 * - `architecture.coverage-gap` — dependency-edge ends and analysed classes that
 *   fall outside every declared layer, seen through the mode that also
 *   classifies out-of-tree namespaces. That breadth is what makes the number
 *   unusable as a gate on one's own code — see `architecture.unassigned-class`
 *   in {@see UnassignedClassSummary} for the narrower one.
 * - `architecture.unreachable-layer` — a declared layer that was ASSIGNED
 *   nothing and could not have been: no class and no dependency-edge end
 *   landed in it, and no analysed class would once the run answered what it
 *   could not.
 * - `architecture.pending-layer-matched` — the opposite reading of the same
 *   evidence for a layer declared `pending: true`. Its predicate is MATCHED,
 *   not assigned, and {@see pendingLayersMatched()} explains why the
 *   difference is the whole point of the channel.
 * - `architecture.empty-template` — a template that expanded to no layers at
 *   all.
 *
 * `architecture.doubted-assignment`, the rule's count of what the run could
 * not decide, reads the same population `coverage()` prints its undecided
 * sentences from, and is built by {@see DoubtedAssignmentDiagnostic}: it is
 * the rule's channel, not a configuration error.
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
    /**
     * The `architecture.coverage-gap` diagnostic, or none when the mode declines
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
     * **Why the message can carry a second number.** A class outside every
     * layer used to mean one thing: no declared criterion caught it, and
     * writing a layer closes the gap. It now means two, and the difference
     * matters to the reader who acts on it — a class whose `extends` or
     * `implements` chain leaves the analysed set, or one seen only as the far
     * end of a dependency edge, is outside every layer because the run could
     * not answer, and no `layers:` entry the author writes will change that.
     * The clause appears only when such a class exists, so a project with none
     * reads exactly the sentence it always did.
     *
     * **Why an assignment in doubt names itself here but never causes the
     * finding.** A class that stands assigned while a layer bearing on its
     * assignment went unanswered is not a hole in the declaration: it is in a
     * layer, its edges are judged, and no `layers:` entry is missing. This
     * channel is a configuration error that fails the run whatever its
     * severity, so letting a doubt raise it failed every fully covered project
     * whose earlier `extends`/`implements` layer met a vendor class at the far
     * end of an edge. The doubt is counted in the text when the gap exists for
     * its own reasons, and is published on its own, at a severity that never
     * gates, by {@see DoubtedAssignmentDiagnostic}.
     *
     * @param array{sourceEdges: int, targetEdges: int, classes: array<string, string>, undecidable: array<string, string>, undecidableOutsidePaths: array<string, string>, doubted: array<string, string>, doubtedOutsidePaths: array<string, string>} $state
     *
     * @return list<Finding>
     */
    public static function coverage(CoverageMode $mode, array $state): array
    {
        if ($mode === CoverageMode::Ignore) {
            return [];
        }

        $unmatched = array_values($state['classes']);
        $doubted = array_values($state['doubted']);
        if ($state['sourceEdges'] + $state['targetEdges'] === 0 && $unmatched === []) {
            return [];
        }

        $severity = match ($mode) {
            CoverageMode::Warn => Severity::Warning,
            CoverageMode::Error => Severity::Error,
        };

        $undecidable = array_values($state['undecidable']);

        return [new Finding(
            location: Location::none(),
            subject: MetricSubject::aggregate(SymbolPath::forProject()),
            symbolPath: SymbolPath::forProject(),
            ruleName: LayerPolicyPreparationInterface::COVERAGE_DIAGNOSTIC_NAME,
            code: LayerPolicyPreparationInterface::COVERAGE_DIAGNOSTIC_NAME,
            message: \sprintf(
                'Architecture coverage-gap: %d edge(s) with unmatched source layer, %d edge(s) with unmatched target layer, %d class(es) outside all declared layers.%s',
                $state['sourceEdges'],
                $state['targetEdges'],
                \count($unmatched),
                self::sampledSentence(
                    ' %d of them could not be decided: a declared "extends"/"implements"/"attributes" criterion'
                    . ' reads facts this run did not collect, because the symbol or a link in its inheritance chain'
                    . ' is outside the analysed paths — for example %s.',
                    $undecidable,
                ) . self::sampledSentence(
                    ' %d assigned class(es) rest on a layer the run could not fully decide — for example %s.',
                    $doubted,
                ),
            ),
            severity: $severity,
            recommendation: self::decidedRecommendation(array_values(array_diff_key($state['classes'], $state['undecidable'])))
                . self::undecidableRecommendation(\count($undecidable), \count($state['undecidableOutsidePaths']))
                . ($doubted === [] ? '' : ' The assignments in doubt are not part of the gap and do not fail the run:'
                    . ' each stands and its edges are judged against its layer\'s allow-list;'
                    . ' architecture.doubted-assignment names them and what settles each.'),
        )];
    }

    /**
     * The recommendation for the classes every declared criterion answered
     * "no" about — the only share of the gap that declaring a layer closes
     * outright. When there are none, the sentence says only how to accept the
     * gap, because advising a layer "covering these classes" would be advice
     * for a case the reader does not have.
     *
     * @param list<string> $decided
     */
    private static function decidedRecommendation(array $decided): string
    {
        $sampleList = DiagnosticSampleList::format($decided);

        return $sampleList === null
            ? 'Leaving coverage-gap on "ignore" accepts the gap.'
            : 'Examples of unclassified classes: ' . $sampleList . '. Declare layers covering these classes or accept the gap by leaving coverage-gap on "ignore".';
    }

    /**
     * `3 analysed class(es), 2 outside the analysed paths`, naming only the
     * labels whose count is not zero.
     *
     * @param array<string, int> $counts label => count
     */
    private static function presentCounts(array $counts): string
    {
        $present = array_filter($counts, static fn(int $count): bool => $count !== 0);

        return implode(', ', array_map(
            static fn(string $label, int $count): string => $count . ' ' . $label,
            array_keys($present),
            $present,
        ));
    }

    /**
     * A sentence counting a set of symbols and naming a sample of them, or the
     * empty string when the set is empty. Two sentences of the gap take this
     * shape: the undecided share, and the assignments in doubt — each only
     * when such a symbol exists, so a project with none reads exactly the
     * sentence it always did.
     *
     * @param string $format `sprintf` format taking the count and the sample.
     * @param list<string> $fqns
     */
    private static function sampledSentence(string $format, array $fqns): string
    {
        if ($fqns === []) {
            return '';
        }

        return \sprintf($format, \count($fqns), DiagnosticSampleList::format($fqns));
    }

    /**
     * What each edit does to the undecided share. A later layer is named
     * because it does cover these classes — an unanswered layer does not
     * withdraw a later match — and because what it covers them with is a
     * guess the reader has to know about. What decides them differs by kind,
     * so each kind present gets its own sentence and an absent kind gets
     * none: an analysed class is decided by completing its chain, which
     * `debug:layer-assignment` locates, while a symbol outside the paths
     * cannot be inspected there at all — the command refuses a class the run
     * did not analyse — and is decided only by a layer reading its name.
     */
    private static function undecidableRecommendation(int $undecidable, int $outside): string
    {
        if ($undecidable === 0) {
            return '';
        }

        $recommendation = ' For the undecided ones, a layer declared after the one that could not answer (a catch-all'
            . ' included) covers them, but as a guess: each may belong to the unanswered layer.';
        if ($undecidable > $outside) {
            $recommendation .= ' For an analysed class, widening paths to include the declaration where its chain stops'
                . ' decides it — "qmx debug:layer-assignment <class>" names that declaration.';
        }
        if ($outside > 0) {
            $recommendation .= ' A symbol outside the analysed paths is decided by analysing it when it is your own code'
                . ' — widening paths to include it — and otherwise by a patterns layer for its namespace declared before'
                . ' the layer that could not answer.';
        }

        return $recommendation;
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
     * So is a layer that could still own an analysed class whose assignment
     * the run could not decide — one a match with an unanswered `exclude:`
     * stands in front of, or one it could not answer about while a type its
     * criteria name is one the run met. "Matches no class" and "shadowed" are
     * conclusions the run did not reach there, and this channel fails the
     * run; `architecture.doubted-assignment` names the layer instead.
     * {@see \Qualimetrix\Analysis\Policy\Architecture\LayerViolation\Observation\LayerEvidence::reachedCounts()} decides which contests count.
     *
     * A contest that does not count is said in the finding rather than
     * dropped, in the words that are true of it: a layer that could not
     * answer is told which of the types it names the run never met — a typo,
     * or a type reachable only through unanalysed code — and a layer whose
     * criteria matched symbols outside the paths is told that an earlier
     * layer holds them through an `exclude:` no run can answer.
     *
     * @param list<LayerDefinition> $definitions In declaration order.
     * @param array<string, int> $reachedCounts Layer name → number of symbols assigned to the
     *                                          layer or analysed classes it could still own,
     *                                          from {@see \Qualimetrix\Analysis\Policy\Architecture\LayerViolation\Observation\LayerEvidence::reachedCounts()}.
     * @param array<string, array{matchedAnalysed: int, matchedOutside: int, unansweredAnalysed: int, unansweredOutside: int, unmetTypes: list<string>, installConsulted: bool}> $contests
     *                                                                                                                                                                                     Every declared layer → what it could still own, from
     *                                                                                                                                                                                     {@see \Qualimetrix\Analysis\Policy\Architecture\LayerViolation\Observation\LayerEvidence::contests()}.
     *
     * @return list<Finding>
     */
    public static function unreachableLayers(array $definitions, array $reachedCounts, array $contests): array
    {
        $findings = [];

        foreach ($definitions as $definition) {
            $layerName = $definition->name();
            if ($definition->lifecycle->isPending() || ($reachedCounts[$layerName] ?? 0) > 0) {
                continue;
            }

            $findings[] = self::projectDiagnostic(
                LayerPolicyPreparationInterface::UNREACHABLE_LAYER_DIAGNOSTIC_NAME,
                \sprintf(
                    'Layer "%s" was never matched during analysis. Possible causes: (1) it is shadowed by a broader layer earlier in the declaration order, (2) the declared criteria (%s) match no class in the analysed codebase.%s Run "qmx debug:layer-assignment <class>" to inspect specific classes.',
                    $layerName,
                    $definition->membership()->describe(),
                    self::uncountedContest($contests[$layerName]),
                ),
                'Move the layer above any broader layer that captures its classes, remove the layer if its pattern intentionally covers no class, or declare "pending: true" if the code it describes has not been written yet.',
            );
        }

        return $findings;
    }

    /**
     * What an unreachable layer could still own, which did not keep it out of
     * the channel, said in the words that hold for each share.
     *
     * @param array{matchedAnalysed: int, matchedOutside: int, unansweredAnalysed: int, unansweredOutside: int, unmetTypes: list<string>, installConsulted: bool} $contest
     */
    private static function uncountedContest(array $contest): string
    {
        $text = '';
        if ($contest['unansweredAnalysed'] + $contest['unansweredOutside'] > 0) {
            $text .= \sprintf(
                ' The run could not answer the criteria about %s (architecture.doubted-assignment names them), and a'
                . ' doubt does not show that they match anything.',
                self::presentCounts([
                    'analysed class(es)' => $contest['unansweredAnalysed'],
                    'symbol(s) outside the analysed paths' => $contest['unansweredOutside'],
                ]),
            );
        }
        $text .= self::sampledSentence(
            $contest['installConsulted']
                ? ' None of the %d type(s) the criteria name (%s) is declared in the analysed paths, built into PHP,'
                    . ' met at either end of a dependency edge or placed by the analysed project\'s composer install:'
                    . ' a name is most likely mistyped, or the package declaring it is not installed.'
                : ' None of the %d type(s) the criteria name (%s) is declared in the analysed paths, built into PHP or met at'
                    . ' either end of a dependency edge, and the run found no composer install to look it up in: either a'
                    . ' name is mistyped, or the type is reachable only through code the run did not analyse, and'
                    . ' installing the project\'s dependencies or widening paths to include that code decides it.',
            $contest['unmetTypes'],
        );
        if ($contest['matchedOutside'] > 0) {
            $text .= \sprintf(
                ' Its criteria match %d symbol(s) outside the analysed paths, which an earlier layer holds through an'
                . ' "exclude" the run cannot answer about them (architecture.doubted-assignment names them); the run'
                . ' never reads such a symbol, so that clause stays unanswered in every run.',
                $contest['matchedOutside'],
            );
        }

        return $text;
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
     * ({@see \Qualimetrix\Analysis\Policy\Architecture\LayerViolation\Observation\LayerEvidenceCollector::tallyMatchedEnd()}): tallying every match
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
     * layers during expansion.
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
                    . 'pattern, (2) matching classes were filtered out by file discovery (`suppress_paths` / '
                    . '`suppress_namespaces` at top level or in rule options), (3) the module disappeared in a '
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
     * All three diagnostics judge the declaration as a whole, so they carry
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
