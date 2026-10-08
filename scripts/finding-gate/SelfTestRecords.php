<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * Authoritative record publications and exact declared value transitions.
 *
 * @phpstan-import-type Specification from SyntheticTree
 * @phpstan-import-type Witness from CheckWitnesses
 */
final class SelfTestRecords extends SelfTestGroup
{
    public function publicComparison(): void
    {
        $tree = SyntheticTree::clean();
        $clean = $this->reportFor($tree);
        $this->same([], $clean->raised(), 'complete independent finding projections establish a clean public comparison');
        $record = $tree['findings']['alpha'][0];
        unset($record['message']);
        $tree['candidateAnswers']['case:alpha|format:json'] = ['stdout' => self::document([$record], [])];
        $broken = $this->reportFor($tree);
        $this->assert(\in_array(FailureClass::RECORD_PROJECTION_MISMATCH, $broken->failureClasses(), true), 'a partial actual JSON publication raises record-projection-mismatch through the public gate');
    }

    /** @return list<Witness> */
    public static function witnesses(): array
    {
        $record = SyntheticTree::clean()['findings']['alpha'][0];
        return [
            CheckWitnesses::witness('ranking-capture-metadata', CheckWitnesses::DECLARATIONS, static fn(array $tree): array => self::rankingWitnessTree($tree, 'metadata'), [[FailureClass::RANKING_PROJECTION_MISMATCH, 'candidate / case:ranking-metadata|format:json', 'RankingCheck::projectionProblem <- Gate::checkFindings'], [FailureClass::RANKING_PROJECTION_MISMATCH, 'candidate / case:ranking-metadata|format:json', 'RankingCheck::projectionProblem <- Gate::captureAuthority']], [[FailureClass::SURFACE_MISMATCH, 'case:ranking-metadata|format:json'], [FailureClass::SURFACE_MISMATCH, 'case:ranking-metadata|check:output:file']]),
            CheckWitnesses::witness('ranking-complete-shape', CheckWitnesses::DECLARATIONS, static fn(array $tree): array => self::rankingWitnessTree($tree, 'shape'), [[FailureClass::RANKING_PROJECTION_MISMATCH, 'candidate / case:ranking-shape|format:json', 'RankingCheck::projectionProblem <- RecordCheck::checkCase'], [FailureClass::RANKING_PROJECTION_MISMATCH, 'candidate / case:ranking-shape|format:json', 'RankingCheck::projectionProblem <- Gate::captureAuthority'], [FailureClass::RUN_FAILED, 'candidate-2 / ranking-shape', 'Gate::captureAuthority <- GateModes::compare']], [[FailureClass::SURFACE_MISMATCH, 'case:ranking-shape|format:json'], [FailureClass::SURFACE_MISMATCH, 'case:ranking-shape|check:output:file']]),
            CheckWitnesses::witness('ranking-duplicate-ambiguity', CheckWitnesses::DECLARATIONS, static fn(array $tree): array => self::rankingWitnessTree($tree, 'ambiguity'), [[FailureClass::RECORD_AMBIGUOUS, 'candidate / case:ranking-ambiguity|format:json', 'RankingCheck::anatomy <- RecordCheck::checkCase'], [FailureClass::RECORD_AMBIGUOUS, 'candidate / case:ranking-ambiguity|format:json', 'RankingCheck::anatomy <- Gate::captureAuthority'], [FailureClass::RUN_FAILED, 'candidate-2 / ranking-ambiguity', 'Gate::captureAuthority <- GateModes::compare']], [[FailureClass::SURFACE_MISMATCH, 'case:ranking-ambiguity|format:json'], [FailureClass::SURFACE_MISMATCH, 'case:ranking-ambiguity|check:output:file']]),
            CheckWitnesses::witness('ranking-unannounced-limit', CheckWitnesses::DECLARATIONS, static fn(array $tree): array => self::rankingWitnessTree($tree, 'limit'), [[FailureClass::VALUE_MISMATCH, 'case:ranking-limit|format:json', 'ValueCheck::measure <- RankingCheck::prepareRanking'], [FailureClass::VALUE_MISMATCH, 'case:ranking-limit|check:output:file', 'ValueCheck::measure <- RankingCheck::prepareRanking']], [[FailureClass::SURFACE_MISMATCH, 'case:ranking-limit|format:summary']]),
            CheckWitnesses::witness('ranking-unchanged-order', CheckWitnesses::DECLARATIONS, static fn(array $tree): array => self::rankingWitnessTree($tree, 'order'), [[FailureClass::RANKING_ORDER_MISMATCH, 'case:ranking-order|format:json|record:*', 'RankingCheck::prepareRanking <- RecordCheck::prepare']]),
            CheckWitnesses::witness('record-publication-shape', CheckWitnesses::DECLARATIONS, static function (array $tree) use ($record): array {
                $tree = self::fixture($tree, 'record-shape');
                $tree['candidateAnswers']['case:record-shape|format:json'] = ['stdout' => self::document([$record + ['unpublished' => 1]], [])];
                return $tree;
            }, [
                [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:record-shape|format:json', 'RecordCheck::publicationProblem <- Gate::checkFindings'],
                [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:record-shape|format:json', 'RecordStage::applyStage <- SurfaceComparison::applyRegisteredStages'],
                [FailureClass::SURFACE_MISMATCH, 'case:record-shape|check:output:file', 'SurfaceComparison::mismatch <- Gate::compare'],
                [FailureClass::RUN_FAILED, 'candidate-2 / record-shape', 'Gate::captureAuthority <- GateModes::compare'],
            ], [
                [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:record-shape|*'],
                [FailureClass::FINDING_TUPLE_MISMATCH, 'candidate / record-shape / finding #0'],
            ]),
            CheckWitnesses::witness('record-unannounced-residual', CheckWitnesses::DECLARATIONS, static function (array $tree) use ($record): array {
                $tree = self::fixture($tree, 'record-residual');
                $record['channel'] = $record['code'] = $record['rule'] = 'unannounced.record';
                $tree['candidateFindings']['record-residual'] = [$record];
                return $tree;
            }, [[FailureClass::RECORD_UNDECLARED, 'case:record-residual|format:json', 'RecordCheck::observeResidual <- RecordStage::countInputs']], [
                [FailureClass::SURFACE_MISMATCH, 'case:record-residual|baseline-file'],
                [FailureClass::SURFACE_MISMATCH, 'case:record-residual|check:output:file'],
                [FailureClass::SURFACE_MISMATCH, 'case:record-residual|format:checkstyle'],
                [FailureClass::SURFACE_MISMATCH, 'case:record-residual|format:github'],
                [FailureClass::SURFACE_MISMATCH, 'case:record-residual|format:gitlab'],
                [FailureClass::SURFACE_MISMATCH, 'case:record-residual|format:html'],
                [FailureClass::SURFACE_MISMATCH, 'case:record-residual|format:json'],
                [FailureClass::SURFACE_MISMATCH, 'case:record-residual|format:sarif'],
                [FailureClass::SURFACE_MISMATCH, 'case:record-residual|format:summary'],
                [FailureClass::SURFACE_MISMATCH, 'case:record-residual|format:text'],
                [FailureClass::SURFACE_MISMATCH, 'case:record-residual|format:text-detail'],
                [FailureClass::SURFACE_MISMATCH, 'case:record-residual|show-suppressed'],
                [FailureClass::CASE_CLAIM_MISMATCH, 'case:record-residual'],
            ]),
            CheckWitnesses::witness('value-unannounced-metric', CheckWitnesses::DECLARATIONS, static function (array $tree): array {
                $tree = self::fixture($tree, 'value-metric');
                $tree['candidateAnswers']['case:value-metric|format:metrics'] = ['stdout' => ValueCheck::value(['symbols' => [['type' => 'method', 'name' => 'Replay\\Value-metric::run', 'file' => 'src/Value-metric.php', 'line' => 1, 'metrics' => ['ccn' => 2]]]])];
                return $tree;
            }, [[FailureClass::VALUE_MISMATCH, 'case:value-metric|format:metrics|record:*', 'ValueCheck::measure <- RecordCheck::prepare']], [[FailureClass::SURFACE_MISMATCH, 'case:value-metric|format:metrics']]),
            CheckWitnesses::witness('value-directive-exit', CheckWitnesses::DECLARATIONS, static function (array $tree): array {
                $tree = self::fixture($tree, 'value-directives');
                $tree['answers']['case:value-directives|directives'] = ['stdout' => '{"directives":[],"exit_code":0}'];
                $tree['candidateAnswers']['case:value-directives|directives'] = ['stdout' => '{"directives":[],"exit_code":1}', 'exit' => 1];
                return $tree;
            }, [
                [FailureClass::VALUE_MISMATCH, 'case:value-directives|directives', 'ValueCheck::measure <- RecordStage::directiveExit'],
                [FailureClass::VALUE_MISMATCH, 'case:value-directives|directives', 'ValueCheck::measure <- ValueStage::applyStage'],
            ], [[FailureClass::SURFACE_MISMATCH, '*case:value-directives*']]),
            CheckWitnesses::witness('value-exact-table', CheckWitnesses::DECLARATIONS, static function (array $tree): array {
                $tree = self::fixture($tree, 'value-exact');
                $metric = ['type' => 'method', 'name' => 'Replay\\Exact::run', 'file' => 'src/Exact.php', 'line' => 1, 'metrics' => ['ccn' => 1, 'replayValue' => 1]];
                $tree['answers']['case:value-exact|format:metrics'] = ['stdout' => ValueCheck::value(['symbols' => [$metric]])];
                $metric['metrics']['replayValue'] = 2;
                $tree['candidateAnswers']['case:value-exact|format:metrics'] = ['stdout' => ValueCheck::value(['symbols' => [$metric]])];
                $tree = self::append($tree, DeclaredValues::INDEX, DeclaredValues::COLUMNS, [['metric', 'replayValue', 'callable', 'One exact measured value changes.']]);
                return self::append($tree, DeclaredValues::DERIVED, DeclaredValues::DERIVED_COLUMNS, [['metric', 'replayValue', 'case:value-exact|format:metrics|record:{"type":"method","name":"Replay\\\\Exact::run"}', '1', '3']]);
            }, [[FailureClass::VALUE_MISMATCH, DeclaredValues::DERIVED, 'ValueCheck::checkRun#2 <- Gate::compare']]),
            CheckWitnesses::witness('record-unused-derive-intent', 'derive-declarations refused', static fn(array $tree): array => self::append($tree, DeclaredRecords::INDEX, DeclaredRecords::COLUMNS, [['withdrawn', '*', 'json', 'format:json', '{"channel":"never.published"}', 'No record is removed.']]), [[FailureClass::RECORD_STALE, DeclaredRecords::INDEX, 'RecordCheck::checkRun <- Gate::compare']]),
            CheckWitnesses::witness('value-unused-derive-intent', 'derive-declarations refused', static fn(array $tree): array => self::append($tree, DeclaredValues::INDEX, DeclaredValues::COLUMNS, [['field', 'nothingPublished', '*', 'No value is changed.']]), [[FailureClass::VALUE_STALE, DeclaredValues::INDEX, 'ValueCheck::checkRun#1 <- Gate::compare']]),
        ];
    }

    /** @param Specification $tree
     * @return Specification
     */
    public static function rankingWitnessTree(array $tree, string $kind): array
    {
        $id = 'ranking-' . $kind;
        $tree = self::fixture($tree, $id);
        $x = $tree['findings']['alpha'][0];
        $y = array_replace($x, ['file' => 'src/Neighbour.php', 'symbol' => 'Replay\\Neighbour::run', 'subject' => 'declaration:callable:Replay\\Neighbour::run@src/Neighbour.php', 'message' => 'Independent neighbour.']);
        $records = $kind === 'ambiguity' ? [$x, $x] : (\in_array($kind, ['order', 'limit'], true) ? [$x, $y] : [$x]);
        $tree['findings'][$id] = $records;
        if ($kind === 'ambiguity') {
            $baseline = ValueCheck::value(['version' => 13, 'scope' => ['src'], 'entries' => [$x['subject'] => [['channel' => $x['channel'], 'magnitudes' => [$x['metricValue'], $x['metricValue']]]]]]);
            $tree['answers']['case:' . $id . '|baseline-file'] = ['stdout' => $baseline, 'file' => $baseline];
        }
        $old = self::issues($records, array_fill(0, \count($records), 30));
        $new = $old;
        if ($kind === 'shape') {
            $new[0]['rank'] = 7;
        } elseif ($kind === 'ambiguity') {
            $new[1]['impactScore'] = 29;
        } elseif ($kind === 'order') {
            $new = self::issues([$y, $x], [30, 30]);
        }
        foreach (['answers' => $old, 'candidateAnswers' => $new] as $side => $issues) {
            $slice = $kind === 'order' ? $issues : ($kind === 'limit' && $side === 'answers' ? \array_slice($issues, 0, 1) : []);
            $document = self::document($records, $slice);
            $answer = ['stdout' => $document, 'ranked' => ['stdout' => self::document($records, $issues)]];
            if ($kind === 'metadata' && $side === 'candidateAnswers') {
                $answer['ranked']['stderr'] = 'A warning unique to the internal capture.';
            }
            $tree[$side]['case:' . $id . '|format:json'] = $answer;
            $tree[$side]['case:' . $id . '|check:output'] = ['file' => $document];
            $tree[$side]['case:' . $id . '|format:summary'] = ['stdout' => self::summary($slice, $records)];
        }
        return $tree;
    }

    /** @param list<array<string,mixed>> $records
     * @param list<int|float> $scores
     *
     * @return list<array<string,mixed>>
     */
    private static function issues(array $records, array $scores): array
    {
        $issues = [];
        foreach ($records as $index => $record) {
            $issues[] = ['rank' => $index + 1, ...array_intersect_key($record, array_flip(RankingSchema::PROJECTION)), 'impactScore' => $scores[$index], 'coupling.class-rank' => null, 'debtMinutes' => $record['techDebtMinutes']];
        }
        return $issues;
    }

    /** @param list<array<string,mixed>> $records
     * @param list<array<string,mixed>> $issues
     */
    private static function document(array $records, array $issues): string
    {
        $counts = [];
        foreach ($records as $record) {
            $rule = (string) $record['rule'];
            $counts[$rule] = ($counts[$rule] ?? 0) + 1;
        }
        return ValueCheck::value(['violations' => $records, 'topIssues' => $issues, 'violationsMeta' => ['total' => \count($records), 'shown' => \count($records), 'truncated' => false, 'byRule' => $counts]]);
    }

    /** @param list<array<string,mixed>> $issues
     * @param list<array<string,mixed>> $records
     */
    private static function summary(array $issues, array $records): string
    {
        $text = "Analysis complete\n";
        foreach ($issues as $issue) {
            foreach ($records as $record) {
                if (RankingSchema::joinKey($record, false) !== RankingSchema::joinKey($issue, true)) {
                    continue;
                }
                $symbol = (string) $record['symbol'];
                $symbol = substr($symbol, (int) strrpos('\\' . $symbol, '\\'));
                $text .= '  ' . $issue['rank'] . '. [ERR] 30.0  ' . $record['file'] . ':' . $record['line'] . "  [15min]\n         " . $record['code'] . ': ' . ReportRecords::message($record, true) . ' (' . $symbol . ")\n";
                break;
            }
        }
        return $text;
    }

    /**
     * @param Specification $tree
     * @param list<string> $columns
     * @param list<list<string>> $rows
     *
     * @return Specification
     */
    private static function append(array $tree, string $file, array $columns, array $rows): array
    {
        $existing = $tree['candidateDeclarations'][$file] ?? $tree['declarations'][$file] ?? Tsv::render($columns, []);
        $addition = Tsv::render($columns, $rows);
        $separator = strpos($addition, "\n");
        if ($separator === false) {
            throw new GateError('An appended declaration has no TSV header.');
        }
        $tree['candidateDeclarations'][$file] = rtrim($existing, "\n") . "\n" . substr($addition, $separator + 1);
        return $tree;
    }

    /**
     * @param Specification $tree
     *
     * @return Specification
     */
    private static function fixture(array $tree, string $id): array
    {
        $tree['cases'][$id] = ['replay.alpha@callable'];
        $tree['findings'][$id] = $tree['findings']['alpha'];
        $tree['declarations']['cases/' . $id . '/case.json'] = ValueCheck::value(['id' => $id, 'description' => 'An independent record publication.', 'coverage' => CaseDefinition::COVERAGE_AUXILIARY, 'paths' => ['src'], 'config' => 'qmx.yaml', 'args' => [], 'channels' => ['replay.alpha@callable']]);
        return $tree;
    }

    /** @param Specification $tree */
    private function reportFor(array $tree): GateReport
    {
        $root = SyntheticTree::create($tree);
        try {
            $report = new GateReport();
            GateModes::run(Options::parse(['gate', '--candidate=' . $root, '--reference=HEAD'], $root), $report);
            return $report;
        } finally {
            SyntheticTree::remove($root);
        }
    }
}
