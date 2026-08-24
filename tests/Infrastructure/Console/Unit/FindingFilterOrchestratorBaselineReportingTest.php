<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\RuleExclusionStats;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryParser;
use Qualimetrix\Analysis\Policy\Baseline\BaselineLoader;
use Qualimetrix\Analysis\Policy\Inline\Suppression\SuppressionFilter;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisCoverage;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\Console\FindingFilterOrchestrator;
use Qualimetrix\Infrastructure\Git\GitScopeResolution;
use Qualimetrix\Reporting\FindingProjection\Contract\GitScopeQueryInterface;
use Qualimetrix\Reporting\FindingProjection\Contract\GitScopeRequest;
use Qualimetrix\Reporting\FindingProjection\Contract\GitScopeResult;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionOptions;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionResult;
use Qualimetrix\Reporting\FindingProjection\FindingProjector;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Covers the three reporting facts P4-D2 adds on top of the existing stale
 * / `--show-resolved` reporting in {@see FindingFilterOrchestratorTest}:
 *
 * - ADR 0017 — a group that shrank without vanishing is not "resolved".
 * - An entry the loaded baseline could not apply is reported as inert,
 *   naming symbol, channel, selector and reason, and never fails the run.
 * - ADR 0017 — a run narrower than the baseline's recorded `scope` is reported,
 *   never failed.
 *
 * A run with no `--baseline` is confirmed to print none of the three.
 */
