<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use QmxFindingGate\{Fs, GateReport, Options, ReportRecords, SyntheticTree, ValueCheck};

/**
 * Recorded publications exercise the comparison stages without capture processes or Git.
 *
 * @phpstan-import-type Specification from SyntheticTree
 */
final class RecordedComparison
{
    /** @param Specification $tree
     * @param (callable(string):void)|null $prepare
     */
    public static function report(array $tree, ?callable $prepare = null): GateReport
    {
        return self::compare($tree, $prepare, false, null)[0];
    }

    /** @param Specification $tree */
    public static function reportAt(array $tree, string $root, ?callable $referencePrepare = null): GateReport
    {
        return self::compare($tree, null, false, $root, $referencePrepare)[0];
    }

    /** @param Specification $tree */
    public static function stageReport(array $tree, string $key): GateReport
    {
        return self::compare($tree, null, false, null, null, $key)[0];
    }

    /** @param Specification $tree
     * @param (callable(\QmxFindingGate\CaptureResult):\QmxFindingGate\CaptureResult)|null $secondCandidate
     *
     * @return array{GateReport,list<string>}
     */
    public static function derive(array $tree, string $root, ?callable $referencePrepare = null, ?callable $secondCandidate = null): array
    {
        return self::compare($tree, null, true, $root, $referencePrepare, null, $secondCandidate);
    }

    /** @param Specification $tree
     * @param (callable(string):void)|null $prepare
     * @param (callable(\QmxFindingGate\CaptureResult):\QmxFindingGate\CaptureResult)|null $secondCandidate
     *
     * @return array{GateReport,list<string>}
     */
    private static function compare(array $tree, ?callable $prepare, bool $derive, ?string $root, ?callable $referencePrepare = null, ?string $stageOnly = null, ?callable $secondCandidate = null): array
    {
        $owned = $root === null;
        $root ??= SyntheticTree::fixture($tree);
        $reference = SyntheticTree::fixture($tree, candidate: false);
        try {
            if ($prepare !== null) {
                $prepare($root);
            }
            if ($referencePrepare !== null) {
                $referencePrepare($reference);
            }
            $report = new GateReport();
            $maps = \QmxFindingGate\RenameMaps::load($root . '/finding-gate/maps', \QmxFindingGate\MetricVocabulary::ofTree($root));
            $maps->acceptReferenceVocabulary(\QmxFindingGate\MetricVocabulary::ofTree($reference));
            $declarations = \QmxFindingGate\Declarations::load($root);
            $run = new \QmxFindingGate\RunContext(
                Options::parse(['gate', '--candidate=' . $root, '--reference=HEAD'], $root),
                $report,
                \QmxFindingGate\Corpus::load($root),
                $maps,
                \QmxFindingGate\ChannelSplit::of($maps),
                \QmxFindingGate\MetricVocabulary::ofTree($root),
                \QmxFindingGate\Normalization::fromRules([]),
                $declarations,
                $root,
            );
            $captures = [
                'candidate' => self::capture($root, $run),
                'reference' => self::capture($reference, $run),
            ];
            foreach ($captures as $side => $capture) {
                $run->rankings->supply($side, $capture->rankings);
                $run->baselineEligibility->supply($side, $capture->baselineEligibility);
            }
            $derivations = [];
            $exact = new \QmxFindingGate\ExactSurfaceDeltaCheck($run);
            if ($derive) {
                foreach (\QmxFindingGate\Wiring::gate()->list('derivations') as $name) {
                    $class = 'QmxFindingGate\\' . $name;
                    $derivation = $class::create($run);
                    if (!$derivation instanceof \QmxFindingGate\Derivation) {
                        throw new \QmxFindingGate\GateError('The recorded comparison requires a declaration writer.');
                    }
                    $derivation->startDeriving();
                    $derivations[] = $derivation;
                }
                $exact->startDeriving();
                $derivations[] = $exact;
            }
            $records = \QmxFindingGate\RecordCheck::create($run);
            $ranking = \QmxFindingGate\RankingCheck::create($run);
            foreach ($captures as $side => $capture) {
                foreach ($run->corpus->cases as $case) {
                    $ranking->checkCase($side, $case, \QmxFindingGate\CaseOutcome::ANALYSIS, $capture->artifacts);
                    $records->checkCase($side, $case, \QmxFindingGate\CaseOutcome::ANALYSIS, $capture->artifacts);
                    try {
                        $complete = $records->rawAuthority($case->id, 'format:json', $side);
                    } catch (\QmxFindingGate\GateError) {
                        $complete = null;
                    }
                    \QmxFindingGate\CaseOutcomeCheck::create($run)->findingsOf($side, $case, $capture->artifacts, $complete);
                }
            }
            $tuple = new \QmxFindingGate\TupleCheck($run->options, $report);
            $tuple->checkTuple();
            $tupleShape = \QmxFindingGate\EquivalenceTuple::load($root);
            foreach ($captures as $side => $capture) {
                foreach ($run->corpus->cases as $case) {
                    $source = $capture->rankings['case:' . $case->id . '|format:json'] ?? null;
                    if ($source === null) {
                        continue;
                    }
                    $text = $source['physical']['stdout'] ?? $source['ranked']['stdout'];
                    $document = ReportRecords::decode($text);
                    $tuple->checkTupleAgainstFindings($side, $case, $tupleShape, $document['violations']);
                }
            }
            if ($stageOnly !== null) {
                $stage = \QmxFindingGate\RecordStage::create($run);
                $stage->countInputs($captures['candidate']->artifacts, $captures['reference']->artifacts);
                $stage->applyStage(new \QmxFindingGate\SurfacePair(
                    $stageOnly,
                    \QmxFindingGate\Surfaces::surfaceClass($stageOnly),
                    $captures['candidate']->artifacts[$stageOnly] ?? null,
                    $captures['reference']->artifacts[$stageOnly] ?? null,
                ));
                return [$report, []];
            }
            $declarations->fields->registerRequired($run->corpus, $run->capturePlan);
            $stages = [];
            foreach (\QmxFindingGate\Wiring::gate()->list('surfaceStages') as $name) {
                $class = 'QmxFindingGate\\' . $name;
                $stages[] = $class::create($run);
            }
            $delta = new \QmxFindingGate\DeclaredDeltaCheck($run->options, $report, $declarations->delta, $declarations->fieldMoves, $run->split);
            if ($derive) {
                $delta->startDeriving();
            }
            $fingerprints = new \QmxFindingGate\FingerprintCheck($report);
            foreach ($captures as $side => $capture) {
                foreach ($run->corpus->cases as $case) {
                    $fingerprints->checkFingerprints($side, $case, ReportRecords::decode($capture->rankings['case:' . $case->id . '|format:json']['physical']['stdout'] ?? $capture->rankings['case:' . $case->id . '|format:json']['ranked']['stdout'])['violations'], $capture->artifacts);
                }
            }
            $exact->plan($captures, $records, $fingerprints);
            $comparison = new \QmxFindingGate\SurfaceComparison($report, $run->corpus, $maps, $run->normalization, $fingerprints, $delta, $root, $stages, $records, $exact);
            $a = $captures['candidate']->artifacts;
            $b = $captures['reference']->artifacts;
            $renameCheck = new \QmxFindingGate\RenameMapCheck($report, $run->corpus, $maps, $run->split);
            $renameCheck->checkSplitExplanation($a, $b);
            $comparison->compareSurfaces($a, $b);
            $records->checkRun($a, $b);
            ValueCheck::create($run)->checkRun($a, $b);
            \QmxFindingGate\FieldValuesCheck::create($run)->checkRun($a, $b);
            \QmxFindingGate\CaptureCheck::create($run)->checkRun($a, $b);
            $normalizationCheck = \QmxFindingGate\NormalizationCheck::create($run);
            $normalizationCheck->checkRun($a, $b);
            $normalizationCheck->checkDeterminism($a, $a);
            $ranking->checkRepeatedCaptures($captures['candidate'], $secondCandidate === null ? $captures['candidate'] : $secondCandidate($captures['candidate']));
            $comparison->checkPathLeaks($a, $b, $reference);
            (new \QmxFindingGate\RenameMapCheck($report, $run->corpus, $maps, $run->split))->checkStaleMaps();
            if (!$derive) {
                (new \QmxFindingGate\StaleDeclarationCheck($report, $declarations))->checkStaleDeclarations();
                $exact->checkStale();
            }
            $paths = [];
            foreach ($derivations as $derivation) {
                $paths = [...$paths, ...$derivation->rewriteDerived()];
            }
            return [$report, array_values($paths)];
        } finally {
            if ($owned) {
                SyntheticTree::remove($root);
            }
            SyntheticTree::remove($reference);
        }
    }

