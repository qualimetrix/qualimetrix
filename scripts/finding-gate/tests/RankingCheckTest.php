<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\{DataProvider, Test};
use PHPUnit\Framework\TestCase;
use QmxFindingGate\{DeclaredFields, DeclaredRecords, DeclaredValues, FailureClass, Fs, GateError, GateModes, GateReport, Options, RankingSchema, ReportRecords, SyntheticTree, Tsv, ValueCheck};

/** @phpstan-import-type Specification from SyntheticTree */
final class RankingCheckTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    #[Test]
    public function itChecksHealthyFullRankingAndZeroSlicesThroughThePublicGate(): void
    {
        foreach ([[], ['--top=0']] as $args) {
            $tree = SyntheticTree::clean();
            $tree['declarations']['cases/alpha/case.json'] = self::definition($args);
            $this->green($tree);
        }
    }

    #[Test]
    public function itNormalizesOnlyTheClockInInternalCaptureWarnings(): void
    {
        $records = self::records(1);
        $tree = self::rankedTree($records, self::issues($records, [10]), 1);
        $answer = self::answer($tree, 'case:alpha|format:json');
        $answer['stderr'] = "[23:59:59] [WARNING] The parent is outside the analysed path.\n";
        $answer['ranked']['stderr'] = "[00:00:00] [WARNING] The parent is outside the analysed path.\n";
        $tree['answers']['case:alpha|format:json'] = $answer;
        $report = $this->reportFor($tree);
        self::assertSame(GateReport::EXIT_GREEN, $report->exitCode(), $report->render());

        $answer['ranked']['stderr'] = "[00:00:00] [WARNING] A different parent is outside the analysed path.\n";
        $tree['answers']['case:alpha|format:json'] = $answer;
        $report = $this->reportFor($tree);
        self::assertContains(FailureClass::RANKING_PROJECTION_MISMATCH, $report->failureClasses(), $report->render());
    }

    #[Test]
    public function itPreservesRawRankedNumberSpellingsBeforeAnyNormalization(): void
    {
        self::assertSame(['{"impactScore":10.50}', '{"impactScore":2}'], ReportRecords::rawRecords('{"topIssues":[{"impactScore":10.50},{"impactScore":2}]}', 'topIssues'));
        $record = self::records(1)[0];
        $tree = self::rankedTree([$record], [self::issue($record, 1, 10.5)], 1);
        $answer = self::answer($tree, 'case:alpha|format:json');
        $answer['ranked']['stdout'] = str_replace('10.5', '10.50', $answer['ranked']['stdout']);
        $tree['candidateAnswers']['case:alpha|format:json'] = $answer;
        $report = $this->reportFor($tree);
        self::assertContains(FailureClass::RANKING_PROJECTION_MISMATCH, $report->failureClasses(), $report->render());
        self::assertNotSame([], array_filter($report->raised(), static fn(array $failure): bool => str_contains($failure['detail'], 'raw prefix')), $report->render());
    }

    /** @return iterable<string,array{string}> */
    public static function repeatedCaptureChanges(): iterable
    {
        foreach (['impactScore', 'coupling.class-rank', 'threshold'] as $field) {
            yield $field => [$field];
        }
    }

    #[Test]
    #[DataProvider('repeatedCaptureChanges')]
    public function itRefusesHiddenValueDriftInTheSecondCandidateCapture(string $field): void
    {
        $records = self::records(2);
        $shown = $field === 'threshold' ? 1 : null;
        $tree = self::rankedTree($records, self::issues($records, [20, 10]), 0, $shown);
        $tree['declarations']['cases/alpha/case.json'] = self::definition(['--top=0', ...($shown === null ? [] : ['--format-opt=violations=1'])]);
        $report = $this->reportFor($tree, static fn(string $root) => self::plantRepeatedCaptureChange($root, $field));
        self::assertSame([FailureClass::NONDETERMINISM_UNDECLARED], $report->failureClasses(), $report->render());
        self::assertSame(GateReport::EXIT_RED, $report->exitCode(), $report->render());
        self::assertSame('case:alpha|format:json', $report->raised()[0]['scope']);
    }

    #[Test]
    public function itIgnoresRepeatedRankPositionsAndPrivateLayoutOutsideThePublishedSlice(): void
    {
        $records = self::records(2);
        $tree = self::rankedTree($records, self::issues($records, [10, 10]), 0);
        $tree['declarations']['cases/alpha/case.json'] = self::definition(['--top=0']);
        $report = $this->reportFor($tree, static fn(string $root) => self::plantRepeatedCaptureChange($root, 'order'));
        self::assertSame(GateReport::EXIT_GREEN, $report->exitCode(), $report->render());
    }

    #[Test]
    public function itPreservesCompleteRepeatedOccurrenceCountsAcrossCandidatePasses(): void
    {
        [$x, $y] = self::records(2);
        $records = [$x, $x, $y, $y];
        $tree = self::rankedTree($records, self::issues($records, [10, 10, 10, 10]), 0, 1);
        $tree['declarations']['cases/alpha/case.json'] = self::definition(['--top=0', '--format-opt=violations=1']);
        $baseline = ValueCheck::value(['version' => 13, 'scope' => ['src'], 'entries' => [
            $x['subject'] => [['channel' => $x['channel'], 'magnitudes' => [$x['metricValue'], $x['metricValue']]]],
            $y['subject'] => [['channel' => $y['channel'], 'magnitudes' => [$y['metricValue'], $y['metricValue']]]],
        ]]);
        $tree['answers']['case:alpha|baseline-file'] = ['stdout' => $baseline, 'file' => $baseline];
        $report = $this->reportFor($tree, static fn(string $root) => self::plantRepeatedCaptureChange($root, 'multiplicity'));
        self::assertSame([FailureClass::NONDETERMINISM_UNDECLARED], $report->failureClasses(), $report->render());
        self::assertSame('case:alpha|format:json', $report->raised()[0]['scope']);
    }

    #[Test]
    public function itRefusesFifthPassHiddenRankingDriftWithoutWritingNormalization(): void
    {
        $records = self::records(2);
        $tree = self::rankedTree($records, self::issues($records, [20, 10]), 0);
        $tree['declarations']['cases/alpha/case.json'] = self::definition(['--top=0']);
        $root = SyntheticTree::create($tree);
        try {
            self::plantRepeatedCaptureChange($root, 'impactScore', 5);
            $path = $root . '/finding-gate/normalization.tsv';
            $before = Fs::read($path);
            $report = new GateReport();
            ob_start();
            try {
                $exit = GateModes::run(Options::parse(['gate', '--candidate=' . $root, '--derive-normalization'], $root), $report);
            } finally {
                ob_end_clean();
            }
            self::assertSame(GateModes::MEASUREMENT_FAILED, $exit, $report->render());
            self::assertSame([FailureClass::NONDETERMINISM_UNDECLARED], $report->failureClasses(), $report->render());
            self::assertSame('case:alpha|format:json', $report->raised()[0]['scope']);
            self::assertSame('5', Fs::read($root . '/replay/ranking-pass-count'));
            self::assertSame($before, Fs::read($path));
        } finally {
            SyntheticTree::remove($root);
        }
    }

    #[Test]
    public function itRefusesUnknownPublisherExpressionsInsteadOfInventingAnEmptySchema(): void
    {
        $root = Fs::temporaryDirectory('ranked-source-schema-');
        try {
            Fs::write($root . '/src/Reporting/Formatter/Json/JsonFormatter.php', '<?php final class JsonFormatter { private function formatTopIssues() { return []; } }');
            $this->expectException(GateError::class);
            RankingSchema::derive($root);
        } finally {
            Fs::removeRecursively($root);
        }
    }

    /** @return iterable<string,array{string}> */
    public static function malformedRankings(): iterable
    {
        foreach (['non-prefix', 'declared-score-non-prefix', 'missing', 'duplicate', 'ascending', 'rank', 'unknown-field', 'exit', 'stderr', 'support-total', 'support-by-rule', 'support-truncated', 'support-original', 'support-ranking', 'support-exit', 'support-stderr', 'support-filter'] as $mutation) {
            yield $mutation => [$mutation];
        }
    }

    #[Test]
    #[DataProvider('malformedRankings')]
    public function itRefusesEachBrokenSideLocalRankingThroughThePublicGate(string $mutation): void
    {
        $records = self::records(3);
        $issues = self::issues($records, [30, 20, 10]);
        $complete = \in_array($mutation, ['missing', 'duplicate'], true);
        $tree = self::rankedTree($records, $issues, $complete ? 0 : 2, $complete ? null : 1);
        $key = 'case:alpha|format:json';
        $answer = self::answer($tree, $key);
        $original = ReportRecords::decode($answer['stdout']);
        $full = ReportRecords::decode($answer['ranked']['stdout']);
        $support = $complete ? [] : ReportRecords::decode($answer['physical']['stdout'] ?? throw new GateError('The truncated fixture support is missing.'));
        if (\in_array($mutation, ['non-prefix', 'declared-score-non-prefix'], true)) {
            $original['topIssues'] = [$issues[1], $issues[2]];
            foreach ($original['topIssues'] as $index => &$issue) {
                $issue['rank'] = $index + 1;
            }
            unset($issue);
            if ($mutation === 'declared-score-non-prefix') {
                $tree['declarations'][DeclaredValues::INDEX] = Tsv::render(DeclaredValues::COLUMNS, [['field', 'ranking.impactScore', '*', 'Change an explicitly named score.']]);
            }
        } elseif ($mutation === 'missing') {
            array_pop($full['topIssues']);
        } elseif ($mutation === 'duplicate') {
            $full['topIssues'][2] = array_replace($full['topIssues'][1], ['rank' => 3]);
        } elseif ($mutation === 'ascending') {
            $full['topIssues'][1]['impactScore'] = 40;
        } elseif ($mutation === 'rank') {
            $full['topIssues'][1]['rank'] = 7;
        } elseif ($mutation === 'unknown-field') {
            foreach ($full['topIssues'] as &$issue) {
                $issue['unclassified'] = 7;
            }
            unset($issue);
        } elseif ($mutation === 'support-total') {
            $support['violationsMeta']['total'] = 4;
        } elseif ($mutation === 'support-by-rule') {
            $support['violationsMeta']['byRule'] = ['neighbour' => 3];
        } elseif ($mutation === 'support-truncated') {
            $support['violationsMeta']['truncated'] = true;
        } elseif ($mutation === 'support-original') {
            $support['violations'][0]['threshold'] = 999;
        } elseif ($mutation === 'support-ranking') {
            $support['topIssues'][2]['impactScore'] = 9;
        } elseif ($mutation === 'support-filter') {
            array_pop($support['violations']);
            array_pop($support['topIssues']);
            $support['violationsMeta'] = ['total' => 2, 'shown' => 2, 'truncated' => false, 'byRule' => ['replay.alpha' => 2]];
        }
        $answer['stdout'] = ValueCheck::value($original);
        $answer['ranked']['stdout'] = ValueCheck::value($full);
        if (!$complete) {
            $answer['physical']['stdout'] = ValueCheck::value($support);
        }
        if (\in_array($mutation, ['exit', 'stderr', 'support-exit', 'support-stderr'], true)) {
            $slot = str_starts_with($mutation, 'support-') ? 'physical' : 'ranked';
            $field = str_ends_with($mutation, 'exit') ? 'exit' : 'stderr';
            if ($field === 'exit') {
                $answer[$slot]['exit'] = 2;
            } else {
                $answer[$slot]['stderr'] = 'Unexpected internal warning.';
            }
        }
        $tree['candidateAnswers'][$key] = $answer;
        if ($mutation === 'declared-score-non-prefix') {
            $records = self::records(4);
            $tree = self::rankedTree($records, self::issues($records, [30, 20, 14, 10]), 3);
            $full = self::issues([$records[1], $records[0], $records[2], $records[3]], [20, 15, 14, 10]);
            $slice = array_map(static fn(array $issue, int $index): array => array_replace($issue, ['rank' => $index + 1]), \array_slice($full, 1), [0, 1, 2]);
            $tree['candidateAnswers'][$key] = ['stdout' => self::document($records, $slice), 'ranked' => ['stdout' => self::document($records, $full)]];
            $tree['declarations'][DeclaredValues::INDEX] = Tsv::render(DeclaredValues::COLUMNS, [['field', 'ranking.impactScore', '*', 'Reduce the explicitly declared first score.']]);
            $tree['declarations'][DeclaredValues::DERIVED] = Tsv::render(DeclaredValues::DERIVED_COLUMNS, [['field', 'ranking.impactScore', 'case:alpha|format:json|record:' . ReportRecords::identity('json', $records[0]), '30', '15']]);
        }
        $this->red($tree, FailureClass::RANKING_PROJECTION_MISMATCH);
    }

    #[Test]
    public function itUsesCompletePhysicalAuthorityWhenOriginalCapsHideRankedFindings(): void
    {
        $records = self::records(3);
        $tree = self::rankedTree($records, self::issues($records, [30, 20, 10]), 2, 1);
        $tree['declarations']['cases/alpha/case.json'] = self::definition(['--detail=1', '--format-opt=violations=1']);
        $this->green($tree);
        foreach (['metricValue', 'threshold', 'subject'] as $field) {
            $mutated = $tree;
            $hidden = $records;
            $hidden[2][$field] = $field === 'subject' ? 'declaration:callable:Replay\\Neighbour::run@src/Neighbour.php' : 7;
            $answer = self::answer($mutated, 'case:alpha|format:json');
            $answer['physical']['stdout'] = self::document($hidden, self::issues($hidden, [30, 20, 10]));
            $mutated['candidateAnswers']['case:alpha|format:json'] = $answer;
            $this->red($mutated, $field === 'subject' ? FailureClass::RECORD_UNDECLARED : FailureClass::VALUE_MISMATCH);
        }
    }

    #[Test]
    public function itLicensesAnExactHiddenPhysicalValueAndRefusesAnUnlistedHiddenKeyWithoutWriting(): void
    {
        $records = self::records(3);
        $issues = self::issues($records, [30, 20, 10]);
        $tree = self::rankedTree($records, $issues, 2, 1);
        $candidate = $records;
        $candidate[2]['metricValue'] = 7;
        $tree['candidateFindings']['alpha'] = $candidate;
        self::publish($tree, 'candidateAnswers', $candidate, $issues, 2, 1);
        $tree['declarations'][DeclaredValues::INDEX] = Tsv::render(DeclaredValues::COLUMNS, [['field', 'metricValue', '*', 'Change exactly one hidden physical magnitude.']]);
        $subject = 'case:alpha|format:json|record:' . ReportRecords::identity('json', $records[2]);
        $tree['declarations'][DeclaredValues::DERIVED] = Tsv::render(DeclaredValues::DERIVED_COLUMNS, [['field', 'metricValue', $subject, ValueCheck::value($records[2]['metricValue']), '7']]);
        $this->green($tree);
        $candidate[1]['threshold'] = 8;
        $tree['candidateFindings']['alpha'] = $candidate;
        self::publish($tree, 'candidateAnswers', $candidate, $issues, 2, 1);
        $root = SyntheticTree::create($tree);
        try {
            $before = Fs::read($root . '/finding-gate/' . DeclaredValues::DERIVED);
            $report = new GateReport();
            $written = (new \QmxFindingGate\Gate(Options::parse(['gate', '--candidate=' . $root, '--reference=HEAD'], $root), $report))->deriveDeclarations();
            self::assertSame([], $written);
            self::assertContains(FailureClass::VALUE_MISMATCH, $report->failureClasses(), $report->render());
            self::assertSame($before, Fs::read($root . '/finding-gate/' . DeclaredValues::DERIVED));
        } finally {
            SyntheticTree::remove($root);
        }
    }

    #[Test]
    public function itJudgesHiddenScoresAndNeverCreditsAnotherDuplicateOccurrence(): void
    {
        $records = self::records(2);
        $tree = self::rankedTree($records, self::issues($records, [30, 20]), 0, 1);
        $answer = self::answer($tree, 'case:alpha|format:json');
        $answer['ranked']['stdout'] = self::document([$records[0]], self::issues($records, [30, 19]), $records);
        $answer['physical']['stdout'] = self::document($records, self::issues($records, [30, 19]));
        $tree['candidateAnswers']['case:alpha|format:json'] = $answer;
        $this->red($tree, FailureClass::VALUE_MISMATCH);
        $x = $records[0];
        $y = $records[1];
        foreach ([true, false] as $mixed) {
            $reference = [$x, $x, $x, $y];
            $candidate = [$x, $x];
            $old = self::issues($reference, [30, 30, 30, 20]);
            $new = self::issues($candidate, $mixed ? [30, 29] : [29, 29]);
            $duplicates = self::rankedTree($reference, $old, 4);
            $duplicates['candidateFindings']['alpha'] = $candidate;
            self::publish($duplicates, 'candidateAnswers', $candidate, $new, 2);
            $duplicates['declarations'][DeclaredRecords::INDEX] = Tsv::render(DeclaredRecords::COLUMNS, [['withdrawn', 'alpha', 'json', 'format:json', '{"channel":"replay.alpha"}', 'Remove two declared exact finding occurrences.']]);
            $withdrawals = [['withdrawn', 'alpha', 'json', 'format:json', DeclaredRecords::canonical($x + ['ranking.impactScore' => 30, 'ranking.coupling.class-rank' => null])], ['withdrawn', 'alpha', 'json', 'format:json', DeclaredRecords::canonical($y + ['ranking.impactScore' => 20, 'ranking.coupling.class-rank' => null])]];
            sort($withdrawals);
            $duplicates['declarations'][DeclaredRecords::DERIVED] = Tsv::render(DeclaredRecords::DERIVED_COLUMNS, $withdrawals);
            $this->red($duplicates, $mixed ? FailureClass::RECORD_AMBIGUOUS : FailureClass::RECORD_UNDECLARED);
        }
        foreach ([1, 10] as $top) {
            $records = [$x, $x, $y];
            $duplicates = self::rankedTree($records, self::issues($records, [30, 30, 20]), min($top, 3));
            $answer = self::answer($duplicates, 'case:alpha|format:json');
            $bad = self::issues($records, [30, 29, 20]);
            $answer['ranked']['stdout'] = self::document($records, $bad);
            $answer['stdout'] = self::document($records, \array_slice($bad, 0, min($top, 3)));
            $duplicates['candidateAnswers']['case:alpha|format:json'] = $answer;
            $this->red($duplicates, FailureClass::RECORD_AMBIGUOUS);
        }
    }

    #[Test]
    public function itLicensesOnlyTheExactUniqueRankingValueAndItsDerivationKey(): void
    {
        $records = self::records(2);
        $old = self::issues($records, [30, 20]);
        $new = self::issues($records, [31, 20]);
        $tree = self::rankedTree($records, $old, 2);
        self::publish($tree, 'candidateAnswers', $records, $new, 2);
        $this->red($tree, FailureClass::VALUE_MISMATCH);
        $tree['declarations'][DeclaredValues::INDEX] = Tsv::render(DeclaredValues::COLUMNS, [['field', 'ranking.impactScore', '*', 'Change one score.']]);
        $subject = 'case:alpha|format:json|record:' . ReportRecords::identity('json', $records[0]);
        $tree['declarations'][DeclaredValues::DERIVED] = Tsv::render(DeclaredValues::DERIVED_COLUMNS, [['field', 'ranking.impactScore', $subject, '30', '31']]);
        $this->green($tree);
        self::publish($tree, 'candidateAnswers', $records, self::issues($records, [31, 19]), 2);
        $this->red($tree, FailureClass::VALUE_MISMATCH);
    }

    #[Test]
    public function itMeasuresTieBreakAndInputOrderWithDeterministicDuplicateLabels(): void
    {
        $records = self::records(3);
        $old = self::issues($records, [10, 10, 10]);
        foreach ([array_reverse($records), [$records[1], $records[0], $records[2]]] as $reordered) {
            $tree = self::rankedTree($records, $old, 3);
            self::publish($tree, 'candidateAnswers', $records, self::issues($reordered, [10, 10, 10]), 3);
            $this->red($tree, FailureClass::RANKING_ORDER_MISMATCH);
        }
        $x = $records[0];
        $y = $records[1];
        $reference = [$x, $x, $y, $x];
        $candidate = [$x, $y, $x, $x];
        $tree = self::rankedTree($reference, self::issues($reference, [10, 10, 10, 10]), 4);
        self::publish($tree, 'candidateAnswers', $reference, self::issues($candidate, [10, 10, 10, 10]), 4);
        $this->red($tree, FailureClass::RANKING_ORDER_MISMATCH);
    }

    #[Test]
    public function itIgnoresOnlyOrderOutsideBothPublishedSlices(): void
    {
        $records = self::records(3);
        $tree = self::rankedTree($records, self::issues($records, [10, 10, 10]), 1);
        self::publish($tree, 'candidateAnswers', $records, self::issues([$records[0], $records[2], $records[1]], [10, 10, 10]), 1);
        $this->green($tree);
    }

    #[Test]
    public function itJudgesLimitsAsIntervalsOnEachOriginalPublication(): void
    {
        $records = self::records(10);
        $issues = self::issues($records, array_fill(0, 10, 10));
        foreach ([[10, 9], [3, 10]] as [$before, $after]) {
            $tree = self::rankedTree($records, $issues, $before);
            self::publish($tree, 'candidateAnswers', $records, $issues, $after);
            $this->red($tree, FailureClass::VALUE_MISMATCH);
            $tree['declarations'][DeclaredValues::INDEX] = Tsv::render(DeclaredValues::COLUMNS, [['field', 'topIssues.limit', '*', 'Change the original display cap.']]);
            $rows = [];
            foreach (['format:json', 'check:output:file'] as $publication) {
                $rows[] = ['field', 'topIssues.limit', 'case:alpha|' . $publication, ValueCheck::value(($before < 10 ? '=' : '>=') . $before), ValueCheck::value(($after < 10 ? '=' : '>=') . $after)];
            }
            sort($rows);
            $tree['declarations'][DeclaredValues::DERIVED] = Tsv::render(DeclaredValues::DERIVED_COLUMNS, $rows);
            $this->green($tree);
        }
    }

    #[Test]
    public function itDerivesAnExactWithdrawalThatPullsTheNextRankedFindingIntoTheSlice(): void
    {
        $reference = self::records(3);
        $candidate = \array_slice($reference, 1);
        $tree = self::rankedTree($reference, self::issues($reference, [30, 20, 10]), 1);
        $tree['candidateFindings']['alpha'] = $candidate;
        self::publish($tree, 'candidateAnswers', $candidate, self::issues($candidate, [20, 10]), 1);
        $tree['declarations'][DeclaredRecords::INDEX] = Tsv::render(DeclaredRecords::COLUMNS, [['withdrawn', 'alpha', 'json', 'format:json', ValueCheck::value(['channel' => 'replay.alpha', 'subject' => $reference[0]['subject']]), 'Withdraw precisely the former first finding.']]);
        $deltaRows = [];
        foreach (['baseline-file', 'check:output:file', 'format:gitlab', 'format:html', 'format:json', 'format:sarif'] as $surface) {
            $file = 'declared-delta/' . str_replace(':', '-', $surface) . '.diff';
            $deltaRows[] = ['case:alpha|' . $surface, $file, 'Measure the remaining aggregate and layout bytes of this exact withdrawal.'];
            $tree['declarations'][$file] = "a difference awaiting measurement\n";
        }
        $tree['declarations'][\QmxFindingGate\DeclaredDelta::INDEX] = Tsv::render(\QmxFindingGate\DeclaredDelta::COLUMNS, $deltaRows);
        $root = SyntheticTree::create($tree);
        try {
            $options = Options::parse(['gate', '--candidate=' . $root, '--reference=HEAD'], $root);
            $derived = new GateReport();
            (new \QmxFindingGate\Gate($options, $derived))->deriveDeclarations();
            self::assertSame(GateReport::EXIT_GREEN, $derived->exitCode(), $derived->render());
            $rows = Tsv::rows($root . '/finding-gate/' . DeclaredRecords::DERIVED, DeclaredRecords::DERIVED_COLUMNS);
            self::assertCount(1, $rows);
            self::assertSame(DeclaredRecords::canonical($reference[0] + ['ranking.impactScore' => 30, 'ranking.coupling.class-rank' => null]), $rows[0]['record']);
            $green = new GateReport();
            (new \QmxFindingGate\Gate($options, $green))->compare();
            self::assertSame(GateReport::EXIT_GREEN, $green->exitCode(), $green->render());
            self::assertSame([], DeclaredValues::load($root . '/finding-gate')->intents());
        } finally {
            SyntheticTree::remove($root);
        }
    }

    #[Test]
    public function itExcludesDeclaredScoreMovesFromTheUnchangedOrderPopulation(): void
    {
        $records = self::records(2);
        $tree = self::rankedTree($records, self::issues($records, [30, 20]), 2);
        self::publish($tree, 'candidateAnswers', $records, self::issues([$records[1], $records[0]], [20, 15]), 2);
        $tree['declarations'][DeclaredValues::INDEX] = Tsv::render(DeclaredValues::COLUMNS, [['field', 'ranking.impactScore', '*', 'Reduce the first score.']]);
        $subject = 'case:alpha|format:json|record:' . ReportRecords::identity('json', $records[0]);
        $tree['declarations'][DeclaredValues::DERIVED] = Tsv::render(DeclaredValues::DERIVED_COLUMNS, [['field', 'ranking.impactScore', $subject, '30', '15']]);
        $this->green($tree);
    }

    #[Test]
    public function itChecksSummaryRoundingAdviceProjectLocationTagAndDebt(): void
    {
        $records = self::records(2);
        $records[0]['recommendation'] = "Use a boundary.\nRetain this evidence.";
        $records[1] = array_replace($records[1], ['file' => null, 'line' => null, 'subject' => 'project:', 'symbol' => '']);
        $issues = self::issues($records, [20, 11.25]);
        $tree = self::rankedTree($records, $issues, 2);
        $tree['cases']['alpha'][] = 'replay.alpha@project';
        $tree['static']['replay.alpha'][] = 'project';
        $tree['fixture']['replay.alpha'][] = 'project';
        $definition = ReportRecords::decode($tree['declarations']['cases/alpha/case.json']);
        $definition['channels'] = $tree['cases']['alpha'];
        $tree['declarations']['cases/alpha/case.json'] = ValueCheck::value($definition);
        self::completeReadableAnswers($tree, $records);
        $this->green($tree);
        foreach (['location', 'message', 'tag', 'debt'] as $mutation) {
            $bad = $tree;
            $text = ($tree['answers']['case:alpha|format:summary']['stdout'] ?? throw new GateError('The fixture summary is missing.'));
            $text = match ($mutation) {
                'location' => str_replace('[project]', 'src/Neighbour.php:99', $text),
                'message' => str_replace('Use a boundary.', 'Unexpected advice.', $text),
                'tag' => str_replace('[ERR]', '[WRN]', $text),
                default => str_replace('[15min]', '[16min]', $text),
            };
            $bad['candidateAnswers']['case:alpha|format:summary'] = ['stdout' => $text];
            $this->red($bad, FailureClass::RANKING_PROJECTION_MISMATCH);
        }
    }

    #[Test]
    public function itMeasuresRankingSchemaExtensionsAndRemovedMembersWithoutAddingAG1View(): void
    {
        foreach (['added-probe', 'removed-rank', 'removed-impactScore', 'removed-file'] as $change) {
            $records = self::records(2);
            $issues = self::issues($records, [30, 20]);
            $tree = self::rankedTree($records, $issues, 0);
            [$direction, $field] = explode('-', $change, 2);
            $tree['declarations'][DeclaredFields::INDEX] = Tsv::render(DeclaredFields::COLUMNS, [[$direction, 'json', 'ranking', $field, 'Change the explicitly classified ranked member.']]);
            $candidate = $issues;
            foreach ($candidate as &$issue) {
                if ($direction === 'added') {
                    $issue[$field] = 7;
                } else {
                    unset($issue[$field]);
                }
            }
            unset($issue);
            self::publish($tree, 'candidateAnswers', $records, $candidate, 0);
            $rows = [];
            if ($direction === 'added') {
                foreach ($issues as $issue) {
                    $rows[] = ['json', 'ranking', $field, 'alpha', 'source:format:json|join:' . RankingSchema::joinKey($issue, true), '7'];
                }
            }
            sort($rows);
            $tree['declarations'][DeclaredFields::DERIVED] = Tsv::render(DeclaredFields::DERIVED_COLUMNS, $rows);
            $report = $this->reportFor($tree, static function (string $root) use ($direction, $field): void {
                $path = $root . '/src/Reporting/Formatter/Json/JsonFormatter.php';
                $source = Fs::read($path);
                $source = $direction === 'added'
                    ? str_replace("                'rank' => null,", "                'rank' => null,\n                'probe' => null,", $source)
                    : str_replace("                '" . $field . "' => null,\n", '', $source);
                Fs::write($path, $source);
            });
            self::assertSame(GateReport::EXIT_GREEN, $report->exitCode(), $change . ': ' . $report->render());
            self::assertNotContains('ranking', \QmxFindingGate\ReportViews::views('json'));
        }
    }

    #[Test]
    public function itSuppliesRankingFieldsOnceAsTheFullOwnSourceUnionWithDuplicateOccurrences(): void
    {
        $main = self::records(1);
        $source = [$main[0], $main[0]];
        $mainIssues = self::issues($main, [30]);
        $sourceIssues = self::issues($source, [30, 30]);
        $tree = self::rankedTree($main, $mainIssues, 0);
        $tree['declarations']['cases/alpha/baseline-src/src/Alpha.php'] = "<?php\n";
        $tree['answers']['case:alpha|check:baseline-source'] = ['stdout' => self::document($source, []), 'ranked' => ['stdout' => self::document($source, $sourceIssues)]];
        $baseline = ValueCheck::value(['version' => 13, 'scope' => ['src'], 'entries' => [$main[0]['subject'] => [['channel' => $main[0]['channel'], 'magnitudes' => [$main[0]['metricValue'], $main[0]['metricValue']]]]]]);
        $tree['answers']['case:alpha|baseline-file'] = ['stdout' => $baseline, 'file' => $baseline];
        $tree['declarations'][DeclaredFields::INDEX] = Tsv::render(DeclaredFields::COLUMNS, [['added', 'json', 'ranking', 'probe', 'Measure every complete own ranked source.']]);
        $rows = [];
        foreach (['format:json' => $mainIssues, 'check:baseline-source' => $sourceIssues] as $view => $issues) {
            $probe = $view === 'format:json' ? 7 : 8;
            foreach ($issues as &$issue) {
                $rows[] = ['json', 'ranking', 'probe', 'alpha', 'source:' . $view . '|join:' . RankingSchema::joinKey($issue, true), (string) $probe];
                $issue['probe'] = $probe;
            }
            unset($issue);
            $physical = $view === 'format:json' ? $main : $source;
            $tree['candidateAnswers']['case:alpha|' . $view] = ['stdout' => self::document($physical, []), 'ranked' => ['stdout' => self::document($physical, $issues)]];
        }
        sort($rows);
        $tree['declarations'][DeclaredFields::DERIVED] = Tsv::render(DeclaredFields::DERIVED_COLUMNS, $rows);
        $prepare = static function (string $root): void {
            $path = $root . '/src/Reporting/Formatter/Json/JsonFormatter.php';
            Fs::write($path, str_replace("                'rank' => null,", "                'rank' => null,\n                'probe' => null,", Fs::read($path)));
        };
        $report = $this->reportFor($tree, $prepare);
        self::assertSame(GateReport::EXIT_GREEN, $report->exitCode(), $report->render());
        self::assertCount(3, $rows);
        $swapped = $tree;
        $swapped['declarations'][DeclaredFields::DERIVED] = Tsv::render(DeclaredFields::DERIVED_COLUMNS, array_map(static fn(array $row): array => array_replace($row, [4 => str_replace('source:check:baseline-source', 'source:format:json', $row[4])]), $rows));
        $report = $this->reportFor($swapped, $prepare);
        self::assertContains(FailureClass::FIELD_VALUES_MISMATCH, $report->failureClasses(), $report->render());
    }

    #[Test]
    public function itUsesOwnBaselineRankingForPromotedBreachAndRejectsItsScoreAndPrefixDrift(): void
    {
        $records = self::records(2);
        $issues = self::issues($records, [30, 20]);
        $tree = self::rankedTree($records, $issues, 2);
        $tree['declarations']['cases/alpha/baseline-src/src/Alpha.php'] = "<?php\n";
        $breach = array_replace($records[0], ['severity' => 'error', 'metricValue' => 4, 'acceptedLevel' => ['shape' => 'magnitude', 'describe' => '3', 'count' => 1]]);
        $promoted = self::issues([$breach], [60]);
        $baseline = ['stdout' => self::document([$breach], $promoted), 'ranked' => ['stdout' => self::document([$breach], $promoted)]];
        $tree['answers']['case:alpha|check:baseline'] = $baseline;
        $this->green($tree);
        $bad = $tree;
        $changed = self::issues([$breach], [59]);
        $bad['candidateAnswers']['case:alpha|check:baseline'] = ['stdout' => self::document([$breach], $changed), 'ranked' => ['stdout' => self::document([$breach], $changed)]];
        $this->red($bad, FailureClass::VALUE_MISMATCH);
        $bad = $tree;
        $bad['candidateAnswers']['case:alpha|check:baseline'] = ['stdout' => self::document($records, [array_replace($issues[1], ['rank' => 1])]), 'ranked' => ['stdout' => self::document($records, $issues)]];
        $this->red($bad, FailureClass::RANKING_PROJECTION_MISMATCH);
    }

    #[Test]
    public function itKeepsTheReferencePairLabelWhenADeclaredMessageChanges(): void
    {
        $records = self::records(2);
        $tree = self::rankedTree($records, self::issues($records, [30, 20]), 2);
        $changed = $records;
        $changed[0]['message'] = 'New advice with the same identity.';
        $tree['candidateFindings']['alpha'] = $changed;
        self::publish($tree, 'candidateAnswers', $changed, self::issues($changed, [30, 20]), 2);
        $tree['declarations'][DeclaredValues::INDEX] = Tsv::render(DeclaredValues::COLUMNS, [['field', 'message', '*', 'Change a matched message.']]);
        $subject = 'case:alpha|format:json|record:' . ReportRecords::identity('json', $records[0]);
        $tree['declarations'][DeclaredValues::DERIVED] = Tsv::render(DeclaredValues::DERIVED_COLUMNS, [['field', 'message', $subject, ValueCheck::value($records[0]['message']), ValueCheck::value($changed[0]['message'])]]);
        $this->green($tree);
    }

    #[Test]
    public function itDerivesOnlyDeclaredScoreKeysAndUsesTheSameLcsForExactOrderRows(): void
    {
        $records = self::records(3);
        $tree = self::rankedTree($records, self::issues($records, [10, 10, 10]), 3);
        self::publish($tree, 'candidateAnswers', $records, self::issues(array_reverse($records), [10, 10, 10]), 3);
        $tree['declarations'][DeclaredValues::INDEX] = Tsv::render(DeclaredValues::COLUMNS, [['order', 'ranking', '*', 'Reverse equal-score occurrences.']]);
        $root = SyntheticTree::create($tree);
        try {
            $options = Options::parse(['gate', '--candidate=' . $root, '--reference=HEAD'], $root);
            $report = new GateReport();
            $paths = (new \QmxFindingGate\Gate($options, $report))->deriveDeclarations();
            self::assertSame(GateReport::EXIT_GREEN, $report->exitCode(), $report->render());
            self::assertContains(DeclaredValues::DERIVED, $paths);
            $rows = Tsv::rows($root . '/finding-gate/' . DeclaredValues::DERIVED, DeclaredValues::DERIVED_COLUMNS);
            self::assertCount(2, $rows);
            self::assertSame(['order', 'order'], array_column($rows, 'kind'));
            self::assertSame(['ranking', 'ranking'], array_column($rows, 'key'));
            self::assertContains(['2', '2'], array_map(static fn(array $row): array => [$row['from'], $row['to']], $rows));
            $green = new GateReport();
            (new \QmxFindingGate\Gate($options, $green))->compare();
            self::assertSame(GateReport::EXIT_GREEN, $green->exitCode(), $green->render());
            $bytes = Fs::read($root . '/finding-gate/' . DeclaredValues::DERIVED);
            Fs::write($root . '/finding-gate/' . DeclaredValues::DERIVED, str_replace("\t2\t2\n", "\t2\t3\n", $bytes));
            $red = new GateReport();
            (new \QmxFindingGate\Gate($options, $red))->compare();
            self::assertContains(FailureClass::VALUE_MISMATCH, $red->failureClasses(), $red->render());
        } finally {
            SyntheticTree::remove($root);
        }
        $tree = self::rankedTree($records, self::issues($records, [30, 20, 10]), 3);
        self::publish($tree, 'candidateAnswers', $records, self::issues([$records[1], $records[0], $records[2]], [20, 15, 10]), 3);
        $tree['declarations'][DeclaredValues::INDEX] = Tsv::render(DeclaredValues::COLUMNS, [['field', 'ranking.impactScore', '*', 'Only a score is declared.']]);
        $root = SyntheticTree::create($tree);
        try {
            $report = new GateReport();
            (new \QmxFindingGate\Gate(Options::parse(['gate', '--candidate=' . $root, '--reference=HEAD'], $root), $report))->deriveDeclarations();
            self::assertSame(GateReport::EXIT_GREEN, $report->exitCode(), $report->render());
            $rows = Tsv::rows($root . '/finding-gate/' . DeclaredValues::DERIVED, DeclaredValues::DERIVED_COLUMNS);
            self::assertSame(['field'], array_column($rows, 'kind'));
            self::assertSame(['ranking.impactScore'], array_column($rows, 'key'));
        } finally {
            SyntheticTree::remove($root);
        }
    }

    #[Test]
    public function itObservesTheExactRegisteredRankingRaiseSitesThroughThePublicGate(): void
    {
        $sites = \QmxFindingGate\RaiseSites::of(\dirname(__DIR__), \QmxFindingGate\RaiseSites::DECLARED_NAMES);
        foreach (\QmxFindingGate\SelfTestRecords::witnesses() as $witness) {
            if (!str_starts_with($witness['id'], 'ranking-')) {
                continue;
            }
            $report = $this->reportFor($witness['plant'](SyntheticTree::clean()));
            $observed = [];
            foreach ($report->raised() as $raised) {
                foreach ($sites->sites as $site) {
                    if ($site['file'] === $raised['file'] && $site['line'] === $raised['line']) {
                        $observed[] = [$raised['class'], $raised['scope'], $sites->identityOf($site['site'], $raised['chain'])];
                        break;
                    }
                }
            }
            foreach ($witness['expect'] as [$failure, $scope, $identity]) {
                self::assertNotSame([], array_values(array_filter($observed, static fn(array $row): bool => $row[0] === $failure && fnmatch($scope, $row[1]) && $row[2] === $identity)), $witness['id'] . ': ' . ValueCheck::value($observed));
            }
            foreach ($observed as $row) {
                $allowed = array_filter($witness['expect'], static fn(array $pattern): bool => $row[0] === $pattern[0] && fnmatch($pattern[1], $row[1]) && $row[2] === $pattern[2]);
                $allowed = [...$allowed, ...array_filter($witness['tolerate'], static fn(array $pattern): bool => $row[0] === $pattern[0] && fnmatch($pattern[1], $row[1]))];
                self::assertNotSame([], $allowed, $witness['id'] . ': ' . ValueCheck::value($row));
            }
            foreach ($witness['tolerate'] as $pattern) {
                self::assertNotSame([], array_filter($observed, static fn(array $row): bool => $row[0] === $pattern[0] && fnmatch($pattern[1], $row[1])), $witness['id'] . ': idle toleration');
            }
        }
    }

    /** @return list<array<string,mixed>> */
    private static function records(int $count): array
    {
        $base = SyntheticTree::clean()['findings']['alpha'][0];
        $records = [];
        for ($index = 0; $index < $count; ++$index) {
            $records[] = array_replace($base, ['file' => 'src/Item' . $index . '.php', 'subject' => 'declaration:callable:Replay\\Item' . $index . '::run@src/Item' . $index . '.php', 'symbol' => 'Replay\\Item' . $index . '::run', 'message' => 'Issue ' . $index]);
        }
        return $records;
    }

    /** @param list<array<string,mixed>> $records
     * @param list<int|float> $scores
     *
     * @return list<array<string,mixed>>
     */
    private static function issues(array $records, array $scores): array
    {
        return array_map(static fn(array $record, int $index): array => self::issue($record, $index + 1, $scores[$index]), $records, array_keys($records));
    }

    /** @param list<array<string,mixed>> $records
     * @param list<array<string,mixed>> $issues
     *
     * @return Specification
     */
    private static function rankedTree(array $records, array $issues, int $slice, ?int $shown = null): array
    {
        $tree = SyntheticTree::clean();
        $tree['findings']['alpha'] = $records;
        $tree['declarations']['cases/alpha/case.json'] = self::definition();
        self::publish($tree, 'answers', $records, $issues, $slice, $shown);
        return $tree;
    }

    /** @param Specification $tree
     * @param list<array<string,mixed>> $records
     * @param list<array<string,mixed>> $issues
     * @param 'answers'|'candidateAnswers' $side
     */
    private static function publish(array &$tree, string $side, array $records, array $issues, int $slice, ?int $shown = null): void
    {
        $shown ??= \count($records);
        $published = \array_slice($records, 0, $shown);
        $answer = ['stdout' => self::document($published, \array_slice($issues, 0, $slice), $records), 'ranked' => ['stdout' => self::document($published, $issues, $records)]];
        if ($shown < \count($records)) {
            $answer['physical'] = ['stdout' => self::document($records, $issues)];
        }
        $tree[$side]['case:alpha|format:json'] = $answer;
        foreach (['check:output', 'check:parallel'] as $alias) {
            $tree[$side]['case:alpha|' . $alias] = $alias === 'check:output' ? ['file' => $answer['stdout']] : ['stdout' => $answer['stdout']];
        }
        $text = "Analysis complete\n";
        foreach (\array_slice($issues, 0, $slice) as $issue) {
            $record = null;
            foreach ($records as $physical) {
                if (RankingSchema::joinKey($physical, false) === RankingSchema::joinKey($issue, true)) {
                    $record = $physical;
                    break;
                }
            }
            if ($record === null) {
                throw new GateError('The test summary has no matching physical record.');
            }
            $tag = match ($issue['severity']) {
                'error' => 'ERR', 'warning' => 'WRN', default => 'INF',
            };
            $location = $issue['file'] === null ? '[project]' : $issue['file'] . ($issue['line'] === null ? '' : ':' . $issue['line']);
            $symbol = $issue['symbol'] === '' ? '' : ' (' . substr($issue['symbol'], (int) strrpos('\\' . $issue['symbol'], '\\')) . ')';
            $text .= '  ' . $issue['rank'] . '. [' . $tag . '] ' . \sprintf('%.1f', $issue['impactScore']) . '  ' . $location . "  [15min]\n         " . $record['code'] . ': ' . ReportRecords::message($record, true) . $symbol . "\n";
        }
        $tree[$side]['case:alpha|format:summary'] = ['stdout' => $text];
    }

    /** @param list<string> $args */
    private static function definition(array $args = []): string
    {
        return ValueCheck::value(['id' => 'alpha', 'description' => 'Independent ranked publication.', 'paths' => ['src'], 'config' => 'qmx.yaml', 'args' => $args, 'channels' => ['replay.alpha@callable']]);
    }

    /** @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private static function issue(array $record, int $rank, int|float $score): array
    {
        return ['rank' => $rank, ...array_intersect_key($record, array_flip(RankingSchema::PROJECTION)), 'impactScore' => $score, 'coupling.class-rank' => null, 'debtMinutes' => $record['techDebtMinutes']];
    }

    /** @param list<array<string,mixed>> $records
     * @param list<array<string,mixed>> $issues
     * @param list<array<string,mixed>>|null $full
     */
    private static function document(array $records, array $issues, ?array $full = null): string
    {
        $full ??= $records;
        $counts = [];
        foreach ($full as $record) {
            $rule = (string) $record['rule'];
            $counts[$rule] = ($counts[$rule] ?? 0) + 1;
        }
        return ValueCheck::value(['topIssues' => $issues, 'violations' => $records, 'violationsMeta' => ['total' => \count($full), 'shown' => \count($records), 'limit' => \count($records) < \count($full) ? \count($records) : null, 'truncated' => \count($records) < \count($full), 'byRule' => $counts]]);
    }

    /** @param Specification $tree
     * @param list<array<string,mixed>> $records
     */
    private static function completeReadableAnswers(array &$tree, array $records): void
    {
        $fingerprints = \QmxFindingGate\Fingerprints::expected($records);
        $gitlab = [];
        $checkstyle = '<checkstyle>';
        $prose = '';
        $github = '';
        foreach ($records as $index => $record) {
            $gitlab[] = ReportRecords::projection('format:gitlab', $record) + ['fingerprint' => md5($fingerprints[$index])];
            $projection = ReportRecords::projection('format:checkstyle', $record);
            $checkstyle .= '<file name="' . htmlspecialchars($projection['file'], \ENT_XML1) . '"><error line="' . $projection['line'] . '" severity="' . $projection['severity'] . '" source="' . $projection['code'] . '" message="' . htmlspecialchars($projection['message'], \ENT_XML1) . '"/></file>';
            $file = $record['file'] ?? '[project]';
            $brief = $record['symbol'] === '' ? '' : substr($record['symbol'], (int) strrpos('\\' . $record['symbol'], '\\'));
            $prose .= $file . " (1 violation)\n  ERROR" . ($record['line'] === null ? '' : ' at line ' . $record['line']) . ($brief === '' ? '' : '  ' . $brief) . "\n    " . ReportRecords::message($record, true) . '  [' . $record['code'] . "]\n\n";
            $properties = $record['file'] === null ? '' : 'file=' . $record['file'] . ',line=' . $record['line'] . ',';
            $github .= '::error ' . $properties . 'title=' . $record['code'] . '::' . str_replace("\n", '%0A', ReportRecords::message($record)) . "\n";
        }
        $tree['answers']['case:alpha|format:gitlab'] = ['stdout' => ValueCheck::value($gitlab)];
        $tree['answers']['case:alpha|format:checkstyle'] = ['stdout' => $checkstyle . '</checkstyle>'];
        foreach (['format:text', 'format:text-verbose', 'show-suppressed'] as $surface) {
            $tree['answers']['case:alpha|' . $surface] = ['stdout' => $prose];
        }
        $tree['answers']['case:alpha|format:github'] = ['stdout' => $github];
    }

    /** @param Specification $tree
     * @return array{stdout:string,ranked:array{stdout:string,stderr?:string,exit?:int},physical?:array{stdout:string,stderr?:string,exit?:int}}
     */
    private static function answer(array $tree, string $key): array
    {
        $answer = $tree['answers'][$key];
        if (!isset($answer['stdout'], $answer['ranked']['stdout'])) {
            throw new GateError('The fixture requires an original and complete ranked stdout.');
        }
        $result = ['stdout' => $answer['stdout'], 'ranked' => ['stdout' => $answer['ranked']['stdout']]];
        if (isset($answer['physical']['stdout'])) {
            $result['physical'] = ['stdout' => $answer['physical']['stdout']];
        }
        return $result;
    }

    /** @param Specification $tree */
    private function green(array $tree): void
    {
        $report = $this->reportFor($tree);
        self::assertSame(GateReport::EXIT_GREEN, $report->exitCode(), $report->render());
    }

    /** @param Specification $tree */
    private function red(array $tree, string $failure): void
    {
        $report = $this->reportFor($tree);
        self::assertContains($failure, $report->failureClasses(), $report->render());
    }

    /** @param Specification $tree
     * @param (callable(string):void)|null $prepare
     */
    private function reportFor(array $tree, ?callable $prepare = null): GateReport
    {
        $root = SyntheticTree::create($tree);
        try {
            if ($prepare !== null) {
                $prepare($root);
            }
            $report = new GateReport();
            ob_start();
            try {
                GateModes::run(Options::parse(['gate', '--candidate=' . $root, '--reference=HEAD'], $root), $report);
            } finally {
                ob_end_clean();
            }
            return $report;
        } finally {
            SyntheticTree::remove($root);
        }
    }

    private static function plantRepeatedCaptureChange(string $root, string $change, int $pass = 2): void
    {
        $plant = <<<'PHP'
            if ($key === 'case:alpha|format:json' && in_array($capture, ['ranked', 'physical'], true)) {
                $counter = $tree . '/replay/ranking-pass-count';
                if ($capture === 'ranked') {
                    file_put_contents($counter, (string) ((is_file($counter) ? (int) file_get_contents($counter) : 0) + 1));
                }
                if ((int) file_get_contents($counter) === REPEATED_PASS) {
                    $document = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
                    $change = 'REPEATED_CHANGE';
                    if ($change === 'multiplicity') {
                        $issues = $document['topIssues'];
                        $document['topIssues'] = [$issues[0], $issues[2], $issues[2], $issues[2]];
                        foreach ($document['topIssues'] as $position => &$issue) {
                            $issue['rank'] = $position + 1;
                        }
                        unset($issue);
                        if ($capture === 'physical') {
                            $records = $document['violations'];
                            $document['violations'] = [$records[0], $records[2], $records[2], $records[2]];
                        }
                    } elseif ($change === 'threshold' && $capture === 'physical') {
                        $document['violations'][1]['threshold'] += 1;
                    } elseif ($change === 'order' && $capture === 'ranked') {
                        $document['topIssues'] = array_reverse($document['topIssues']);
                        foreach ($document['topIssues'] as $position => &$issue) {
                            $issue['rank'] = $position + 1;
                            $issue = array_reverse($issue, true);
                        }
                        unset($issue);
                    } elseif ($capture === 'ranked' && $change !== 'threshold') {
                        $document['topIssues'][0][$change] = (float) ($document['topIssues'][0][$change] ?? 0) + 1;
                    }
                    $stdout = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n";
                }
            }
            echo $stdout;
            PHP;
        $plant = str_replace(['REPEATED_PASS', 'REPEATED_CHANGE'], [(string) $pass, $change], $plant);
        $path = $root . '/bin/qmx';
        $binary = Fs::read($path);
        self::assertSame(1, substr_count($binary, 'echo $stdout;'));
        Fs::write($path, str_replace('echo $stdout;', $plant, $binary));
    }
}
