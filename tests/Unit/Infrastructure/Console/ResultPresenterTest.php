<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Console;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Pipeline\AnalysisResult;
use Qualimetrix\Analysis\Repository\InMemoryMetricRepository;
use Qualimetrix\Baseline\BaselineGenerator;
use Qualimetrix\Baseline\BaselineWriter;
use Qualimetrix\Baseline\ViolationHasher;
use Qualimetrix\Configuration\AnalysisConfiguration;
use Qualimetrix\Configuration\ConfigurationHolder;
use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Core\Profiler\ProfilerHolder;
use Qualimetrix\Core\Profiler\ProfilerInterface;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Infrastructure\Console\ProfilePresenter;
use Qualimetrix\Infrastructure\Console\ResultPresenter;
use Qualimetrix\Reporting\Debt\DebtCalculator;
use Qualimetrix\Reporting\Debt\RemediationTimeRegistry;
use Qualimetrix\Reporting\Formatter\FormatterInterface;
use Qualimetrix\Reporting\Formatter\FormatterRegistryInterface;
use Qualimetrix\Reporting\GroupBy;
use Qualimetrix\Reporting\Health\MetricHintProvider;
use Qualimetrix\Reporting\Health\SummaryEnricher;
use Qualimetrix\Reporting\Impact\ClassRankResolver;
use Qualimetrix\Reporting\Impact\ImpactCalculator;
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
                new ImpactCalculator(new ClassRankResolver(), new RemediationTimeRegistry()),
            ),
            profilePresenter: new ProfilePresenter(new ProfilerHolder()),
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
                new ImpactCalculator(new ClassRankResolver(), new RemediationTimeRegistry()),
            ),
            profilePresenter: new ProfilePresenter(new ProfilerHolder()),
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
    public function presentResultsReturnsExitCode0ForWarningsWithoutFailOn(): void
    {
        // Default fail-on is error, so warnings alone don't cause non-zero exit
        $exitCode = $this->presentWithViolationsAndFailOn(
            [self::createViolation(Severity::Warning)],
            null,
        );

        self::assertSame(0, $exitCode);
    }

    #[Test]
    public function presentResultsReturnsExitCode1ForWarningsWithFailOnWarning(): void
    {
        $exitCode = $this->presentWithViolationsAndFailOn(
            [self::createViolation(Severity::Warning)],
            Severity::Warning,
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
                new ImpactCalculator(new ClassRankResolver(), new RemediationTimeRegistry()),
            ),
            profilePresenter: new ProfilePresenter(new ProfilerHolder()),
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
                new ImpactCalculator(new ClassRankResolver(), new RemediationTimeRegistry()),
            ),
            profilePresenter: new ProfilePresenter(new ProfilerHolder()),
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