    private static function capture(string $root, \QmxFindingGate\RunContext $run): \QmxFindingGate\CaptureResult
    {
        $answers = json_decode(Fs::read($root . '/replay/answers.json'), true, 512, \JSON_THROW_ON_ERROR);
        $artifacts = [];
        $rankings = [];
        $baselineEligibility = [];
        foreach ($run->capturePlan->invocations() as $descriptor) {
            $surface = $descriptor['surface'];
            $key = $descriptor['scope'] . '|' . $surface;
            $answer = $answers[$key];
            $stdout = $answer['stdout'] ?? '';
            if (isset($answer['summaryIssues'])) {
                $stdout = "Analysis complete\nTop issues\n" . implode('', $answer['summaryIssues']);
            }
            $artifacts[$key] = $surface === 'baseline-file' ? ($answer['file'] ?? $stdout) : $stdout;
            $artifacts[$descriptor['scope'] . '|exit:' . ($surface === 'baseline-file' ? 'baseline:generate' : $surface)] = (string) ($answer['exit'] ?? 0);
            $artifacts[$descriptor['scope'] . '|stderr:' . $surface] = $answer['stderr'] ?? '';
            if ($descriptor['outputFileKind'] !== null) {
                $artifacts[$descriptor['scope'] . '|' . $descriptor['outputFileKind']] = $answer['file'] ?? '';
            }
            if ($descriptor['rankingSource'] === $key) {
                $slot = $answer['ranked'];
                $physical = $answer['physical'] ?? null;
                $document = ReportRecords::decode($stdout);
                $rankings[$key] = [
                    'ranked' => ['stdout' => $slot['stdout'] ?? '', 'stderr' => $slot['stderr'] ?? ($answer['stderr'] ?? ''), 'exit' => $slot['exit'] ?? ($answer['exit'] ?? 0)],
                    'physical' => ($document['violationsMeta']['truncated'] ?? false) === true && $physical !== null ? ['stdout' => $physical['stdout'] ?? '', 'stderr' => $physical['stderr'] ?? ($answer['stderr'] ?? ''), 'exit' => $physical['exit'] ?? ($answer['exit'] ?? 0)] : null,
                ];
                $complete = ReportRecords::decode($physical['stdout'] ?? $slot['stdout'] ?? $stdout);
                if (\is_array($complete['violations'] ?? null)) {
                    $groups = \QmxFindingGate\BaselineEligibility::groups(\QmxFindingGate\BaselineEligibility::validatedRecords($complete['violations']));
                    $baselineEligibility[$key] = $answer['baselineEligibility'] ?? array_fill_keys(array_keys($groups), true);
                }
            }
        }
        return new \QmxFindingGate\CaptureResult($artifacts, $rankings, $baselineEligibility);
    }

}
