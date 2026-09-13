<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Functional;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationPipelineInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Evidence\Cohesion\Configuration\LcomCollectionConfigurationResolver;
use Qualimetrix\Analysis\Evidence\Cohesion\Runtime\LcomCollectionConfigurationStore;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\ComputedMetricConfiguratorInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Evidence\Coupling\Contract\Configuration\CouplingConfiguratorInterface;
use Qualimetrix\Analysis\Finding\Configuration\FindingConfigurationResolver;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleChannelRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitecturePolicyConfiguratorInterface;
use Qualimetrix\Analysis\Policy\Baseline\BaselineConflictException;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfigurationResolverInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Cache\CacheConfigurationStore;
use Qualimetrix\Infrastructure\Cache\CacheFactory;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfiguration;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfigurationResolverInterface;
use Qualimetrix\Infrastructure\Console\AnalysisRuntimeConfigurator;
use Qualimetrix\Infrastructure\Console\Command\BaselineCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineRun;
use Qualimetrix\Infrastructure\Console\ConfigurationInputAdapter;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\MeasuredFindingSet;
use Qualimetrix\Infrastructure\Console\Progress\ProgressConfigurator;
use Qualimetrix\Infrastructure\Console\Progress\SwitchableProgressReporter;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Qualimetrix\Infrastructure\Console\RuleInputValidator;
use Qualimetrix\Infrastructure\Console\RuntimeConfigurator;
use Qualimetrix\Infrastructure\Console\RuntimeLimitsController;
use Qualimetrix\Infrastructure\Console\RuntimeLoggerConfigurator;
use Qualimetrix\Infrastructure\Logging\Contract\LoggerFactoryInterface;
use Qualimetrix\Infrastructure\Logging\LoggerHolder;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfiguration;
use Qualimetrix\Infrastructure\Parallel\Contract\ParallelConfigurationResolverInterface;
use Qualimetrix\Infrastructure\Parallel\Runtime\ParallelConfigurationStore;
use Qualimetrix\Infrastructure\Profiler\ProfileSession;
use Qualimetrix\Infrastructure\Rule\ChannelUniverse;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Qualimetrix\Reporting\FindingProjection\Contract\ConfiguredFindingExclusionsResolverInterface;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Throwable;

/**
 * What a baseline command says when it fails, and what `-v` adds to it.
 *
 * Every one of these exceptions used to be turned into its message and
 * nothing else, on the reasoning that they are the user's to fix. That
 * classification is a guess, and it is wrong exactly when a trace is worth
 * most: a `RuntimeException` can be raised from anywhere in the analysis the
 * command runs, and its message names a symptom rather than a site. So the
 * default output stays a single sentence — nobody wants a trace for a typo'd
 * path — and `-v` is what it was asked for.
 *
 * {@see self::provideRefusals()} covers the command's refusal carrier and its
 * named secondary signal separately: both give code 3 on stderr, and neither
 * ever carries a trace, which is the opposite trade from the rest of this
 * file's cases.
 */
#[CoversClass(BaselineCommand::class)]
final class BaselineCommandFailureReportingTest extends TestCase
{
    /**
     * @return iterable<string, array{Throwable, string}>
     */
    public static function provideFailures(): iterable
    {
        yield 'an unreadable baseline envelope' => [
            new RuntimeException('Baseline file not found: b.json'),
            'Baseline file not found: b.json',
        ];

        yield 'a file somebody else rewrote' => [
            new BaselineConflictException('Baseline file b.json changed since it was read'),
            'changed since it was read',
        ];

        yield 'a defect in the tool itself' => [
            new LogicException('the invariant nobody expected to break'),
            'Unexpected error: the invariant nobody expected to break',
        ];
    }

    /**
     * The default: one sentence, no trace, and a failing exit code.
     */
    #[Test]
    #[DataProvider('provideFailures')]
    public function itReportsAFailureAsOneSentence(Throwable $thrown, string $expected): void
    {
        $tester = self::execute($thrown, verbose: false);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString($expected, $tester->getDisplay());
        self::assertStringNotContainsString('Stack trace:', $tester->getDisplay());
    }

