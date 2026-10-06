<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\LevelActivity;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExclusionStats;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionResult;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\SuppressionBinding\UnboundSuppressionAudit;
use Qualimetrix\Analysis\Finding\SuppressionBinding\UnboundSuppressionOptions;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryParser;
use Qualimetrix\Analysis\Policy\Baseline\BaselineLoader;
use Qualimetrix\Analysis\Policy\Baseline\EntryBinding\UnusedEntryAudit;
use Qualimetrix\Analysis\Policy\Inline\Contract\DirectiveObservations;
use Qualimetrix\Analysis\Policy\Inline\Suppression\SuppressionFilter;
use Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisCoverage;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Analysis\Run\Contract\Pipeline\MeasuredRunResult;

use Qualimetrix\Analysis\Run\Discovery\EntryInspector;
use Qualimetrix\Analysis\Run\Discovery\ProjectTree;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\FindingFilterOrchestrator;
use Qualimetrix\Infrastructure\Console\ResolvedCheckScope;
use Qualimetrix\Infrastructure\Git\GitScopeResolution;
use Qualimetrix\Reporting\FindingProjection\Contract\GitScopeQueryInterface;
use Qualimetrix\Reporting\FindingProjection\Contract\GitScopeRequest;
use Qualimetrix\Reporting\FindingProjection\Contract\GitScopeResult;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionOptions;
use Qualimetrix\Reporting\FindingProjection\FindingProjectionResult;
use Qualimetrix\Reporting\FindingProjection\FindingProjector;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\StubRuleCoverage;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Covers the three reporting facts added on top of the existing stale
 * / `--show-resolved` reporting in {@see FindingFilterOrchestratorTest}:
 *
 * - ADR 0017 — a group that shrank without vanishing is not "resolved".
 * - An entry the loaded baseline could not apply is reported as inert,
 *   naming symbol, channel, selector and reason, with Warning severity.
 * - ADR 0017 — a run narrower than the baseline's recorded `scope` is reported,
 *   never failed.
 *
 * A run with no `--baseline` is confirmed to print none of the three.
 */
