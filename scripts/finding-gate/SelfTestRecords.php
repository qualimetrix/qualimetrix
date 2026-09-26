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
        $tree['candidateAnswers']['case:alpha|format:json'] = ['stdout' => ValueCheck::value(['violations' => [$record]])];
        $broken = $this->reportFor($tree);
        $this->assert(\in_array(FailureClass::RECORD_PROJECTION_MISMATCH, $broken->failureClasses(), true), 'a partial actual JSON publication raises record-projection-mismatch through the public gate');
    }

    /** @return list<Witness> */
    public static function witnesses(): array
    {
        $record = SyntheticTree::clean()['findings']['alpha'][0];
        return [
            CheckWitnesses::witness('record-publication-shape', CheckWitnesses::DECLARATIONS, static function (array $tree) use ($record): array {
                $tree = self::fixture($tree, 'record-shape');
                $tree['candidateAnswers']['case:record-shape|format:json'] = ['stdout' => ValueCheck::value(['violations' => [$record + ['unpublished' => 1]]])];
                return $tree;
            }, [
                [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:record-shape|format:json', 'RecordCheck::publicationProblem <- Gate::checkFindings'],
                [FailureClass::RECORD_PROJECTION_MISMATCH, 'candidate / case:record-shape|format:json', 'RecordStage::applyStage <- SurfaceComparison::compareSurfaces'],
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
                [FailureClass::SURFACE_MISMATCH, 'case:record-residual|format:text'],
                [FailureClass::SURFACE_MISMATCH, 'case:record-residual|format:text-verbose'],
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
            CheckWitnesses::witness('record-ranked-neighbour', CheckWitnesses::DECLARATIONS, static function (array $tree) use ($record): array {
                $tree = self::fixture($tree, 'record-ranked');
                $issue = self::issue($record);
                $tree['answers']['case:record-ranked|format:json'] = ['stdout' => ValueCheck::value(['violations' => [$record], 'topIssues' => [$issue]])];
                $issue['message'] = 'An unannounced ranked neighbour.';
                $tree['candidateAnswers']['case:record-ranked|format:json'] = ['stdout' => ValueCheck::value(['violations' => [$record], 'topIssues' => [$issue]])];
                return $tree;
            }, [[FailureClass::TOP_ISSUES_MISMATCH, 'case:record-ranked|format:json', 'RecordStage::inspectRankedPrefix <- SurfaceComparison::compareSurfaces']], [[FailureClass::SURFACE_MISMATCH, 'case:record-ranked|format:json']]),
            CheckWitnesses::witness('value-ranked-score', CheckWitnesses::DECLARATIONS, static function (array $tree) use ($record): array {
                $tree = self::fixture($tree, 'value-ranked');
                $issue = self::issue($record);
                $tree['answers']['case:value-ranked|format:json'] = ['stdout' => ValueCheck::value(['violations' => [$record], 'topIssues' => [$issue]])];
                $issue['impactScore'] = 11;
                $tree['candidateAnswers']['case:value-ranked|format:json'] = ['stdout' => ValueCheck::value(['violations' => [$record], 'topIssues' => [$issue]])];
                return $tree;
            }, [[FailureClass::VALUE_MISMATCH, 'case:value-ranked|format:json|record:*', 'ValueCheck::measure <- RecordStage::rankedProjection']], [[FailureClass::SURFACE_MISMATCH, 'case:value-ranked|format:json']]),
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

    /** @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private static function issue(array $record): array
    {
        return ['rank' => 1, 'file' => $record['file'], 'line' => $record['line'], 'symbol' => $record['symbol'], 'rule' => $record['rule'], 'severity' => $record['severity'], 'message' => $record['message'], 'recommendation' => $record['recommendation'], 'impactScore' => 10, 'coupling.class-rank' => null, 'debtMinutes' => $record['techDebtMinutes']];
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
