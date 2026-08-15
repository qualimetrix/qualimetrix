<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Summary\HealthSummaryBuilder;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Metadata\HealthMetricCatalog;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\DebtCalculator;
use Qualimetrix\Analysis\Evidence\Prioritization\Debt\RemediationTimeRegistry;
use Qualimetrix\Analysis\Evidence\Prioritization\Impact\ClassRankResolver;
use Qualimetrix\Analysis\Evidence\Prioritization\Impact\ImpactCalculator;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisCoverage;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailure;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailureKind;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisResult;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\Console\ExitCodeResolver;
use Qualimetrix\Infrastructure\Console\ExitPolicy;
use Qualimetrix\Infrastructure\Console\FormatterContextFactory;
use Qualimetrix\Infrastructure\Console\ProfilePresenter;
use Qualimetrix\Infrastructure\Console\ResultPresenter;
use Qualimetrix\Infrastructure\Profiler\ProfileSession;
use Qualimetrix\Reporting\Contract\OutputFormat;
use Qualimetrix\Reporting\Filter\ViolationFilter;
use Qualimetrix\Reporting\Formatter\FormatterInterface;
use Qualimetrix\Reporting\Formatter\FormatterRegistryInterface;
use Qualimetrix\Reporting\GroupBy;
use Qualimetrix\Reporting\Health\SummaryEnricher;
use Qualimetrix\Reporting\Report;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

#[CoversClass(ResultPresenter::class)]
final class ResultPresenterTest extends TestCase
{
    #[Test]
    public function itUsesTheExplicitResolvedFormatAndProjectRoot(): void
    {
        $formatter = $this->createMock(FormatterInterface::class);
        $formatter->method('getDefaultGroupBy')->willReturn(GroupBy::None);
        $formatter->expects(self::once())->method('format')->willReturn('rendered');

        $registry = $this->createMock(FormatterRegistryInterface::class);
        $registry->expects(self::once())->method('get')->with('json')->willReturn($formatter);

        $output = new BufferedOutput();
        $exit = $this->presenter($registry)->presentResults(
            [],
            $this->analysisResult(),
            $this->input(['--format' => 'text']),
            $output,
            AbsolutePath::fromString('/project'),
            outputFormat: new OutputFormat('json'),
            exitPolicy: new ExitPolicy(),
        );

        self::assertSame(0, $exit);
        self::assertStringContainsString('rendered', $output->fetch());
    }

    #[Test]
    public function itAppliesTheExplicitExitPolicyWithoutAConfigurationProvider(): void
    {
        $formatter = self::createStub(FormatterInterface::class);
        $formatter->method('getDefaultGroupBy')->willReturn(GroupBy::None);
        $formatter->method('format')->willReturn('');
        $registry = self::createStub(FormatterRegistryInterface::class);
        $registry->method('get')->willReturn($formatter);
        $violation = $this->violation(Severity::Warning);

        $exit = $this->presenter($registry)->presentResults(
            [$violation],
            $this->analysisResult([$violation]),
            $this->input(),
            new BufferedOutput(),
            AbsolutePath::fromString('/project'),
            outputFormat: new OutputFormat(),
            exitPolicy: new ExitPolicy(Severity::Warning),
        );

        self::assertSame(Severity::Warning->getExitCode(), $exit);
    }

    #[Test]
    public function itDefaultsToErrorOnlyExitBehavior(): void
    {
        $formatter = self::createStub(FormatterInterface::class);
        $formatter->method('getDefaultGroupBy')->willReturn(GroupBy::None);
        $formatter->method('format')->willReturn('');
        $registry = self::createStub(FormatterRegistryInterface::class);
        $registry->method('get')->willReturn($formatter);
        $violation = $this->violation(Severity::Warning);

        self::assertSame(0, $this->presenter($registry)->presentResults(
            [$violation],
            $this->analysisResult([$violation]),
            $this->input(),
            new BufferedOutput(),
            AbsolutePath::fromString('/project'),
            new OutputFormat(),
            new ExitPolicy(),
        ));
    }