#[CoversClass(FindingFilterOrchestrator::class)]
#[CoversClass(\Qualimetrix\Infrastructure\Console\BaselineFilterReporter::class)]
#[CoversClass(FindingProjectionResult::class)]
final class FindingFilterOrchestratorBaselineReportingTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    private string $tempRoot;

    protected function setUp(): void
    {
        $this->tempRoot = \Qualimetrix\Tests\Analysis\Policy\Baseline\Support\TempDirectory::create('qmx-orchestrator-report-');
        mkdir($this->tempRoot . '/src/Legacy', 0o755, true);
        file_put_contents($this->tempRoot . '/src/Legacy/bootstrap.php', '<?php');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        \Qualimetrix\Tests\Analysis\Policy\Baseline\Support\TempDirectory::remove($this->tempRoot);
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
    public function itReportsUncomparedReasonCountsAndOnlyStaleResolvedCounts(): void
    {
        $entry = static fn(string $path): \Qualimetrix\Analysis\Policy\Baseline\BaselineEntry => new \Qualimetrix\Analysis\Policy\Baseline\BaselineEntry(
            new \Qualimetrix\Analysis\Policy\Baseline\BaselineIdentity('file:' . $path, new \Qualimetrix\Analysis\Finding\Contract\FindingChannel('code-smell.goto')),
            null,
            1,
        );
        $stale = $entry('src/StaleSecret.php');
        $unmeasured = $entry('src/DisabledSecret.php');
        $outside = $entry('tests/OutsideSecret.php');
        $uncompared = $entry('src/IncompleteSecret.php');
        $inert = \Qualimetrix\Analysis\Policy\Baseline\InertBaselineEntry::forRaw('secret invalid entry', null, \Qualimetrix\Analysis\Policy\Baseline\InertEntryReason::Malformed, 'invalid count', []);
        $outcome = new \Qualimetrix\Analysis\Policy\Baseline\Contract\CeilingOutcome(
            new \Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterStageResult(\Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterStage::Baseline, [], []),
            [$stale],
            [$inert],
            [$unmeasured],
            [$outside],
            [$uncompared],
            reasons: [
                $unmeasured->identity->key() => 'rule-disabled',
                $outside->identity->key() => 'paths-differ',
                $uncompared->identity->key() => 'analysis-incomplete',
            ],
        );
        $result = new FindingProjectionResult(
            [],
            new \Qualimetrix\Analysis\Policy\Inline\Contract\AnnotationSuppressionResult([], [], []),
            staleEntries: [$stale],
            inertEntries: [$inert],
            ceilingOutcome: $outcome,
            unusedAuditPublished: false,
        );
        $output = new BufferedOutput();
        (new \Qualimetrix\Infrastructure\Console\BaselineFilterReporter($output, true))->report($result, [], AbsolutePath::fromString('/project'));
        $display = $output->fetch();
        self::assertStringContainsString('1 baseline entries have been resolved', $display);
        self::assertStringContainsString('2 baseline entries are unused (1 stale, 1 inert)', $display);
        self::assertStringContainsString('1 baseline entries were not measured by this run: rule-disabled', $display);
        self::assertStringContainsString("1 baseline entries lie outside this run's coverage: paths-differ", $display);
        self::assertStringContainsString('1 baseline entries were not compared: analysis-incomplete', $display);
        foreach ([$stale, $unmeasured, $outside, $uncompared] as $hidden) {
            self::assertStringNotContainsString($hidden->identity->subjectKey, $display);
            self::assertStringNotContainsString($hidden->selector()->value, $display);
        }
        self::assertStringNotContainsString($inert->selector->value, $display);
        self::assertStringNotContainsString('secret invalid entry', $display);
    }

    #[Test]
    public function itDoesNotCountAShrunkButPresentGroupAsResolved(): void
    {
        $survivors = [
            self::gotoFinding('src/Legacy/bootstrap.php'),
            self::gotoFinding('src/Legacy/bootstrap.php'),
        ];

        $baselinePath = $this->writeBaseline([
            SymbolPath::forFile(RelativePath::fromString('src/Legacy/bootstrap.php'))->toCanonical() => [
                ['channel' => 'code-smell.goto', 'count' => 5],
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
                ['channel' => 'retired.channel', 'count' => 1],
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

        self::assertSame('', $display);
        self::assertCount(1, $result->findings);
        $audit = $result->findings[0];
        $entry = $result->inertEntries[0];
        self::assertSame('baseline.unused-entry', $audit->channel()->code);
        self::assertSame(Severity::Warning, $audit->severity);
        self::assertStringContainsString($symbolKey, $audit->message);
        self::assertStringContainsString('retired.channel', $audit->message);
        self::assertStringContainsString('channel is not declared by any rule', $audit->message);
        self::assertStringContainsString($entry->selector->value, $audit->message);
        self::assertSame(\Qualimetrix\Analysis\Finding\Contract\OccurrenceKey::semantic('baseline-unused-entry', ['cause' => 'inert', 'selector' => $entry->selector->value])->value, $audit->occurrenceKey?->value);
        self::assertSame([], $result->measuredFindings);
        self::assertSame([], $result->removedBy(\Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterStage::Baseline));
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

        self::assertStringContainsString('does not cover 1 recorded baseline paths', $display);
        self::assertStringNotContainsString('tests', $display);
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
        $projectRoot = AbsolutePath::fromString($this->tempRoot);

        $output = new BufferedOutput();
        $this->filterAndReport(
            $this->createOrchestrator(),
            $this->createAnalysisResult(),
            $this->createInput(['--baseline' => $baselinePath]),
            self::diagnosticConsole($output),
            new GitScopeResolution(
                paths: [$projectRoot],
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
            'version' => 14,
            'generated' => '2026-08-05T12:00:00+03:00',
            'scope' => $scope,
            'exclusions' => ['patterns' => [], 'generated' => 'excluded'],
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

        $measurement = (new \Qualimetrix\Analysis\Run\Configuration\ProjectScopeCoverage(new \Qualimetrix\Infrastructure\Composer\ComposerManifestReader()))->measure(
            $scopeResolution->projectRoot,
            $scopeResolution->paths,
            AutoloadDevPolicy::Exclude,
            \Qualimetrix\Analysis\Run\Contract\Configuration\PathsAuthorship::Authored,
        );

        return $orchestrator->filterAndReport(
            $result,
            $input,
            $output,
            new ResolvedCheckScope($scopeResolution, [], $measurement),
            new FindingProjectionOptions(
                baselineDocument: \is_string($baselinePath) && $baselinePath !== '' ? BaselineLoader::preflight($baselinePath) : null,
            ),
            new RunConfiguration([], $scopeResolution->projectRoot, GeneratedFilePolicy::Exclude, $measurement, [], AutoloadDevPolicy::Exclude),
        );
    }

    private function createOrchestrator(): FindingFilterOrchestrator
    {
        $declarations = StubChannelDeclarationRegistry::withDefaults();
        $declarations->declare('code-smell.goto', ChannelDeclaration::occurrence(SymbolLevel::File));

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
            unusedEntryAudit: new UnusedEntryAudit((function () {
                $execution = self::createStub(\Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface::class);
                $execution->method('publishable')->willReturnCallback(static fn(array $findings): array => $findings);

                return $execution;
            })()),
        );

        return new FindingFilterOrchestrator($pipeline, new ErrorStream(), self::silentSuppressionAudit(), new \Qualimetrix\Infrastructure\Composer\ComposerManifestReader(), new \Qualimetrix\Infrastructure\Console\ObservedProjectScopeReasons(new \Qualimetrix\Infrastructure\Composer\ComposerManifestReader(), new \Qualimetrix\Infrastructure\Composer\ComposerAutoloadMap(new \Qualimetrix\Infrastructure\Composer\ComposerManifestReader())), new ProjectTree(new EntryInspector()), StubRuleCoverage::everyRuleRan());
    }

    /**
     * The suppression-binding channels are not this file's subject and have
     * their own end-to-end cases; switched off, the audit answers with an
     * empty list before it reads anything, so these cases keep measuring what
     * they measured.
     */
    private static function silentSuppressionAudit(): UnboundSuppressionAudit
    {
        $configuration = self::createStub(RuleConfigurationInterface::class);
        $snapshot = \Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture::build(
            \Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration::fromDocument(\Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture::document([['source' => 'config', 'values' => ['rules' => [\Qualimetrix\Analysis\Finding\SuppressionBinding\UnboundSuppressionRule::NAME => ['enabled' => false]]]]], \Qualimetrix\Core\Path\AbsolutePath::fromString('/project'))),
            [new \Qualimetrix\Analysis\Finding\Contract\RuleMetadata(\Qualimetrix\Analysis\Finding\SuppressionBinding\UnboundSuppressionRule::NAME, UnboundSuppressionOptions::class, '', [], false)],
        );
        $configuration->method('resolvedOptions')->willReturn($snapshot);
        return new UnboundSuppressionAudit(self::createStub(RuleExecutionInterface::class), $configuration);
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
            new InputOption('suppress-path', mode: InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL, default: []),
            new InputOption('suppress-namespace', mode: InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL, default: []),
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

        return AnalysisResult::fromRun(
            measured: new MeasuredRunResult(
                repository: $repository,
                coverage: new AnalysisCoverage([RelativePath::fromString('src/Legacy/bootstrap.php')], [], []),
                namespaceTree: null,
                projectScope: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement(new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse(AbsolutePath::fromString($this->tempRoot), true, [], [], [], true, []), [AbsolutePath::fromString($this->tempRoot)], \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeState::Covered, [], new \Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement()),
                duration: 0.1,
                subjectCoverage: \Qualimetrix\Analysis\Finding\Contract\ProjectScope\SubjectCoverageFacts::fromMeasured(new \Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement(), [RelativePath::fromString('src/Legacy/bootstrap.php')], []),
            ),
            directives: new DirectiveObservations(
                suppressions: [],
                thresholdOverrides: [],
            ),
            ruleExecution: new RuleExecutionResult($findings, $findings, new RuleExclusionStats(), LevelActivity::empty()),
            latePublished: [],
        );
    }

    /**
     * @param list<string> $paths project-relative analysed paths for this run
     */
    private function createScopeResolution(array $paths = []): GitScopeResolution
    {
        $projectRoot = AbsolutePath::fromString($this->tempRoot);

        return new GitScopeResolution(
            paths: array_map(
                static fn(string $path): AbsolutePath => AbsolutePath::fromString($projectRoot->value() . '/' . $path),
                $paths,
            ),
            gitClient: null,
            reportScope: null,
            projectRoot: $projectRoot,
        );
    }
}
