<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Pipeline\AnalysisCoverage;
use Qualimetrix\Analysis\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Analysis\Pipeline\AnalysisResult;
use Qualimetrix\Analysis\RuleExecution\RuleExclusionStats;
use Qualimetrix\Analysis\RuleExecution\RuleExecutorInterface;
use Qualimetrix\Baseline\BaselineEntryParser;
use Qualimetrix\Baseline\BaselineLoader;
use Qualimetrix\Baseline\Suppression\SuppressionFilter;
use Qualimetrix\Configuration\AnalysisConfiguration;
use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Infrastructure\Console\MeasuredViolationSet;
use Qualimetrix\Infrastructure\Console\ViolationFilterOrchestrator;
use Qualimetrix\Infrastructure\Console\ViolationFilterPipeline;
use Qualimetrix\Infrastructure\Git\GitScopeResolution;
use Qualimetrix\Tests\Support\Violation\StubChannelDeclarationRegistry;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Verifies that per-rule `exclude_namespaces` / `exclude_paths` suppression
 * (RuleExecutor::getRuleExclusionStats()) is surfaced by the orchestrator's
 * `-v` and `--show-suppressed` output, mirroring the existing global-filter
 * reporting (path/namespace exclusion counters).
 */
#[CoversClass(ViolationFilterOrchestrator::class)]
final class ViolationFilterOrchestratorTest extends TestCase
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

    #[Test]
    public function itPrintsNothingAboutRuleExclusionsWhenStatsAreEmptyAndNotVerbose(): void
    {
        $orchestrator = $this->createOrchestrator(new RuleExclusionStats());

        $output = new BufferedOutput();
        $orchestrator->filterAndReport(
            $this->createAnalysisResult(),
            $this->createInput(),
            self::diagnosticConsole($output),
            $this->createScopeResolution(),
        );

        self::assertStringNotContainsString('exclude_namespaces', $output->fetch());
    }

    #[Test]
    public function itPrintsNamespaceExclusionCountWhenVerbose(): void
    {
        $stats = new RuleExclusionStats(
            namespaceExclusionsByRule: ['complexity.cyclomatic' => 2],
        );
        $orchestrator = $this->createOrchestrator($stats);

        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $orchestrator->filterAndReport(
            $this->createAnalysisResult(),
            $this->createInput(),
            self::diagnosticConsole($output),
            $this->createScopeResolution(),
        );

        $display = $output->fetch();
        self::assertStringContainsString('2 violation(s) suppressed by per-rule exclude_namespaces', $display);
        self::assertStringContainsString('complexity.cyclomatic: 2', $display);
    }

    #[Test]
    public function itPrintsPathExclusionCountWhenVerbose(): void
    {
        $stats = new RuleExclusionStats(
            pathExclusionsByRule: ['coupling.cbo' => 3],
        );
        $orchestrator = $this->createOrchestrator($stats);

        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $orchestrator->filterAndReport(
            $this->createAnalysisResult(),
            $this->createInput(),
            self::diagnosticConsole($output),
            $this->createScopeResolution(),
        );

        $display = $output->fetch();
        self::assertStringContainsString('3 violation(s) suppressed by per-rule exclude_paths', $display);
        self::assertStringContainsString('coupling.cbo: 3', $display);
    }

    #[Test]
    public function itDoesNotPrintRuleExclusionCountsWithoutVerboseFlag(): void
    {
        $stats = new RuleExclusionStats(namespaceExclusionsByRule: ['rule1' => 1]);
        $orchestrator = $this->createOrchestrator($stats);

        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);
        $orchestrator->filterAndReport(
            $this->createAnalysisResult(),
            $this->createInput(),
            self::diagnosticConsole($output),
            $this->createScopeResolution(),
        );

        self::assertStringNotContainsString('exclude_namespaces', $output->fetch());
    }

    #[Test]
    public function itPrintsExcludedViolationDetailsWithShowSuppressed(): void
    {
        $violation = new Violation(
            location: new Location(RelativePath::fromString('src/Service/UserService.php'), 42),
            symbolPath: SymbolPath::forClass('App\\Tests', 'UserServiceTest'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.method',
            message: 'CCN too high',
            severity: Severity::Warning,
        );

        $stats = new RuleExclusionStats(
            namespaceExclusionsByRule: ['complexity.cyclomatic' => 1],
            excludedViolations: [$violation],
        );
        $orchestrator = $this->createOrchestrator($stats);

        $output = new BufferedOutput();
        $orchestrator->filterAndReport(
            $this->createAnalysisResult(),
            $this->createInput(['--show-suppressed' => true]),
            self::diagnosticConsole($output),
            $this->createScopeResolution(),
        );

        $display = $output->fetch();
        self::assertStringContainsString('1 violation(s) suppressed by per-rule exclude_namespaces/exclude_paths', $display);
        self::assertStringContainsString('src/Service/UserService.php', $display);
        self::assertStringContainsString('CCN too high', $display);
        self::assertStringContainsString('[complexity.cyclomatic]', $display);
    }

    #[Test]
    public function itDoesNotPrintExcludedViolationDetailsWithoutShowSuppressed(): void
    {
        $violation = new Violation(
            location: new Location(RelativePath::fromString('src/Service/UserService.php'), 42),
            symbolPath: SymbolPath::forClass('App\\Tests', 'UserServiceTest'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.method',
            message: 'CCN too high',
            severity: Severity::Warning,
        );

        $stats = new RuleExclusionStats(
            namespaceExclusionsByRule: ['complexity.cyclomatic' => 1],
            excludedViolations: [$violation],
        );
        $orchestrator = $this->createOrchestrator($stats);

        $output = new BufferedOutput();
        $orchestrator->filterAndReport(
            $this->createAnalysisResult(),
            $this->createInput(),
            self::diagnosticConsole($output),
            $this->createScopeResolution(),
        );

        self::assertStringNotContainsString('CCN too high', $output->fetch());
    }

    /**
     * ADR 0017 makes stale entries diagnostic-only: a stale entry
     * neither fails the run nor takes its neighbours down with it.
     *
     * The premise relies on ADR 0017's per-identity key — the
     * stale entry shares its symbol with an entry that still fires — because
     * that is the case whose behaviour changed. A stale entry on some other
     * symbol was already stale under v5 and proves nothing about the change.
     */
    #[Test]
    public function itReportsAStaleEntryWithoutFailingTheRunOrDisablingItsNeighbour(): void
    {
        $stillFiring = self::violation('src/Service/UserService.php', 'App\\Service', 'UserService');
        $baselinePath = $this->writeBaseline([
            $stillFiring->symbolPath->toCanonical() => [
                ['channel' => $stillFiring->channel()->toKey(), 'magnitudes' => [25], 'count' => 1],
                ['channel' => 'code-smell.goto#code-smell.goto', 'count' => 2],
            ],
        ]);

        $output = new BufferedOutput();
        $result = $this->createOrchestrator(new RuleExclusionStats())->filterAndReport(
            $this->createAnalysisResult([$stillFiring]),
            $this->createInput(['--baseline' => $baselinePath]),
            self::diagnosticConsole($output),
            $this->createScopeResolution(),
        );

        $display = $output->fetch();

        self::assertSame([], $result->violations, 'The surviving entry must still suppress its finding.');
        self::assertStringContainsString('1 baseline entries did not appear in this run', $display);
        self::assertStringContainsString('code-smell.goto', $display);
        self::assertStringNotContainsString('Error:', $display);
        // The advice that cannot work must not be given: `baseline:cleanup`
        // selects on a vanished `file:` path and cannot touch this entry.
        self::assertStringNotContainsString('baseline:cleanup', $display);
    }

    /**
     * `--show-resolved` reads the same predicate as staleness, so while a
     * stale entry aborted the run it could only ever print on a run with
     * nothing to print.
     */
    #[Test]
    public function itPrintsResolvedEntriesOnARunThatStaysGreen(): void
    {
        $stillFiring = self::violation('src/Service/UserService.php', 'App\\Service', 'UserService');
        $baselinePath = $this->writeBaseline([
            $stillFiring->symbolPath->toCanonical() => [
                ['channel' => $stillFiring->channel()->toKey(), 'magnitudes' => [25], 'count' => 1],
                ['channel' => 'code-smell.goto#code-smell.goto', 'count' => 2],
            ],
        ]);

        $output = new BufferedOutput();
        $this->createOrchestrator(new RuleExclusionStats())->filterAndReport(
            $this->createAnalysisResult([$stillFiring]),
            $this->createInput(['--baseline' => $baselinePath, '--show-resolved' => true]),
            self::diagnosticConsole($output),
            $this->createScopeResolution(),
        );

        self::assertStringContainsString('1 baseline entries have been resolved!', $output->fetch());
    }

    private static function violation(string $file, string $namespace, string $class): Violation
    {
        return new Violation(
            location: new Location(RelativePath::fromString($file), 10),
            symbolPath: SymbolPath::forClass($namespace, $class),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.method',
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
            'version' => 10,
            'generated' => '2026-08-05T12:00:00+03:00',
            'scope' => ['src'],
            'entries' => $entries,
        ], \JSON_THROW_ON_ERROR));

        return $path;
    }

    private function createOrchestrator(RuleExclusionStats $stats): ViolationFilterOrchestrator
    {
        $configProvider = self::createStub(ConfigurationProviderInterface::class);
        $configProvider->method('getConfiguration')->willReturn(new AnalysisConfiguration());

        $declarations = StubChannelDeclarationRegistry::withDefaults();

        $pipeline = new ViolationFilterPipeline(
            new BaselineLoader(new BaselineEntryParser($declarations)),
            $declarations,
            new MeasuredViolationSet(
                self::createStub(AnalysisPipelineInterface::class),
                new SuppressionFilter(),
                $configProvider,
            ),
        );

        $ruleExecutor = self::createStub(RuleExecutorInterface::class);
        $ruleExecutor->method('getRuleExclusionStats')->willReturn($stats);

        return new ViolationFilterOrchestrator($pipeline, $ruleExecutor);
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
     * @param list<Violation> $violations
     */
    private function createAnalysisResult(array $violations = []): AnalysisResult
    {
        $repository = self::createStub(MetricRepositoryInterface::class);

        return new AnalysisResult(
            violations: $violations,
            duration: 0.1,
            metrics: $repository,
            coverage: new AnalysisCoverage([RelativePath::fromString('Fixture.php')], [], []),
        );
    }

    private function createScopeResolution(): GitScopeResolution
    {
        return new GitScopeResolution(
            paths: [],
            fileDiscovery: self::createStub(FileDiscoveryInterface::class),
            gitClient: null,
            reportScope: null,
            projectRoot: AbsolutePath::fromString(sys_get_temp_dir()),
        );
    }
}