    /**
     * Under `-v` the same failure carries the trace.
     */
    #[Test]
    #[DataProvider('provideFailures')]
    public function itAddsTheTraceWhenTheUserAsksForVerbosity(Throwable $thrown, string $expected): void
    {
        $tester = self::execute($thrown, verbose: true);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString($expected, $tester->getDisplay());
        self::assertStringContainsString('Stack trace:', $tester->getDisplay());
        self::assertStringContainsString(self::class, $tester->getDisplay());
    }

    /**
     * The command's refusal carrier and its named secondary signal both give
     * code 3 on stderr, and neither ever carries a trace — a refusal is the
     * user's to fix, not ours to explain with a stack.
     *
     * @return iterable<string, array{Throwable, string}>
     */
    public static function provideRefusals(): iterable
    {
        yield 'an InvalidArgumentException nothing normalized' => [
            new InvalidArgumentException('Path(s) do not exist: src'),
            'Path(s) do not exist: src',
        ];

        yield 'a configuration that will not load' => [
            ConfigurationRefusal::aboutDocument(
                ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml'),
                'qmx.yaml was not found',
            ),
            'Configuration error: qmx.yaml was not found',
        ];
    }

    #[Test]
    #[DataProvider('provideRefusals')]
    public function itAnswersARefusalOnStderrWithExitThreeAndNoTrace(Throwable $thrown, string $expected): void
    {
        $tester = self::execute($thrown, verbose: true);

        self::assertSame(3, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString($expected, $tester->getErrorOutput());
        self::assertStringNotContainsString('Stack trace:', $tester->getErrorOutput());
    }

    #[Test]
    public function itResetsSharedBaselineRuntimeBeforeConfigurationLoadingFails(): void
    {
        $runtime = self::runtimeConfigurator();
        $runtimeReflection = new ReflectionClass($runtime);
        $analysisRuntime = $runtimeReflection->getProperty('analysisRuntimeConfigurator')->getValue($runtime);
        self::assertInstanceOf(AnalysisRuntimeConfigurator::class, $analysisRuntime);
        $validator = (new ReflectionClass($analysisRuntime))->getProperty('ruleInputValidator')->getValue($analysisRuntime);
        self::assertInstanceOf(RuleInputValidator::class, $validator);
        $selector = (new ReflectionClass($validator))->getProperty('ruleSelector')->getValue($validator);
        self::assertInstanceOf(RuleSelector::class, $selector);
        $staticChannels = (new ReflectionClass($selector))->getProperty('defaultChannels')->getValue($selector);
        $selector->replaceChannels(self::createStub(RuleChannelRegistryInterface::class));

        $cacheFactory = $runtimeReflection->getProperty('cacheFactory')->getValue($runtime);
        self::assertInstanceOf(CacheFactory::class, $cacheFactory);
        $cacheStore = (new ReflectionClass($cacheFactory))->getProperty('configurationStore')->getValue($cacheFactory);
        self::assertInstanceOf(CacheConfigurationStore::class, $cacheStore);
        $cacheFactory->replaceConfiguration(new CacheConfiguration(AbsolutePath::fromString('/custom-cache'), false));
        $parallelStore = $runtimeReflection->getProperty('parallelConfigurationStore')->getValue($runtime);
        self::assertInstanceOf(ParallelConfigurationStore::class, $parallelStore);
        $parallelStore->replace(new ParallelConfiguration(3));
        $profile = $runtimeReflection->getProperty('profileSession')->getValue($runtime);
        self::assertInstanceOf(ProfileSession::class, $profile);
        $profile->enable();

        $pipeline = self::createStub(ConfigurationPipelineInterface::class);
        $pipeline->method('resolve')->willThrowException(ConfigurationRefusal::aboutDocument(
            ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml'),
            'qmx.yaml was not found',
        ));
        $baselineRun = new BaselineRun(
            $runtime,
            self::withoutConstructor(MeasuredFindingSet::class),
            self::ruleInputValidator(self::createStub(RuleRegistryInterface::class)),
            self::configurationInputAdapter($pipeline),
            self::createStub(RunConfigurationResolverInterface::class),
            self::createStub(ConfiguredFindingExclusionsResolverInterface::class),
            self::createStub(CacheConfigurationResolverInterface::class),
            self::createStub(ParallelConfigurationResolverInterface::class),
        );

        try {
            $baselineRun->measure(new ArrayInput([]), new BufferedOutput());
            self::fail('Configuration loading must fail.');
        } catch (ConfigurationRefusal) {
            self::assertSame(
                $staticChannels,
                (new ReflectionClass($selector))->getProperty('channels')->getValue($selector),
            );
            self::assertTrue($cacheStore->current()->enabled);
            self::assertNull($parallelStore->current()->workers);
            self::assertFalse($profile->isEnabled());
        }
    }

    private static function execute(Throwable $thrown, bool $verbose): CommandTester
    {
        $command = new class ($thrown) extends BaselineCommand {
            public function __construct(private readonly Throwable $thrown)
            {
                parent::__construct('baseline:throwing-stub');
            }

            protected function doExecute(InputInterface $input, OutputInterface $output): int
            {
                throw $this->thrown;
            }
        };
        $command->setRefusalPresenter(self::refusalPresenter());

        $tester = new CommandTester($command);
        $tester->execute(
            [],
            [
                'capture_stderr_separately' => true,
                ...($verbose ? ['verbosity' => OutputInterface::VERBOSITY_VERBOSE] : []),
            ],
        );

        return $tester;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private static function withoutConstructor(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    private static function runtimeConfigurator(): RuntimeConfigurator
    {
        $cacheStore = new CacheConfigurationStore();
        $parallelStore = new ParallelConfigurationStore();
        $ruleOptions = new RuleOptionsRegistry();
        $loggerFactory = self::createStub(LoggerFactoryInterface::class);
        $loggerFactory->method('create')->willReturn(new NullLogger());
        $architecture = self::createStub(ArchitecturePolicyConfiguratorInterface::class);
        $ruleRegistry = self::createStub(RuleRegistryInterface::class);
        $staticChannels = new ChannelUniverse([], [], [], new ResolvedComputedMetricDefinitions([]));
        $ruleSelector = new RuleSelector($staticChannels);
        $ruleInputValidator = new RuleInputValidator(
            $ruleRegistry,
            $ruleSelector,
            new FindingConfigurationResolver(),
            $staticChannels,
        );

        $errorStream = new ErrorStream();

        return new RuntimeConfigurator(
            new RuntimeLoggerConfigurator($loggerFactory, new LoggerHolder(), $errorStream),
            new ProgressConfigurator(new SwitchableProgressReporter(), $errorStream),
            new ProfileSession(),
            new AnalysisRuntimeConfigurator(
                $ruleOptions,
                new LcomCollectionConfigurationResolver(),
                new LcomCollectionConfigurationStore(),
                $architecture,
                self::createStub(ComputedMetricConfiguratorInterface::class),
                self::createStub(CouplingConfiguratorInterface::class),
                $ruleInputValidator,
            ),
            new CacheFactory($cacheStore),
            $parallelStore,
            new RuntimeLimitsController(),
        );
    }

    private static function ruleInputValidator(RuleRegistryInterface $rules): RuleInputValidator
    {
        $staticChannels = new ChannelUniverse([], [], [], new ResolvedComputedMetricDefinitions([]));

        return new RuleInputValidator(
            $rules,
            new RuleSelector($staticChannels),
            new FindingConfigurationResolver(),
            $staticChannels,
        );
    }

    private static function configurationInputAdapter(
        ConfigurationPipelineInterface $pipeline,
    ): ConfigurationInputAdapter {
        return new ConfigurationInputAdapter(
            $pipeline,
        );
    }

    private static function refusalPresenter(): RefusalPresenter
    {
        return new RefusalPresenter(new ErrorStream());
    }
}
