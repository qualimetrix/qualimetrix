<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Infrastructure\Console;

use AiMessDetector\Analysis\Pipeline\AnalysisResult;
use AiMessDetector\Analysis\Repository\InMemoryMetricRepository;
use AiMessDetector\Baseline\BaselineGenerator;
use AiMessDetector\Baseline\BaselineWriter;
use AiMessDetector\Baseline\ViolationHasher;
use AiMessDetector\Configuration\AnalysisConfiguration;
use AiMessDetector\Configuration\ConfigurationHolder;
use AiMessDetector\Configuration\ConfigurationProviderInterface;
use AiMessDetector\Core\Profiler\ProfilerHolder;
use AiMessDetector\Core\Profiler\ProfilerInterface;
use AiMessDetector\Core\Symbol\SymbolPath;
use AiMessDetector\Core\Violation\Location;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\Violation;
use AiMessDetector\Infrastructure\Console\ResultPresenter;
use AiMessDetector\Reporting\Debt\DebtCalculator;
use AiMessDetector\Reporting\Debt\RemediationTimeRegistry;
use AiMessDetector\Reporting\Formatter\FormatterInterface;
use AiMessDetector\Reporting\Formatter\FormatterRegistryInterface;
use AiMessDetector\Reporting\GroupBy;
use AiMessDetector\Reporting\Health\MetricHintProvider;
use AiMessDetector\Reporting\Health\SummaryEnricher;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[CoversClass(ResultPresenter::class)]
final class ResultPresenterTest extends TestCase
{
    private ResultPresenter $presenter;

    protected function setUp(): void
    {
        $this->presenter = new ResultPresenter(
            formatterRegistry: $this->createStub(FormatterRegistryInterface::class),
            profilerHolder: new ProfilerHolder(),
            baselineGenerator: new BaselineGenerator(new ViolationHasher()),
            baselineWriter: new BaselineWriter(),
            configurationProvider: $this->createStub(ConfigurationProviderInterface::class),
            summaryEnricher: new SummaryEnricher(
                new DebtCalculator(new RemediationTimeRegistry()),
                new MetricHintProvider(),
            ),
        );
    }

    protected function tearDown(): void
    {
        ProfilerHolder::reset();
    }

    #[Test]
    public function presentResultsUsesFormatFromConfigNotCliDirectly(): void
    {
        // Set up ConfigurationHolder with format 'json'
        $configHolder = new ConfigurationHolder();
        $configHolder->setConfiguration(new AnalysisConfiguration(format: 'json'));

        // Create a mock formatter that records it was called
        $mockFormatter = $this->createStub(FormatterInterface::class);
        $mockFormatter->method('format')->willReturn('[]');
        $mockFormatter->method('getDefaultGroupBy')->willReturn(GroupBy::None);

        // FormatterRegistry should be asked for 'json' (from config), not 'text' (from CLI default)
        $registry = $this->createMock(FormatterRegistryInterface::class);
        $registry->expects(self::once())
            ->method('get')
            ->with('json')
            ->willReturn($mockFormatter);

        $presenter = new ResultPresenter(
            formatterRegistry: $registry,
            profilerHolder: new ProfilerHolder(),
            baselineGenerator: new BaselineGenerator(new ViolationHasher()),
            baselineWriter: new BaselineWriter(),
            configurationProvider: $configHolder,
            summaryEnricher: new SummaryEnricher(
                new DebtCalculator(new RemediationTimeRegistry()),
                new MetricHintProvider(),
            ),
        );

        $input = $this->createStub(InputInterface::class);
        $input->method('getOption')->willReturnCallback(
            static fn(string $name): mixed => match ($name) {
                'format' => null, // CLI did not specify format
                'group-by' => null,
                'format-opt' => [],
                default => null,
            },
        );

        $output = $this->createStub(OutputInterface::class);
        $output->method('isDecorated')->willReturn(false);

        $analysisResult = new AnalysisResult(
            violations: [],
            filesAnalyzed: 0,
            filesSkipped: 0,
            duration: 0.0,
            metrics: new InMemoryMetricRepository(),
            suppressions: [],
        );

        $presenter->presentResults([], $analysisResult, $input, $output);
    }

