<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\LayerViolation;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Builds `architecture.doubted-assignment`, the third channel of
 * {@see LayerViolationRule}: the symbols whose layer the run could not fully
 * decide, and the layers that could not answer about them.
 *
 * It is the rule's, not {@see LayerDeclarationValidator}'s: a doubt is
 * information about how far the layer verdicts can be trusted, reported at
 * `info` and never gating, where every validator channel fails the run. It
 * counts the same population whose undecided share
 * {@see DeclaredLayerReachability::coverage()} names in the coverage-gap text,
 * and both read that population from the walk rather than from each other.
 *
 * Extracted for the reason {@see UnmatchedExcludeDiagnostic} was: the rule
 * owns the decision to report, and a finding's own text is a separate subject.
 *
 * @internal Consumed by {@see LayerViolationRule}.
 */
final class DoubtedAssignmentDiagnostic
{
    /**
     * `architecture.doubted-assignment`: which symbols' layer the run could
     * not fully decide, and which layers could not answer about them.
     *
     * Two populations, both settled by answering the same layers. A symbol
     * that stands assigned while a layer bearing on the assignment went
     * unanswered is in a layer and its edges are judged; what the run cannot
     * say is whether that layer would have changed it. A symbol in no layer
     * only because a layer could not answer is judged against no allow-list,
     * and may belong to that layer. Both are information about how far the
     * verdict can be trusted, not a hole in the declaration, so the finding
     * is {@see Severity::Info} — reported, never gating — and a channel of the
     * rule rather than of the configuration validator.
     *
     * Published whatever the coverage mode. It says whether membership is
     * right rather than how much of the code a layer covers, and it is where
     * every layer `architecture.unreachable-layer` may no longer call empty
     * stays visible — under the default `ignore` nothing else in `check` names
     * it. Such a layer either could not answer, and is named with the layers
     * that could not, or would own a symbol if an unanswered `exclude:` in
     * front of it removed the symbol, and is named in a list of its own.
     *
     * The layers are named with their counts because a symbol outside the
     * analysed paths has no other surface: `debug:layer-assignment` refuses a
     * class the run did not analyse.
     *
     * @param array{sourceEdges: int, targetEdges: int, classes: array<string, string>, undecidable: array<string, string>, undecidableOutsidePaths: array<string, string>, doubted: array<string, string>, doubtedOutsidePaths: array<string, string>} $state
     * @param array<string, array<string, true>> $undecidedByLayer Layer name => the canonical symbols it could not answer
     *                                                             about while it bore on their assignment, in
     *                                                             declaration order.
     * @param array<string, array<string, true>> $ownsIfExcludedByLayer Layer name => the canonical symbols it would own
     *                                                                  if an unanswered `exclude:` in front of it
     *                                                                  removed them, in declaration order.
     *
     * @return list<Finding>
     */
    public static function forDoubts(
        array $state,
        array $undecidedByLayer,
        array $ownsIfExcludedByLayer,
        string $channelName,
    ): array {
        $outsideKeys = $state['doubtedOutsidePaths'] + $state['undecidableOutsidePaths'];
        $all = $state['doubted'] + $state['undecidable'];
        $analysed = array_diff_key($all, $outsideKeys);
        $outside = array_intersect_key($all, $outsideKeys);

        $statements = [];
        $consequences = '';
        foreach (self::doubtPopulations($state) as [$symbols, $statement, $consequence]) {
            if ($symbols === []) {
                continue;
            }
            $statements[] = \sprintf(
                $statement,
                \count($symbols),
                self::presentCounts([
                    'analysed class(es)' => \count(array_intersect_key($symbols, $analysed)),
                    'outside the analysed paths' => \count(array_intersect_key($symbols, $outside)),
                ]),
            );
            $consequences .= $consequence;
        }

        if ($statements === []) {
            return [];
        }

        return [new Finding(
            location: Location::none(),
            subject: MetricSubject::aggregate(SymbolPath::forProject()),
            symbolPath: SymbolPath::forProject(),
            ruleName: $channelName,
            code: $channelName,
            message: implode('; ', $statements)
                . '. Layers that could not answer: ' . self::layerList($undecidedByLayer, $state) . '.'
                . ($ownsIfExcludedByLayer === [] ? '' : ' Layers that would own some of them if an unanswered "exclude"'
                    . ' removed them: ' . self::layerList($ownsIfExcludedByLayer, $state) . '.')
                . self::doubtExamples($analysed, $outside)
                . $consequences,
            severity: Severity::Info,
            recommendation: self::doubtRecommendation(\count($analysed), \count($outside)),
        )];
    }