    #[Test]
    public function itRelativizesOnlyTheProjectRootPrefixInCoverageFailureMessages(): void
    {
        $formatter = $this->createMock(FormatterInterface::class);
        $formatter->method('getDefaultGroupBy')->willReturn(GroupBy::None);
        $formatter->expects(self::once())->method('format')->willReturnCallback(
            static function (Report $report): string {
                self::assertNotNull($report->coverage);
                self::assertSame(
                    'Parse error in src/Broken.php; dependency /external/project/shared.php',
                    $report->coverage->failures[0]->message,
                );

                return '';
            },
        );
        $registry = self::createStub(FormatterRegistryInterface::class);
        $registry->method('get')->willReturn($formatter);
        $coverage = new AnalysisCoverage([], [], [new AnalysisFailure(
            RelativePath::fromString('src/Broken.php'),
            AnalysisFailureKind::Parse,
            'Parse error in /project/src/Broken.php; dependency /external/project/shared.php',
        )]);

        $this->presenter($registry)->presentResults(
            [],
            $this->analysisResult(coverage: $coverage),
            $this->input(),
            new BufferedOutput(),
            AbsolutePath::fromString('/project'),
            new OutputFormat(),
            new ExitPolicy(),
        );
    }

    private function presenter(FormatterRegistryInterface $registry): ResultPresenter
    {
        $session = new ProfileSession();
        $definitions = self::createStub(ComputedMetricDefinitionCatalogInterface::class);
        $definitions->method('all')->willReturn([]);
        $remediation = new RemediationTimeRegistry();

        return new ResultPresenter(
            $registry,
            $session,
            new SummaryEnricher(
                new DebtCalculator($remediation),
                new ImpactCalculator(new ClassRankResolver(), $remediation),
                new HealthSummaryBuilder(new HealthMetricCatalog(), $definitions),
            ),
            new ProfilePresenter($session),
            new ExitCodeResolver(),
            new ViolationFilter(),
            new FormatterContextFactory(),
        );
    }

    /** @param array<string, mixed> $options */
    private function input(array $options = []): ArrayInput
    {
        $definition = new InputDefinition([
            new InputOption('format', null, InputOption::VALUE_REQUIRED),
            new InputOption('output', null, InputOption::VALUE_REQUIRED),
            new InputOption('profile', null, InputOption::VALUE_OPTIONAL),
            new InputOption('profile-format', null, InputOption::VALUE_REQUIRED),
            new InputOption('group-by', null, InputOption::VALUE_REQUIRED),
            new InputOption('format-opt', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, '', []),
            new InputOption('detail', null, InputOption::VALUE_OPTIONAL, '', false),
            new InputOption('top', null, InputOption::VALUE_REQUIRED),
            new InputOption('all', null, InputOption::VALUE_NONE),
            new InputOption('namespace', null, InputOption::VALUE_REQUIRED),
            new InputOption('class', null, InputOption::VALUE_REQUIRED),
        ]);

        return new ArrayInput($options, $definition);
    }

    /** @param list<Violation> $violations */
    private function analysisResult(array $violations = [], ?AnalysisCoverage $coverage = null): AnalysisResult
    {
        return new AnalysisResult(
            $violations,
            0.1,
            new InMemoryMetricRepository(),
            $coverage ?? new AnalysisCoverage([], [], []),
        );
    }

    private function violation(Severity $severity): Violation
    {
        $path = RelativePath::fromString('src/Subject.php');
        $symbol = SymbolPath::forFile($path);

        return new Violation(
            location: new Location($path, 1),
            subject: MetricSubject::aggregate($symbol),
            symbolPath: $symbol,
            ruleName: 'fixture.rule',
            violationCode: 'fixture.rule',
            message: 'Fixture',
            severity: $severity,
        );
    }
}
