<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Discovery\FileDiscoveryInterface;
use Qualimetrix\Analysis\Pipeline\AnalysisResult;
use Qualimetrix\Analysis\RuleExecution\RuleExclusionStats;
use Qualimetrix\Analysis\RuleExecution\RuleExecutorInterface;
use Qualimetrix\Baseline\BaselineLoader;
use Qualimetrix\Baseline\Suppression\SuppressionFilter;
use Qualimetrix\Baseline\ViolationHasher;
use Qualimetrix\Configuration\AnalysisConfiguration;
use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Infrastructure\Console\ViolationFilterOrchestrator;
use Qualimetrix\Infrastructure\Console\ViolationFilterPipeline;
use Qualimetrix\Infrastructure\Git\GitScopeResolution;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
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
    #[Test]
    public function itPrintsNothingAboutRuleExclusionsWhenStatsAreEmptyAndNotVerbose(): void
    {
        $orchestrator = $this->createOrchestrator(new RuleExclusionStats());

        $output = new BufferedOutput();
        $orchestrator->filterAndReport(
            $this->createAnalysisResult(),
            $this->createInput(),
            $output,
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
            $output,
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
            $output,
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
            $output,
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
            $output,
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
            $output,
            $this->createScopeResolution(),
        );

        self::assertStringNotContainsString('CCN too high', $output->fetch());
    }

    private function createOrchestrator(RuleExclusionStats $stats): ViolationFilterOrchestrator
    {
        $configProvider = self::createStub(ConfigurationProviderInterface::class);
        $configProvider->method('getConfiguration')->willReturn(new AnalysisConfiguration());

        $pipeline = new ViolationFilterPipeline(
            new BaselineLoader(),
            new ViolationHasher(),
            new SuppressionFilter(),
            $configProvider,
        );

        $ruleExecutor = self::createStub(RuleExecutorInterface::class);
        $ruleExecutor->method('getRuleExclusionStats')->willReturn($stats);

        return new ViolationFilterOrchestrator($pipeline, $ruleExecutor);
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
            new InputOption('baseline-ignore-stale', mode: InputOption::VALUE_NONE),
            new InputOption('no-suppression', mode: InputOption::VALUE_NONE),
            new InputOption('show-resolved', mode: InputOption::VALUE_NONE),
            new InputOption('show-suppressed', mode: InputOption::VALUE_NONE),
        ]);

        return new ArrayInput($options, $definition);
    }

    private function createAnalysisResult(): AnalysisResult
    {
        $repository = self::createStub(MetricRepositoryInterface::class);

        return new AnalysisResult(
            violations: [],
            filesAnalyzed: 1,
            filesSkipped: 0,
            duration: 0.1,
            metrics: $repository,
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
