<?php

declare(strict_types=1);

namespace QmxFindingGate\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\Fs;
use QmxFindingGate\Gate;
use QmxFindingGate\GateReport;
use QmxFindingGate\Options;
use QmxFindingGate\Process;
use QmxFindingGate\SyntheticTree;

final class SyntheticTreeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__) . '/classes.php';
    }

    #[Test]
    public function itRequiresAnExactKnownInvocationAndExposesTheChildEnvironment(): void
    {
        $tree = SyntheticTree::clean();
        $tree['answers']['tree|rules'] = ['env' => true];
        $root = SyntheticTree::create($tree);
        $cache = Fs::temporaryDirectory('replay-cache-');
        try {
            $unknown = Process::run([\PHP_BINARY, $root . '/bin/qmx', 'rules'], $root, environmentAdditions: ['QMX_GATE_INVOCATION' => 'tree|unknown']);
            self::assertSame(70, $unknown['exit']);
            self::assertStringContainsString('no answer', $unknown['stderr']);
            $known = Process::run([\PHP_BINARY, $root . '/bin/qmx', 'rules'], $root, environmentAdditions: ['QMX_GATE_INVOCATION' => 'tree|rules', 'XDG_CACHE_HOME' => $cache]);
            self::assertSame(0, $known['exit']);
            self::assertSame(['invocation' => 'tree|rules', 'cache' => $cache, 'locale' => 'C', 'timezone' => 'UTC', 'argv' => ['rules'], 'cwd' => $root], json_decode($known['stdout'], true, 512, \JSON_THROW_ON_ERROR));
        } finally {
            SyntheticTree::remove($root);
            Fs::removeRecursively($cache);
        }
    }
    #[Test]
    public function itPublishesOneSortedSarifCatalogEntryPerCode(): void
    {
        $tree = SyntheticTree::clean();
        $first = SyntheticTree::finding($tree['tuple'], 'replay.alpha', 'declaration:callable:Replay\\Alpha::run@src/Alpha.php');
        $second = SyntheticTree::finding($tree['tuple'], 'replay.alpha', 'declaration:callable:Replay\\Beta::run@src/Beta.php');
        $third = SyntheticTree::finding($tree['tuple'], 'replay.before', 'declaration:callable:Replay\\Before::run@src/Before.php');
        $tree['findings']['alpha'] = [$first, $second, $third];
        $root = SyntheticTree::create($tree);
        try {
            $result = Process::run(
                [\PHP_BINARY, $root . '/bin/qmx', 'check', '-f', 'sarif'],
                $root,
                environmentAdditions: ['QMX_GATE_INVOCATION' => 'case:alpha|format:sarif'],
            );
            self::assertSame(0, $result['exit']);
            $report = json_decode($result['stdout'], true, 512, \JSON_THROW_ON_ERROR);
            $run = $report['runs'][0];
            self::assertSame([['id' => 'replay.alpha'], ['id' => 'replay.before']], $run['tool']['driver']['rules']);
            self::assertSame([0, 0, 1], array_column($run['results'], 'ruleIndex'));
        } finally {
            SyntheticTree::remove($root);
        }
    }

    #[Test]
    public function itReplaysFullRankingsAndPhysicalFindingsSeparatelyFromTheOriginalSlice(): void
    {
        $tree = SyntheticTree::clean();
        $tree['findings']['alpha'] = [];
        for ($index = 0; $index < 12; ++$index) {
            $tree['findings']['alpha'][] = SyntheticTree::finding($tree['tuple'], 'replay.alpha', 'declaration:callable:Replay\\Alpha::run' . $index . '@src/Alpha.php');
        }
        $root = SyntheticTree::create($tree);
        try {
            $argv = [\PHP_BINARY, $root . '/bin/qmx', 'check', '-f', 'json', '--top=0', '--top=3', '--detail=1'];
            $environment = ['QMX_GATE_INVOCATION' => 'case:alpha|format:json'];
            $original = json_decode(Process::run($argv, $root, environmentAdditions: $environment)['stdout'], true, 512, \JSON_THROW_ON_ERROR);
            self::assertCount(1, $original['violations']);
            self::assertCount(3, $original['topIssues']);
            self::assertSame(['total' => 12, 'shown' => 1, 'limit' => 1, 'truncated' => true, 'byRule' => ['replay.alpha' => 12]], $original['violationsMeta']);
            $ranked = json_decode(Process::run([...$argv, '--top=13'], $root, environmentAdditions: [...$environment, 'QMX_GATE_CAPTURE' => 'ranked'])['stdout'], true, 512, \JSON_THROW_ON_ERROR);
            self::assertSame($original['violations'], $ranked['violations']);
            self::assertCount(12, $ranked['topIssues']);
            $physical = json_decode(Process::run([\PHP_BINARY, $root . '/bin/qmx', 'check', '-f', 'json', '--detail=all', '--format-opt=violations=all', '--top=13'], $root, environmentAdditions: [...$environment, 'QMX_GATE_CAPTURE' => 'physical'])['stdout'], true, 512, \JSON_THROW_ON_ERROR);
            self::assertCount(12, $physical['violations']);
            self::assertFalse($physical['violationsMeta']['truncated']);
            self::assertSame($ranked['topIssues'], $physical['topIssues']);
            $summary = Process::run([\PHP_BINARY, $root . '/bin/qmx', 'check', '-f', 'summary', '--top=0', '--top=3'], $root, environmentAdditions: ['QMX_GATE_INVOCATION' => 'case:alpha|format:summary']);
            self::assertSame(3, preg_match_all('/^  [0-9]+\. \[/m', $summary['stdout']));
            $emptyTop = Process::run([\PHP_BINARY, $root . '/bin/qmx', 'check', '-f', 'summary', '--top=0'], $root, environmentAdditions: ['QMX_GATE_INVOCATION' => 'case:alpha|format:summary']);
            self::assertSame("Analysis complete\n", $emptyTop['stdout']);
        } finally {
            SyntheticTree::remove($root);
        }
    }

    #[Test]
    public function itKeepsIndependentPrivateAnswerOverridesAndTheirOwnMetadata(): void
    {
        $tree = SyntheticTree::clean();
        $tree['candidateAnswers']['case:alpha|format:json'] = [
            'stdout' => 'original', 'stderr' => 'source diagnostic', 'exit' => 3,
            'ranked' => ['stdout' => 'ranked', 'stderr' => 'ranked diagnostic', 'exit' => 4],
            'physical' => ['stdout' => 'physical'],
        ];
        $root = SyntheticTree::create($tree);
        try {
            foreach (['' => ['original', 'source diagnostic', 3], 'ranked' => ['ranked', 'ranked diagnostic', 4], 'physical' => ['physical', 'source diagnostic', 3]] as $slot => [$stdout, $stderr, $exit]) {
                $environment = ['QMX_GATE_INVOCATION' => 'case:alpha|format:json'];
                if ($slot !== '') {
                    $environment['QMX_GATE_CAPTURE'] = $slot;
                }
                $capture = Process::run([\PHP_BINARY, $root . '/bin/qmx', 'check', '-f', 'json'], $root, environmentAdditions: $environment);
                self::assertSame(['stdout' => $stdout, 'stderr' => $stderr, 'exit' => $exit], $capture);
            }
        } finally {
            SyntheticTree::remove($root);
        }
    }

    #[Test]
    #[Group('finding-gate-e2e')]
    public function itKeepsGeneratedSummaryRowsWhenReusingAnAnswerAsAnOverride(): void
    {
        $tree = SyntheticTree::clean();
        $summary = SyntheticTree::caseAnswers('alpha', $tree['findings']['alpha'], false, [])['case:alpha|format:summary'];
        $tree['candidateAnswers']['case:alpha|format:summary'] = $summary;
        $root = SyntheticTree::create($tree);
        try {
            $capture = Process::run(
                [\PHP_BINARY, $root . '/bin/qmx', 'check', '-f', 'summary'],
                $root,
                environmentAdditions: ['QMX_GATE_INVOCATION' => 'case:alpha|format:summary'],
            );
            self::assertSame(0, $capture['exit']);
            self::assertSame("Analysis complete\n\nTop issues by impact\n" . implode('', $summary['summaryIssues'] ?? []), $capture['stdout']);
            self::assertSame(1, preg_match_all('/^  [0-9]+\. \[/m', $capture['stdout']));
            $report = new GateReport();
            (new Gate(Options::parse(['gate', '--candidate=' . $root, '--reference=HEAD'], $root), $report))->compare();
            self::assertSame(GateReport::VERDICT_GREEN, $report->verdict(), $report->render());
        } finally {
            SyntheticTree::remove($root);
        }

        $tree['candidateAnswers']['case:alpha|format:summary'] = ['stdout' => "Explicit summary.\n"];
        $root = SyntheticTree::create($tree);
        try {
            $capture = Process::run(
                [\PHP_BINARY, $root . '/bin/qmx', 'check', '-f', 'summary'],
                $root,
                environmentAdditions: ['QMX_GATE_INVOCATION' => 'case:alpha|format:summary'],
            );
            self::assertSame(0, $capture['exit']);
            self::assertSame("Explicit summary.\n", $capture['stdout']);
        } finally {
            SyntheticTree::remove($root);
        }
    }

    #[Test]
    public function itPreservesExplicitJsonBytesWhileSlicingOnlyTheRawTopList(): void
    {
        $source = <<<'JSON'
            { "before": {"number":1e+02,"text":"escaped \" quote ] and \\ slash"},
              "topIssues" : [
                {"impactScore":10.5,"nested":{"values":[1,{"text":"closing ] bracket"}]}},
                {"impactScore":2.000}
              ], "violations":[{},{}], "violationsMeta":{"total":2,"shown":2,"truncated":false}, "after":-0.00 }

            JSON;
        $ranked = str_replace('10.5,', '10.50,', $source);
        $slice = static fn(string $text): string => str_replace(
            ",\n    {\"impactScore\":2.000}",
            '',
            $text,
        );
        $tree = SyntheticTree::clean();
        $tree['candidateAnswers']['case:alpha|format:json'] = ['stdout' => $source, 'ranked' => ['stdout' => $ranked], 'physical' => ['stdout' => $ranked]];
        $tree['candidateAnswers']['case:alpha|check:output'] = ['file' => $ranked];
        $root = SyntheticTree::create($tree);
        try {
            $base = [\PHP_BINARY, $root . '/bin/qmx', 'check', '-f', 'json'];
            $environment = ['QMX_GATE_INVOCATION' => 'case:alpha|format:json'];
            self::assertSame($source, Process::run([...$base, '--top=3', '--detail=1'], $root, environmentAdditions: $environment)['stdout']);
            self::assertSame($slice($source), Process::run([...$base, '--top=1', '--detail=1'], $root, environmentAdditions: $environment)['stdout']);
            foreach (['ranked', 'physical'] as $slot) {
                $private = [...$environment, 'QMX_GATE_CAPTURE' => $slot];
                self::assertSame($ranked, Process::run([...$base, '--top=3'], $root, environmentAdditions: $private)['stdout']);
                self::assertSame($slice($ranked), Process::run([...$base, '--top=1'], $root, environmentAdditions: $private)['stdout']);
            }
            $output = $root . '/raw-output.json';
            $written = Process::run([...$base, '--top=1', '--detail=1', '--output=' . $output], $root, environmentAdditions: ['QMX_GATE_INVOCATION' => 'case:alpha|check:output']);
            self::assertSame(0, $written['exit']);
            self::assertSame($slice($ranked), Fs::read($output));
            $zero = Process::run([...$base, '--top=0'], $root, environmentAdditions: $environment)['stdout'];
            $start = strpos($source, "[\n");
            self::assertIsInt($start);
            $end = strpos($source, '], "violations"', $start);
            self::assertIsInt($end);
            self::assertSame(substr_replace($source, '[]', $start, $end - $start + 1), $zero);
        } finally {
            SyntheticTree::remove($root);
        }
    }

    #[Test]
    public function itKeepsBreachAnnotationsAndRawStructuredMessagesInTheirPublishedViews(): void
    {
        $tree = SyntheticTree::clean();
        foreach ([
            [['shape' => 'magnitude', 'describe' => '1', 'count' => 1], \PHP_INT_MAX, 'accepted at 1, now ' . \PHP_INT_MAX],
            [['shape' => 'occurrence', 'describe' => '1 occurrence', 'count' => 1], 3, 'accepted at 1 occurrence'],
        ] as [$accepted, $value, $fragment]) {
            $finding = SyntheticTree::finding($tree['tuple'], 'replay.alpha', 'declaration:callable:Replay\\Alpha::run@src/Alpha.php');
            $finding = array_replace($finding, ['message' => 'Message', 'recommendation' => 'Advice', 'acceptedLevel' => $accepted, 'metricValue' => $value, 'severity' => 'info']);
            $answers = SyntheticTree::caseAnswers('alpha', [$finding], false, []);
            $sarif = json_decode(self::publication($answers, 'format:sarif'), true, flags: \JSON_THROW_ON_ERROR);
            $gitlab = json_decode(self::publication($answers, 'format:gitlab'), true, flags: \JSON_THROW_ON_ERROR);
            self::assertSame('Message (' . $fragment . ')', $sarif['runs'][0]['results'][0]['message']['text']);
            self::assertSame('note', $sarif['runs'][0]['results'][0]['level']);
            self::assertSame('Message (' . $fragment . ')', $gitlab[0]['description']);
            self::assertSame('info', $gitlab[0]['severity']);
            self::assertStringContainsString('message="Message (' . $fragment . ')"', self::publication($answers, 'format:checkstyle'));
            self::assertStringContainsString('::Message (' . $fragment . ')', self::publication($answers, 'format:github'));
            self::assertStringContainsString('    Advice (' . $fragment . ')  [replay.alpha]', self::publication($answers, 'format:text-detail'));
            $html = json_decode(\QmxFindingGate\ReportPayload::of(self::publication($answers, 'format:html'), 'case:alpha|format:html', 'candidate'), true, flags: \JSON_THROW_ON_ERROR);
            self::assertSame('Message', $html['violations'][0]['message']);
        }
    }

    #[Test]
    public function itOmitsDetailedCaptionsForNamespaceAndFileSymbolsWithExactCallableSubjects(): void
    {
        $tree = SyntheticTree::clean();
        foreach (['ns:Replay\\Package', 'declaration:callable:Replay\\Alpha::run@src/Alpha.php'] as $subject) {
            $finding = SyntheticTree::finding($tree['tuple'], 'replay.alpha', $subject);
            $finding['symbol'] = str_starts_with($subject, 'ns:') ? 'Replay\\Package' : $finding['file'];
            $answers = SyntheticTree::caseAnswers('alpha', [$finding], false, []);
            $text = self::publication($answers, 'format:text-detail');
            self::assertStringContainsString('  ERROR ' . $finding['file'] . ':' . $finding['line'] . "\n    ", $text);
            self::assertStringNotContainsString('  ' . $finding['symbol'] . "\n", $text);
        }
    }

    /** @param array<string,array{stdout?:string}> $answers */
    private static function publication(array $answers, string $surface): string
    {
        self::assertArrayHasKey('case:alpha|' . $surface, $answers);
        $answer = $answers['case:alpha|' . $surface];
        self::assertArrayHasKey('stdout', $answer);

        return $answer['stdout'];
    }

}
