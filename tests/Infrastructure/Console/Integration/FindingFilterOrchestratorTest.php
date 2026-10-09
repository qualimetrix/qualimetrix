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
 * Verifies that per-rule `suppress_namespaces`, `suppress_namespace_channels`,
 * and `suppress_paths` suppression
 * ({@see RuleExecutionResult::exclusions()}, carried on {@see AnalysisResult}) is
 * surfaced by the orchestrator's
 * `-v` and `--show-suppressed` output, mirroring the existing global-filter
 * reporting (path/namespace exclusion counters).
 */
#[CoversClass(FindingFilterOrchestrator::class)]
final class FindingFilterOrchestratorTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    private string $tempRoot;

    protected function setUp(): void
    {
        $this->tempRoot = \Qualimetrix\Tests\Analysis\Policy\Baseline\Support\TempDirectory::create('qmx-orchestrator-');
        mkdir($this->tempRoot . '/src/Service', 0o755, true);
        file_put_contents($this->tempRoot . '/src/Service/UserService.php', '<?php');
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

    #[Test]
    public function itRetainsLateAuxiliaryCausesAndRootOmissionsOnAWithheldRun(): void
    {
        $root = sys_get_temp_dir() . '/qmx-late-manifest-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0777, true);
        mkdir($root . '/dependency');
        file_put_contents($root . '/composer.json', '{"autoload":{"files":["src/A.php"]}}');
        file_put_contents($root . '/src/A.php', '<?php');
        file_put_contents($root . '/dependency/composer.json', '[]');
        try {
            $reader = new \Qualimetrix\Infrastructure\Composer\ComposerManifestReader();
            $anchor = new \Qualimetrix\Infrastructure\Composer\ComposerAutoloadMap($reader);
            $anchor->pointAt($root, ['/qmx-missing-' . bin2hex(random_bytes(6)) . '/src']);
            $measurement = (new \Qualimetrix\Analysis\Run\Configuration\ProjectScopeCoverage($reader))->measure(AbsolutePath::fromString($root), [AbsolutePath::fromString($root . '/dependency')], \Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy::Exclude, \Qualimetrix\Analysis\Run\Contract\Configuration\PathsAuthorship::Authored);
            $reader->read(AbsolutePath::fromString($root . '/dependency'));
            $scope = new GitScopeResolution($measurement->paths, null, null, AbsolutePath::fromString($root));
            $measured = $measurement->withDiscoveredFiles(new \Qualimetrix\Analysis\Run\Contract\Discovery\DiscoveredProjectFiles([], [], [], [], [], new \Qualimetrix\Analysis\Run\Discovery\ScopeFacts([RelativePath::fromString('src/A.php')], [], [], false), 0));
            $report = $this->createOrchestrator($reader, $anchor)->projectScope(new ResolvedCheckScope($scope, [], $measurement), $this->createAnalysisResult(projectScope: $measured), new FindingProjectionOptions());
            self::assertSame('narrowed', $report->state);
            self::assertCount(10, $report->unjudgedChannels);
            self::assertSame([
                'architecture.empty-template',
                'architecture.unmatched-exclude',
                'architecture.unmatched-type',
                'architecture.unreachable-layer',
                'cohesion.unmatched-exclude-method',
                'coupling.unmatched-framework-namespace',
                'discovery.unmatched-exclude',
                'suppression.unmatched-namespace',
                'suppression.unmatched-path',
                'suppression.unmatched-rule-ledger',
            ], $report->unjudgedChannels);
            $reasons = array_map(static fn($reason): array => $reason->toArray(), $report->reasons);
            self::assertCount(2, $reasons);
            self::assertSame('manifest-issue', $reasons[0]['kind']);
            self::assertSame('invalid-root', $reasons[0]['issueKind']);
            self::assertTrue($reasons[0]['auxiliary']);
            self::assertSame(realpath($root . '/dependency/composer.json'), $reasons[0]['source']);
            self::assertSame('omitted-composer-root', $reasons[1]['kind']);
            self::assertSame('unresolvable', $reasons[1]['cause']);
            self::assertSame($report->reasons, $report->withReasons($report->reasons)->reasons);
        } finally {
            unlink($root . '/src/A.php');
            unlink($root . '/composer.json');
            unlink($root . '/dependency/composer.json');
            rmdir($root . '/src');
            rmdir($root . '/dependency');
            rmdir($root);
        }
    }

    #[Test]
    public function itPrintsNothingAboutRuleExclusionsWhenStatsAreEmptyAndNotVerbose(): void
    {
        $orchestrator = $this->createOrchestrator();

        $output = new BufferedOutput();
        $this->filterAndReport(
            $orchestrator,
            $this->createAnalysisResult(),
            $this->createInput(),
            self::diagnosticConsole($output),
            $this->createScopeResolution(),
        );

        self::assertStringNotContainsString('suppress_namespaces', $output->fetch());
    }

    #[Test]
    public function itPrintsNamespaceExclusionCountWhenVerbose(): void
    {
        $stats = new RuleExclusionStats(
            namespaceExclusionsByRule: ['complexity.ccn' => 2],
        );
        $orchestrator = $this->createOrchestrator();

        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $this->filterAndReport(
            $orchestrator,
            $this->createAnalysisResult(stats: $stats),
            $this->createInput(),
            self::diagnosticConsole($output),
            $this->createScopeResolution(),
        );

        $display = $output->fetch();
        self::assertStringContainsString(
            '2 violation(s) suppressed by per-rule suppress_namespaces/suppress_namespace_channels',
            $display,
        );
        self::assertStringContainsString('complexity.ccn: 2', $display);
    }

    #[Test]
    public function itPrintsPathExclusionCountWhenVerbose(): void
    {
        $stats = new RuleExclusionStats(
            pathExclusionsByRule: ['coupling.cbo' => 3],
        );
        $orchestrator = $this->createOrchestrator();

        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $this->filterAndReport(
            $orchestrator,
            $this->createAnalysisResult(stats: $stats),
            $this->createInput(),
            self::diagnosticConsole($output),
            $this->createScopeResolution(),
        );

        $display = $output->fetch();
        self::assertStringContainsString('3 violation(s) suppressed by per-rule suppress_paths', $display);
        self::assertStringContainsString('coupling.cbo: 3', $display);
    }

    #[Test]
    public function itDoesNotPrintRuleExclusionCountsWithoutVerboseFlag(): void
    {
        $stats = new RuleExclusionStats(namespaceExclusionsByRule: ['rule1' => 1]);
        $orchestrator = $this->createOrchestrator();

        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);
        $this->filterAndReport(
            $orchestrator,
            $this->createAnalysisResult(stats: $stats),
            $this->createInput(),
            self::diagnosticConsole($output),
            $this->createScopeResolution(),
        );

        self::assertStringNotContainsString('suppress_namespaces', $output->fetch());
    }

    #[Test]
    public function itPrintsExcludedFindingDetailsWithShowSuppressed(): void
    {
        $path = RelativePath::fromString('src/Service/UserService.php');
        $symbol = SymbolPath::forClass('App\\Tests', 'UserServiceTest');
        $finding = new Finding(
            location: new Location($path, 42),
            subject: MetricSubject::declaration(DeclarationPath::of($symbol, $path, DeclarationOrdinal::fromRank(0))),
            symbolPath: $symbol,
            ruleName: 'complexity.ccn',
            code: 'complexity.ccn',
            message: 'CCN too high',
            severity: Severity::Warning,
        );

        $stats = new RuleExclusionStats(
            namespaceExclusionsByRule: ['complexity.ccn' => 1],
            excludedFindings: [$finding],
        );
        $orchestrator = $this->createOrchestrator();

        $output = new BufferedOutput();
        $this->filterAndReport(
            $orchestrator,
            $this->createAnalysisResult(stats: $stats),
            $this->createInput(['--show-suppressed' => true]),
            self::diagnosticConsole($output),
            $this->createScopeResolution(),
        );

        $display = $output->fetch();
        self::assertStringContainsString(
            '1 violation(s) suppressed by per-rule suppress_namespaces/suppress_namespace_channels/suppress_paths',
            $display,
        );
        self::assertStringContainsString('src/Service/UserService.php', $display);
        self::assertStringContainsString('CCN too high', $display);
        self::assertStringContainsString('[complexity.ccn]', $display);
    }

    #[Test]
    public function itNamesTheWholeRunWhenSuppressedDetailsAreShownBesideAReportingSelector(): void
    {
        $finding = self::finding('src/Service/UserService.php', 'Other', 'Outside');
        $stats = new RuleExclusionStats(namespaceExclusionsByRule: ['complexity.ccn' => 1], excludedFindings: [$finding]);
        $output = new BufferedOutput();
        $this->filterAndReport(
            $this->createOrchestrator(),
            $this->createAnalysisResult(stats: $stats),
            $this->createInput(['--show-suppressed' => true, '--namespace' => 'subtree:Shop']),
            self::diagnosticConsole($output),
            $this->createScopeResolution(),
        );

        $display = $output->fetch();
        self::assertStringContainsString('across the whole run (reporting selectors are not applied)', $display);
        self::assertStringContainsString('[complexity.ccn]', $display);
        self::assertStringContainsString('src/Service/UserService.php', $display);
    }

    #[Test]
    public function itDoesNotPrintExcludedFindingDetailsWithoutShowSuppressed(): void
    {
        $path = RelativePath::fromString('src/Service/UserService.php');
        $symbol = SymbolPath::forClass('App\\Tests', 'UserServiceTest');
        $finding = new Finding(
            location: new Location($path, 42),
            subject: MetricSubject::declaration(DeclarationPath::of($symbol, $path, DeclarationOrdinal::fromRank(0))),
            symbolPath: $symbol,
            ruleName: 'complexity.ccn',
            code: 'complexity.ccn',
            message: 'CCN too high',
            severity: Severity::Warning,
        );

        $stats = new RuleExclusionStats(
            namespaceExclusionsByRule: ['complexity.ccn' => 1],
            excludedFindings: [$finding],
        );
        $orchestrator = $this->createOrchestrator();

        $output = new BufferedOutput();
        $this->filterAndReport(
            $orchestrator,
            $this->createAnalysisResult(stats: $stats),
            $this->createInput(),
            self::diagnosticConsole($output),
            $this->createScopeResolution(),
        );

        self::assertStringNotContainsString('CCN too high', $output->fetch());
    }

    /**
     * Stale entries produce Warning findings without changing sibling acceptance.
     *
     * The premise relies on ADR 0017's per-identity key — the
     * stale entry shares its symbol with an entry that still fires — because
     * that is the case whose behaviour changed. A stale entry on some other
     * symbol was already stale under v5 and proves nothing about the change.
     */
    #[Test]
    public function itReportsAStaleEntryWithoutFailingTheRunOrDisablingItsNeighbour(): void
    {
        $stillFiring = self::finding('src/Service/UserService.php', 'App\\Service', 'UserService');
        $baselinePath = $this->writeBaseline([
            $stillFiring->subject->toCanonical() => [
                ['channel' => $stillFiring->channel()->code, 'magnitudes' => [25]],
                ['channel' => 'code-smell.goto', 'count' => 2],
            ],
        ]);

        $output = new BufferedOutput();
        $result = $this->filterAndReport(
            $this->createOrchestrator(),
            $this->createAnalysisResult([$stillFiring]),
            $this->createInput(['--baseline' => $baselinePath]),
            self::diagnosticConsole($output),
            $this->createScopeResolution(),
        );

        $display = $output->fetch();

        self::assertNotContains($stillFiring, $result->findings, 'The surviving entry must still suppress its finding.');
        self::assertSame([$stillFiring], $result->removedBy(\Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterStage::Baseline));
        self::assertCount(1, $result->findings);
        $audit = $result->findings[0];
        $stale = $result->staleEntries[0];
        self::assertSame('baseline.unused-entry', $audit->channel()->code);
        self::assertSame(Severity::Warning, $audit->severity);
        self::assertSame(SymbolLevel::Project, $audit->level());
        self::assertSame(\Qualimetrix\Analysis\Finding\Contract\OccurrenceKey::semantic('baseline-unused-entry', ['cause' => 'stale', 'selector' => $stale->selector()->value])->value, $audit->occurrenceKey?->value);
        self::assertStringContainsString($stale->identity->describe(), $audit->message);
        self::assertStringContainsString($stale->selector()->value, $audit->message);
        self::assertStringContainsString('complete comparable measured set', $audit->message);
        self::assertNotContains($audit, $result->measuredFindings);
        self::assertStringNotContainsString('code-smell.goto', $display);
        self::assertStringNotContainsString('Error:', $display);
        self::assertStringNotContainsString('baseline:cleanup', $display);
    }

    /** Declaration movement does not establish repair; the audit reports only the measured absence. */
    #[Test]
    public function itNamesAMovedDeclarationAmongTheCausesOfStaleness(): void
    {
        $stillFiring = self::finding('src/Service/UserService.php', 'App\\Service', 'UserService');
        $baselinePath = $this->writeBaseline([
            $stillFiring->subject->toCanonical() => [
                ['channel' => $stillFiring->channel()->code, 'magnitudes' => [25]],
                ['channel' => 'code-smell.goto', 'count' => 2],
            ],
        ]);

        $output = new BufferedOutput();
        $result = $this->filterAndReport(
            $this->createOrchestrator(),
            $this->createAnalysisResult([$stillFiring]),
            $this->createInput(['--baseline' => $baselinePath]),
            self::diagnosticConsole($output),
            $this->createScopeResolution(),
        );

        $display = $output->fetch();

        self::assertStringContainsString('does not cover 1 recorded baseline paths', $display);
        self::assertStringNotContainsString('src', $display);
        self::assertStringNotContainsString('code-smell.goto', $display);
        self::assertCount(1, $result->findings);
        $audit = $result->findings[0];
        self::assertSame('baseline.unused-entry', $audit->channel()->code);
        self::assertStringContainsString('its identity did not appear in the complete comparable measured set', $audit->message);
        self::assertStringContainsString($result->staleEntries[0]->selector()->value, $audit->message);
        self::assertStringNotContainsString('was repaired', $audit->message);
        self::assertStringNotContainsString('was moved', $audit->message);
    }

    /**
     * --show-resolved counts only identities proven absent from the complete
     * comparable measured set.
     */
    #[Test]
    public function itPrintsResolvedEntriesOnARunThatStaysGreen(): void
    {
        $stillFiring = self::finding('src/Service/UserService.php', 'App\\Service', 'UserService');
        $baselinePath = $this->writeBaseline([
            $stillFiring->subject->toCanonical() => [
                ['channel' => $stillFiring->channel()->code, 'magnitudes' => [25]],
                ['channel' => 'code-smell.goto', 'count' => 2],
            ],
        ]);

        $output = new BufferedOutput();
        $this->filterAndReport(
            $this->createOrchestrator(),
            $this->createAnalysisResult([$stillFiring]),
            $this->createInput(['--baseline' => $baselinePath, '--show-resolved' => true]),
            self::diagnosticConsole($output),
            $this->createScopeResolution(),
        );

        self::assertStringContainsString('1 baseline entries have been resolved!', $output->fetch());
    }

    private static function finding(string $file, string $namespace, string $class): Finding
    {
        $path = RelativePath::fromString($file);
        $symbol = SymbolPath::forClass($namespace, $class);

        return new Finding(
            location: new Location($path, 10),
            subject: MetricSubject::declaration(DeclarationPath::of($symbol, $path, DeclarationOrdinal::fromRank(1))),
            symbolPath: $symbol,
            ruleName: 'complexity.ccn',
            code: 'complexity.ccn',
            message: 'CCN too high',
            severity: Severity::Error,
            metricValue: 25,
        );
    }

    /**
     * @param array<string, list<array<string, mixed>>> $entries
     */
    private function writeBaseline(array $entries): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'qmx_orch_baseline_') . '.json';
        $this->tempFiles[] = $path;

        file_put_contents($path, json_encode([
            'version' => 14,
            'generated' => '2026-08-05T12:00:00+03:00',
            'scope' => ['src'],
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
                baselineDocument: \is_string($baselinePath) && $baselinePath !== '' ? (new \Qualimetrix\Analysis\Policy\Baseline\BaselineDocumentReader())->preflight($baselinePath) : null,
            ),
            new RunConfiguration([], $scopeResolution->projectRoot, GeneratedFilePolicy::Exclude, $measurement, [], AutoloadDevPolicy::Exclude),
        );
    }

    private function createOrchestrator(?\Qualimetrix\Infrastructure\Composer\ComposerManifestReader $reader = null, ?\Qualimetrix\Infrastructure\Composer\ComposerAutoloadMap $anchor = null): FindingFilterOrchestrator
    {
        $reader ??= new \Qualimetrix\Infrastructure\Composer\ComposerManifestReader();
        $anchor ??= new \Qualimetrix\Infrastructure\Composer\ComposerAutoloadMap($reader);
        $declarations = StubChannelDeclarationRegistry::withDefaults();
        $declarations->declare('code-smell.goto', ChannelDeclaration::occurrence(SymbolLevel::Class_));

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
            fileScope: \Qualimetrix\Infrastructure\DependencyInjection\Configurator\DeclaredChannelFileScope::create(),
        );

        return new FindingFilterOrchestrator(
            $pipeline,
            new ErrorStream(),
            self::silentSuppressionAudit(),
            $reader,
            new \Qualimetrix\Infrastructure\Console\ObservedProjectScopeReasons($reader, $anchor),
            new \Qualimetrix\Analysis\Policy\Baseline\BaselineDocumentReader(),
            new \Qualimetrix\Infrastructure\Console\BaselineProjectionCoverage($reader, new ProjectTree(new EntryInspector()), StubRuleCoverage::everyRuleRan()),
        );
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
            new InputOption('namespace', mode: InputOption::VALUE_REQUIRED),
            new InputOption('class', mode: InputOption::VALUE_REQUIRED),
        ]);

        return new ArrayInput($options, $definition);
    }

    /**
     * @param list<Finding> $findings
     */
    private function createAnalysisResult(array $findings = [], RuleExclusionStats $stats = new RuleExclusionStats(), ?\Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement $projectScope = null): AnalysisResult
    {
        $repository = self::createStub(MetricRepositoryInterface::class);

        return AnalysisResult::fromRun(
            measured: new MeasuredRunResult(
                repository: $repository,
                coverage: new AnalysisCoverage([RelativePath::fromString('src/Service/UserService.php')], [], []),
                namespaceTree: null,
                projectScope: $projectScope ?? new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement(new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse(AbsolutePath::fromString($this->tempRoot), true, [], [], [], true, []), [AbsolutePath::fromString($this->tempRoot)], \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeState::Covered, [], new \Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement()),
                duration: 0.1,
                subjectCoverage: \Qualimetrix\Analysis\Finding\Contract\ProjectScope\SubjectCoverageFacts::fromMeasured(new \Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement(), [RelativePath::fromString('src/Service/UserService.php')], []),
            ),
            directives: new DirectiveObservations(
                suppressions: [],
                thresholdOverrides: [],
            ),
            ruleExecution: new RuleExecutionResult($findings, $findings, $stats, LevelActivity::empty()),
            latePublished: [],
            populationPublication: self::populationPublication(),
        );
    }

    private function createScopeResolution(): GitScopeResolution
    {
        return new GitScopeResolution(
            paths: [AbsolutePath::fromString($this->tempRoot . '/src/Service/UserService.php')],
            gitClient: null,
            reportScope: null,
            projectRoot: AbsolutePath::fromString($this->tempRoot),
        );
    }

    private static function populationPublication(): \Qualimetrix\Analysis\Finding\Contract\ChannelPublication
    {
        $decisions = [];
        foreach ([\Qualimetrix\Analysis\Policy\Baseline\EntryBinding\UnusedEntryRule::class, \Qualimetrix\Analysis\Finding\SuppressionBinding\UnboundSuppressionRule::class] as $rule) {
            foreach ($rule::channelDeclarations() as $name => $declaration) {
                foreach ($declaration->levels as $level) {
                    $decisions[] = new \Qualimetrix\Analysis\Finding\Contract\EnablementDecision(
                        new \Qualimetrix\Analysis\Finding\Contract\Selection\SelectionCellAddress($rule::NAME, new \Qualimetrix\Analysis\Finding\Contract\FindingChannel($name), $level, \Qualimetrix\Analysis\Finding\Contract\ChannelSelectionRole::Selectable),
                        new \Qualimetrix\Analysis\Finding\Contract\Selection\AuthoredCellDecision(\Qualimetrix\Analysis\Finding\Contract\Selection\CellSwitch::On, \Qualimetrix\Analysis\Finding\Contract\Selection\CellAdmission::Direct),
                    );
                }
            }
        }
        return new \Qualimetrix\Analysis\Finding\Contract\ChannelPublication(new \Qualimetrix\Analysis\Finding\Contract\RuleEnablement($decisions, null));
    }
}