    #[Test]
    public function presentProfileShowsErrorWhenTmpFileWriteFails(): void
    {
        // Enable profiler
        $profiler = $this->createStub(ProfilerInterface::class);
        $profiler->method('isEnabled')->willReturn(true);
        $profiler->method('export')->willReturn('{"test":"data"}');
        ProfilerHolder::set($profiler);

        // Use a non-existent directory to trigger write failure
        $invalidPath = '/non/existent/dir/profile.json';

        $input = $this->createStub(InputInterface::class);
        $input->method('getOption')->willReturnCallback(
            static fn(string $name): mixed => match ($name) {
                'profile' => $invalidPath,
                'profile-format' => 'json',
                default => null,
            },
        );

        $output = $this->createMock(OutputInterface::class);

        // Should output an error message, not a success message
        $output->expects(self::once())
            ->method('writeln')
            ->with(
                self::stringContains('Failed to write profile data'),
                self::anything(),
            );

        $this->presenter->presentProfile($input, $output);
    }

    #[Test]
    public function presentProfileShowsSuccessOnValidWrite(): void
    {
        // Enable profiler
        $profiler = $this->createStub(ProfilerInterface::class);
        $profiler->method('isEnabled')->willReturn(true);
        $profiler->method('export')->willReturn('{"test":"data"}');
        ProfilerHolder::set($profiler);

        $tmpDir = sys_get_temp_dir();
        $profilePath = $tmpDir . '/test_profile_' . getmypid() . '.json';

        $input = $this->createStub(InputInterface::class);
        $input->method('getOption')->willReturnCallback(
            static fn(string $name): mixed => match ($name) {
                'profile' => $profilePath,
                'profile-format' => 'json',
                default => null,
            },
        );

        $output = $this->createMock(OutputInterface::class);

        $output->expects(self::once())
            ->method('writeln')
            ->with(
                self::stringContains('Profile exported to'),
                self::anything(),
            );

        $this->presenter->presentProfile($input, $output);

        // Cleanup
        if (file_exists($profilePath)) {
            unlink($profilePath);
        }
    }

    #[Test]
    public function presentResultsReturnsExitCode1ForWarningsWithoutFailOn(): void
    {
        $exitCode = $this->presentWithViolationsAndFailOn(
            [self::createViolation(Severity::Warning)],
            null,
        );

        self::assertSame(1, $exitCode);
    }

    #[Test]
    public function presentResultsReturnsExitCode2ForErrorsWithoutFailOn(): void
    {
        $exitCode = $this->presentWithViolationsAndFailOn(
            [self::createViolation(Severity::Error)],
            null,
        );

        self::assertSame(2, $exitCode);
    }

    #[Test]
    public function presentResultsReturnsExitCode0ForWarningsWhenFailOnError(): void
    {
        $exitCode = $this->presentWithViolationsAndFailOn(
            [self::createViolation(Severity::Warning)],
            Severity::Error,
        );

        self::assertSame(0, $exitCode);
    }

    #[Test]
    public function presentResultsReturnsExitCode2ForErrorsWhenFailOnError(): void
    {
        $exitCode = $this->presentWithViolationsAndFailOn(
            [self::createViolation(Severity::Error)],
            Severity::Error,
        );

        self::assertSame(2, $exitCode);
    }

    #[Test]
    public function presentResultsReturnsExitCode1ForWarningsWhenFailOnWarning(): void
    {
        $exitCode = $this->presentWithViolationsAndFailOn(
            [self::createViolation(Severity::Warning)],
            Severity::Warning,
        );

        self::assertSame(1, $exitCode);
    }

    #[Test]
    public function presentResultsReturnsExitCode0WhenNoViolations(): void
    {
        $exitCode = $this->presentWithViolationsAndFailOn([], null);

        self::assertSame(0, $exitCode);
    }

    #[Test]
    public function presentResultsReturnsExitCode0ForWarningsWhenFailOnNone(): void
    {
        $exitCode = $this->presentWithViolationsAndFailOn(
            [$this->createViolation(Severity::Warning)],
            false,
        );

        self::assertSame(0, $exitCode);
    }