#[CoversClass(FindingFilterOrchestrator::class)]
final class FindingFilterOrchestratorBaselineReportingTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    /**
     * ADR 0017: five `goto` statements were accepted for one symbol; only two
     * fire now. The group shrank but did not vanish — its identity is still
     * present in the measured set, so it is neither stale nor counted by
     * `--show-resolved`. This is a documented limitation, not a bug: the
     * design cannot tell which member repaired, only that the whole group
     * grew or shrank (ADR 0017).
     */
    #[Test]
    public function itDoesNotCountAShrunkButPresentGroupAsResolved(): void
    {
        $survivors = [
            self::gotoFinding('src/Legacy/bootstrap.php'),
            self::gotoFinding('src/Legacy/bootstrap.php'),
        ];

        $baselinePath = $this->writeBaseline([
            SymbolPath::forFile(RelativePath::fromString('src/Legacy/bootstrap.php'))->toCanonical() => [
                ['channel' => 'code-smell.goto#code-smell.goto', 'count' => 5],
            ],
        ]);

        $output = new BufferedOutput();
        $result = $this->filterAndReport(
            $this->createOrchestrator(),
            $this->createAnalysisResult($survivors),
            $this->createInput(['--baseline' => $baselinePath, '--show-resolved' => true]),
            self::diagnosticConsole($output),
            $this->createScopeResolution(['src']),
        );

        $display = $output->fetch();

        self::assertSame([], $result->findings, 'A group within its stored count must still be fully accepted.');
        self::assertStringNotContainsString('did not appear in this run', $display);
        self::assertStringNotContainsString('resolved', $display);
    }

    /**
     * ADR 0017: an entry addressing a channel no rule declares does not suppress,
     * and `check` names the symbol, the channel, the selector and the
     * reason — without failing the run.
     */
    #[Test]
    public function itReportsAnInertEntryNamingSymbolChannelSelectorAndReasonWithoutFailing(): void
    {
        $symbol = SymbolPath::forClass('App\\Legacy', 'Bootstrap');
        $symbolKey = MetricSubject::declaration(
            DeclarationPath::of($symbol, RelativePath::fromString('src/Legacy/Bootstrap.php'), DeclarationOrdinal::fromRank(0)),
        )->toCanonical();

        $baselinePath = $this->writeBaseline([
            $symbolKey => [
                ['channel' => 'retired.channel#retired.channel', 'count' => 1],
            ],
        ]);

        $output = new BufferedOutput();
        $result = $this->filterAndReport(
            $this->createOrchestrator(),
            $this->createAnalysisResult(),
            $this->createInput(['--baseline' => $baselinePath]),
            self::diagnosticConsole($output),
            $this->createScopeResolution(['src']),
        );

        $display = $output->fetch();

        self::assertStringContainsString('1 baseline entries could not be applied', $display);
        self::assertStringContainsString($symbolKey, $display);
        self::assertStringContainsString('retired.channel#retired.channel', $display);
        self::assertStringContainsString('channel is not declared by any rule', $display);
        self::assertMatchesRegularExpression('/\[[0-9a-f]{12}\]/', $display, 'The selector must be printed so a user can copy it.');
        self::assertSame([], $result->findings, 'An inapplicable entry must not suppress anything, but it also has no finding to report here.');
    }

    /**
     * ADR 0017: `check` reports a scope narrower than the baseline's recorded
     * one; it never fails on it — that guard belongs to the writing
     * commands, not to `check`.
     */
    #[Test]
    public function itReportsAScopeNarrowerThanTheBaselineWithoutFailing(): void
    {
        $baselinePath = $this->writeBaseline(entries: [], scope: ['src', 'tests']);

        $output = new BufferedOutput();
        $result = $this->filterAndReport(
            $this->createOrchestrator(),
            $this->createAnalysisResult(),
            $this->createInput(['--baseline' => $baselinePath]),
            self::diagnosticConsole($output),
            $this->createScopeResolution(['src']),
        );

        $display = $output->fetch();

        self::assertStringContainsString('does not cover the baseline', $display);
        self::assertStringContainsString('tests', $display);
        self::assertStringNotContainsString('Error:', $display);
        self::assertSame([], $result->findings);
    }

    /**
     * A run whose scope covers (or exceeds) the recorded one prints nothing
     * about a mismatch — only the narrowing direction is a problem (ADR 0017).
     */
    #[Test]
    public function itPrintsNoScopeMismatchWhenTheRunCoversTheRecordedScope(): void
    {
        $baselinePath = $this->writeBaseline(entries: [], scope: ['src']);

        $output = new BufferedOutput();
        $this->filterAndReport(
            $this->createOrchestrator(),
            $this->createAnalysisResult(),
            $this->createInput(['--baseline' => $baselinePath]),
            self::diagnosticConsole($output),
            $this->createScopeResolution(['src', 'tests']),
        );

        self::assertStringNotContainsString('does not cover', $output->fetch());
    }

    /**
     * **A run over the project root is the widest run there is**, so it
     * covers whatever the file recorded and there is nothing to report. It
     * used to report a mismatch against every baseline: the root has no
     * project-relative form, so it was compared as an absolute machine path
     * and matched no recorded segment at all.
     */
    #[Test]
    public function itPrintsNoScopeMismatchForARunOverTheProjectRoot(): void
    {
        $baselinePath = $this->writeBaseline(entries: [], scope: ['src', 'tests']);
        $projectRoot = AbsolutePath::fromString(sys_get_temp_dir());

        $output = new BufferedOutput();
        $this->filterAndReport(
            $this->createOrchestrator(),
            $this->createAnalysisResult(),
            $this->createInput(['--baseline' => $baselinePath]),
            self::diagnosticConsole($output),
            new GitScopeResolution(
                paths: [$projectRoot],
                fileDiscovery: self::createStub(FileDiscoveryInterface::class),
                gitClient: null,
                reportScope: null,
                projectRoot: $projectRoot,
            ),
        );

        self::assertStringNotContainsString('does not cover', $output->fetch());
    }

    /**
     * Without `--baseline`, none of the three baseline-reporting facts has
     * anything to report — `$filterResult->baselineScope` is `null` and
     * `$filterResult->inertEntries` is empty by construction.
     */
    #[Test]
    public function itPrintsNoneOfTheThreeBaselineMessagesWithoutABaselineOption(): void
    {
        $output = new BufferedOutput();
        $this->filterAndReport(
            $this->createOrchestrator(),
            $this->createAnalysisResult([self::gotoFinding('src/Legacy/bootstrap.php')]),
            $this->createInput(),
            self::diagnosticConsole($output),
            $this->createScopeResolution(['src']),
        );

        $display = $output->fetch();

        self::assertStringNotContainsString('did not appear in this run', $display);
        self::assertStringNotContainsString('could not be applied', $display);
        self::assertStringNotContainsString('does not cover', $display);
    }

    private static function gotoFinding(string $file): Finding
    {
        $path = RelativePath::fromString($file);
        $symbol = SymbolPath::forFile($path);

        return new Finding(
            location: new Location($path, 12),
            subject: MetricSubject::aggregate($symbol),
            symbolPath: $symbol,
            ruleName: 'code-smell.goto',
            code: 'code-smell.goto',
            message: 'Avoid goto',
            severity: Severity::Warning,
        );
    }

    /**
     * @param array<string, list<array<string, mixed>>> $entries
     * @param list<string> $scope
     */
    private function writeBaseline(array $entries, array $scope = ['src']): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'qmx_orch_reporting_baseline_') . '.json';
        $this->tempFiles[] = $path;

        file_put_contents($path, json_encode([
            'version' => 13,
            'generated' => '2026-08-05T12:00:00+03:00',
            'scope' => $scope,
            'entries' => $entries,
        ], \JSON_THROW_ON_ERROR));

        return $path;
    }

    private function filterAndReport(
        FindingFilterOrchestrator $orchestrator,
        AnalysisResult $result,
        ArrayInput $input,
        OutputInterface $output,
        GitScopeResolution $scopeResolution,
    ): FindingProjectionResult {
        $baselinePath = $input->getOption('baseline');

        return $orchestrator->filterAndReport(
            $result,
            $input,
            $output,
            $scopeResolution,
            new FindingProjectionOptions(
                baselinePath: \is_string($baselinePath) && $baselinePath !== '' ? $baselinePath : null,
            ),
        );
    }

    private function createOrchestrator(): FindingFilterOrchestrator
    {
        $declarations = StubChannelDeclarationRegistry::withDefaults();

        $pipeline = new FindingProjector(
            new SuppressionFilter(),
            new BaselineLoader(new BaselineEntryParser($declarations)),
            $declarations,
            new class implements GitScopeQueryInterface {
                public function resolve(GitScopeRequest $request): GitScopeResult
                {
                    return new GitScopeResult([], []);
                }
            },
        );

        $ruleExecutor = self::createStub(RuleExecutionInterface::class);
        $ruleExecutor->method('exclusionStats')->willReturn(new RuleExclusionStats());

        return new FindingFilterOrchestrator($pipeline, $ruleExecutor);
    }

    private static function diagnosticConsole(BufferedOutput $diagnostics): ConsoleOutput
    {
        $output = new ConsoleOutput($diagnostics->getVerbosity(), false);
        $output->setErrorOutput($diagnostics);

        return $output;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function createInput(array $options = []): ArrayInput
    {
        $definition = new InputDefinition([
            new InputOption('baseline', mode: InputOption::VALUE_OPTIONAL),
            new InputOption('exclude-path', mode: InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL, default: []),
            new InputOption('exclude-namespace', mode: InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL, default: []),
            new InputOption('report-strict', mode: InputOption::VALUE_NONE),
            new InputOption('no-suppression-annotations', mode: InputOption::VALUE_NONE),
            new InputOption('show-resolved', mode: InputOption::VALUE_NONE),
            new InputOption('show-suppressed', mode: InputOption::VALUE_NONE),
        ]);

        return new ArrayInput($options, $definition);
    }

    /**
     * @param list<Finding> $findings
     */
    private function createAnalysisResult(array $findings = []): AnalysisResult
    {
        $repository = self::createStub(MetricRepositoryInterface::class);

        return new AnalysisResult(
            findings: $findings,
            duration: 0.1,
            metrics: $repository,
            coverage: new AnalysisCoverage([RelativePath::fromString('Fixture.php')], [], []),
        );
    }

    /**
     * @param list<string> $paths project-relative analysed paths for this run
     */
    private function createScopeResolution(array $paths = []): GitScopeResolution
    {
        $projectRoot = AbsolutePath::fromString(sys_get_temp_dir());

        return new GitScopeResolution(
            paths: array_map(
                static fn(string $path): AbsolutePath => AbsolutePath::fromString($projectRoot->value() . '/' . $path),
                $paths,
            ),
            fileDiscovery: self::createStub(FileDiscoveryInterface::class),
            gitClient: null,
            reportScope: null,
            projectRoot: $projectRoot,
        );
    }
}
