<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\RuleExclusionStats;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryParser;
use Qualimetrix\Analysis\Policy\Baseline\BaselineLoader;
use Qualimetrix\Analysis\Policy\Inline\Suppression\SuppressionFilter;
use Qualimetrix\Analysis\Run\Contract\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisCoverage;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\Console\ViolationFilterOrchestrator;
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
 * Verifies that per-rule `exclude_namespaces`, `exclude_namespace_channels`,
 * and `exclude_paths` suppression
 * (RuleExecution::exclusionStats()) is surfaced by the orchestrator's
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
        $this->filterAndReport(
            $orchestrator,
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
        $this->filterAndReport(
            $orchestrator,
            $this->createAnalysisResult(),
            $this->createInput(),
            self::diagnosticConsole($output),
            $this->createScopeResolution(),
        );

        $display = $output->fetch();
        self::assertStringContainsString(
            '2 violation(s) suppressed by per-rule exclude_namespaces/exclude_namespace_channels',
            $display,
        );
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
        $this->filterAndReport(
            $orchestrator,
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
        $this->filterAndReport(
            $orchestrator,
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
        $path = RelativePath::fromString('src/Service/UserService.php');
        $symbol = SymbolPath::forClass('App\\Tests', 'UserServiceTest');
        $violation = new Violation(
            location: new Location($path, 42),
            subject: MetricSubject::declaration(new DeclarationPath($symbol, $path, 42)),
            symbolPath: $symbol,
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.callable',
            message: 'CCN too high',
            severity: Severity::Warning,
        );

        $stats = new RuleExclusionStats(
            namespaceExclusionsByRule: ['complexity.cyclomatic' => 1],
            excludedViolations: [$violation],
        );
        $orchestrator = $this->createOrchestrator($stats);

        $output = new BufferedOutput();
        $this->filterAndReport(
            $orchestrator,
            $this->createAnalysisResult(),
            $this->createInput(['--show-suppressed' => true]),
            self::diagnosticConsole($output),
            $this->createScopeResolution(),
        );

        $display = $output->fetch();
        self::assertStringContainsString(
            '1 violation(s) suppressed by per-rule exclude_namespaces/exclude_namespace_channels/exclude_paths',
            $display,
        );
        self::assertStringContainsString('src/Service/UserService.php', $display);
        self::assertStringContainsString('CCN too high', $display);
        self::assertStringContainsString('[complexity.cyclomatic]', $display);
    }

    #[Test]
    public function itDoesNotPrintExcludedViolationDetailsWithoutShowSuppressed(): void
    {
        $path = RelativePath::fromString('src/Service/UserService.php');
        $symbol = SymbolPath::forClass('App\\Tests', 'UserServiceTest');
        $violation = new Violation(
            location: new Location($path, 42),
            subject: MetricSubject::declaration(new DeclarationPath($symbol, $path, 42)),
            symbolPath: $symbol,
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.callable',
            message: 'CCN too high',
            severity: Severity::Warning,
        );

        $stats = new RuleExclusionStats(
            namespaceExclusionsByRule: ['complexity.cyclomatic' => 1],
            excludedViolations: [$violation],
        );
        $orchestrator = $this->createOrchestrator($stats);

        $output = new BufferedOutput();
        $this->filterAndReport(
            $orchestrator,
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
            $stillFiring->subject->toCanonical() => [
                ['channel' => $stillFiring->channel()->toKey(), 'magnitudes' => [25], 'count' => 1],
                ['channel' => 'code-smell.goto#code-smell.goto', 'count' => 2],
            ],
        ]);

        $output = new BufferedOutput();
        $result = $this->filterAndReport(
            $this->createOrchestrator(new RuleExclusionStats()),
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
            $stillFiring->subject->toCanonical() => [
                ['channel' => $stillFiring->channel()->toKey(), 'magnitudes' => [25], 'count' => 1],
                ['channel' => 'code-smell.goto#code-smell.goto', 'count' => 2],
            ],
        ]);

        $output = new BufferedOutput();
        $this->filterAndReport(
            $this->createOrchestrator(new RuleExclusionStats()),
            $this->createAnalysisResult([$stillFiring]),
            $this->createInput(['--baseline' => $baselinePath, '--show-resolved' => true]),
            self::diagnosticConsole($output),
            $this->createScopeResolution(),
        );

        self::assertStringContainsString('1 baseline entries have been resolved!', $output->fetch());
    }

    private static function violation(string $file, string $namespace, string $class): Violation
    {
        $path = RelativePath::fromString($file);
        $symbol = SymbolPath::forClass($namespace, $class);

        return new Violation(
            location: new Location($path, 10),
            subject: MetricSubject::declaration(new DeclarationPath($symbol, $path, 10)),
            symbolPath: $symbol,
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.callable',
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
            'version' => 11,
            'generated' => '2026-08-05T12:00:00+03:00',
            'scope' => ['src'],
            'entries' => $entries,
        ], \JSON_THROW_ON_ERROR));

        return $path;
    }

    private function filterAndReport(
        ViolationFilterOrchestrator $orchestrator,
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

    private function createOrchestrator(RuleExclusionStats $stats): ViolationFilterOrchestrator
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
        $ruleExecutor->method('exclusionStats')->willReturn($stats);

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