    /**
     * The two populations the doubt finding reports, each with the statement
     * that counts it and what the doubt means for its symbols.
     *
     * @param array{doubted: array<string, string>, undecidable: array<string, string>} $state
     *
     * @return list<array{0: array<string, string>, 1: string, 2: string}>
     */
    private static function doubtPopulations(array $state): array
    {
        return [
            [
                $state['doubted'],
                '%d assigned symbol(s) rest on a layer the run could not fully decide (%s)',
                ' Each assignment stands and its edges are judged against its layer\'s allow-list; whether a layer the run'
                . ' could not answer — an earlier one, or the assigned layer\'s own "exclude" — would change it is unknown.',
            ],
            [
                $state['undecidable'],
                '%d symbol(s) are in no layer because a layer could not answer about them (%s)',
                ' A symbol in no layer is judged against no allow-list, and may belong to the layer that could not answer.',
            ],
        ];
    }

    /**
     * Each named layer with how many of the assigned and of the unassigned
     * symbols in doubt it bears on. Every symbol a layer could not answer
     * about, and every symbol a layer would own once an unanswered `exclude:`
     * removed it, is in one of the two, so no entry is empty.
     *
     * @param array<string, array<string, true>> $symbolsByLayer
     * @param array{doubted: array<string, string>, undecidable: array<string, string>} $state
     */
    private static function layerList(array $symbolsByLayer, array $state): string
    {
        return implode(', ', array_map(
            static fn(int|string $layerName, array $symbols): string => \sprintf('"%s" (%s)', $layerName, self::presentCounts([
                'assigned in doubt' => \count(array_intersect_key($symbols, $state['doubted'])),
                'in no layer' => \count(array_intersect_key($symbols, $state['undecidable'])),
            ])),
            array_keys($symbolsByLayer),
            $symbolsByLayer,
        ));
    }

    /**
     * Examples split by kind, because what settles the doubt differs by kind
     * and a mixed list does not say which advice applies to which name.
     *
     * @param array<string, string> $analysed
     * @param array<string, string> $outside
     */
    private static function doubtExamples(array $analysed, array $outside): string
    {
        $lists = array_filter([
            'analysed' => DiagnosticSampleList::format(array_values($analysed)),
            'outside the analysed paths' => DiagnosticSampleList::format(array_values($outside)),
        ], static fn(?string $list): bool => $list !== null);

        return \sprintf(' Examples, at most %d of each kind by name — ', DiagnosticSampleList::LIMIT)
            . implode('; ', array_map(
                static fn(string $kind, string $list): string => $kind . ': ' . $list,
                array_keys($lists),
                $lists,
            ))
            . '.';
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
     * One sentence per kind of symbol present, because what settles the doubt
     * differs: an analysed class can be inspected and its chain completed,
     * while a symbol outside the paths cannot be inspected at all —
     * `debug:layer-assignment` refuses a class the run did not analyse.
     *
     * "Outside the analysed paths" is a fact about the run, not about whose
     * code the symbol is: a run over one directory leaves the project's own
     * classes outside too. For those a patterns layer is a remodelling rather
     * than an answer, so the sentence names both remedies and whose code each
     * is for.
     */
    private static function doubtRecommendation(int $analysed, int $outside): string
    {
        $sentences = [];
        if ($analysed > 0) {
            $sentences[] = 'For an analysed class, "qmx debug:layer-assignment <class>" names the unanswered layer and'
                . ' where its inheritance chain stops; widening paths to include that declaration settles it.';
        }
        if ($outside > 0) {
            $sentences[] = 'For a symbol outside the analysed paths, what settles it depends on whose code it is: your own code'
                . ' is settled by analysing it — widening paths to include it, or running over the whole project rather'
                . ' than part of it; a dependency\'s class by a patterns layer for its namespace declared before the layer'
                . ' that could not answer.';
        }

        return implode(' ', $sentences);
    }
}