    #[Test]
    public function presentResultsReturnsExitCode0ForErrorsWhenFailOnNone(): void
    {
        $exitCode = $this->presentWithViolationsAndFailOn(
            [$this->createViolation(Severity::Error)],
            false,
        );

        self::assertSame(0, $exitCode);
    }

    /**
     * @param list<Violation> $violations
     */
    private function presentWithViolationsAndFailOn(array $violations, Severity|false|null $failOn): int
    {
        $configHolder = new ConfigurationHolder();
        $configHolder->setConfiguration(new AnalysisConfiguration(failOn: $failOn));

        $mockFormatter = $this->createStub(FormatterInterface::class);
        $mockFormatter->method('format')->willReturn('');
        $mockFormatter->method('getDefaultGroupBy')->willReturn(GroupBy::None);

        $registry = $this->createStub(FormatterRegistryInterface::class);
        $registry->method('get')->willReturn($mockFormatter);

        $presenter = new ResultPresenter(
            formatterRegistry: $registry,
            profilerHolder: new ProfilerHolder(),
            baselineGenerator: new BaselineGenerator(new ViolationHasher()),
            baselineWriter: new BaselineWriter(),
            configurationProvider: $configHolder,
            summaryEnricher: new SummaryEnricher(
                new DebtCalculator(new RemediationTimeRegistry()),
                new MetricHintProvider(),
            ),
        );

        $input = $this->createStub(InputInterface::class);
        $input->method('getOption')->willReturnCallback(
            static fn(string $name): mixed => match ($name) {
                'format' => null,
                'group-by' => null,
                'format-opt' => [],
                default => null,
            },
        );

        $output = $this->createStub(OutputInterface::class);
        $output->method('isDecorated')->willReturn(false);

        $analysisResult = new AnalysisResult(
            violations: $violations,
            filesAnalyzed: 1,
            filesSkipped: 0,
            duration: 0.1,
            metrics: new InMemoryMetricRepository(),
            suppressions: [],
        );

        return $presenter->presentResults($violations, $analysisResult, $input, $output);
    }

    #[Test]
    public function presentResultsThrowsOnMutuallyExclusiveNamespaceAndClass(): void
    {
        $configHolder = new ConfigurationHolder();
        $configHolder->setConfiguration(new AnalysisConfiguration());

        $mockFormatter = $this->createStub(FormatterInterface::class);
        $mockFormatter->method('format')->willReturn('');
        $mockFormatter->method('getDefaultGroupBy')->willReturn(GroupBy::None);

        $registry = $this->createStub(FormatterRegistryInterface::class);
        $registry->method('get')->willReturn($mockFormatter);

        $presenter = new ResultPresenter(
            formatterRegistry: $registry,
            profilerHolder: new ProfilerHolder(),
            baselineGenerator: new BaselineGenerator(new ViolationHasher()),
            baselineWriter: new BaselineWriter(),
            configurationProvider: $configHolder,
            summaryEnricher: new SummaryEnricher(
                new DebtCalculator(new RemediationTimeRegistry()),
                new MetricHintProvider(),
            ),
        );

        $input = $this->createStub(InputInterface::class);
        $input->method('getOption')->willReturnCallback(
            static fn(string $name): mixed => match ($name) {
                'format' => null,
                'group-by' => null,
                'format-opt' => [],
                'namespace' => 'App\Service',
                'class' => 'App\Service\UserService',
                default => null,
            },
        );

        $output = $this->createStub(OutputInterface::class);
        $output->method('isDecorated')->willReturn(false);

        $analysisResult = new AnalysisResult(
            violations: [],
            filesAnalyzed: 0,
            filesSkipped: 0,
            duration: 0.0,
            metrics: new InMemoryMetricRepository(),
            suppressions: [],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('mutually exclusive');

        $presenter->presentResults([], $analysisResult, $input, $output);
    }

    private static function createViolation(Severity $severity): Violation
    {
        return new Violation(
            location: new Location('test.php', 1),
            symbolPath: SymbolPath::forFile('test.php'),
            ruleName: 'test.rule',
            violationCode: 'test.rule.violation',
            message: 'Test violation',
            severity: $severity,
        );
    }
}
