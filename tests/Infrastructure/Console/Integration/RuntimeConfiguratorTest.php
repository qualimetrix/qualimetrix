<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Integration;

use InvalidArgumentException;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\Cohesion\Configuration\LcomCollectionConfigurationResolver;
use Qualimetrix\Analysis\Evidence\Cohesion\LcomOptions;
use Qualimetrix\Analysis\Evidence\Cohesion\LcomRule;
use Qualimetrix\Analysis\Evidence\Cohesion\Runtime\LcomCollectionConfigurationStore;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\ComputedMetricConfiguratorInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Evidence\Coupling\Contract\Configuration\CouplingConfiguratorInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\Selection\RuleEnablementResolver;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitecturePolicyConfiguratorInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ResolvedArchitecturePolicyInterface;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Cache\CacheConfigurationResolver;
use Qualimetrix\Infrastructure\Cache\CacheConfigurationStore;
use Qualimetrix\Infrastructure\Cache\CacheFactory;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfiguration;
use Qualimetrix\Infrastructure\Composer\ComposerAutoloadMap;
use Qualimetrix\Infrastructure\Console\AnalysisRuntimeConfigurator;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\Progress\ProgressConfigurator;
use Qualimetrix\Infrastructure\Console\Progress\SwitchableProgressReporter;
use Qualimetrix\Infrastructure\Console\Refusal\EnvironmentRefusal;
use Qualimetrix\Infrastructure\Console\RuleInputValidator;
use Qualimetrix\Infrastructure\Console\RunTarget\RunTargets;
use Qualimetrix\Infrastructure\Console\RuntimeConfigurator;
use Qualimetrix\Infrastructure\Console\RuntimeLimits;
use Qualimetrix\Infrastructure\Console\RuntimeLimitsController;
use Qualimetrix\Infrastructure\Console\RuntimeLoggerConfigurator;
use Qualimetrix\Infrastructure\Logging\Contract\LoggerFactoryInterface;
use Qualimetrix\Infrastructure\Logging\LoggerHolder;
use Qualimetrix\Infrastructure\Parallel\Configuration\ParallelConfigurationResolver;
use Qualimetrix\Infrastructure\Parallel\Runtime\ParallelConfigurationStore;
use Qualimetrix\Infrastructure\Profiler\ProfileSession;
use Qualimetrix\Infrastructure\Rule\ChannelUniverse;
use Qualimetrix\Infrastructure\Rule\Contract\RuleChannelSnapshotFactoryInterface;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Qualimetrix\Subprocess\ChildProcess;
use Qualimetrix\Tests\Analysis\Configuration\Support\LayeredDocument;
use Qualimetrix\Tests\Infrastructure\Console\Support\SplitStreamConsoleOutput;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

require_once \dirname(__DIR__, 4) . '/scripts/subprocess/ChildProcess.php';

#[CoversClass(RuntimeConfigurator::class)]
#[CoversClass(RuntimeLimitsController::class)]
final class RuntimeConfiguratorTest extends TestCase
{
    private CacheConfigurationStore $cacheStore;
    private CacheFactory $cacheFactory;
    private ParallelConfigurationStore $parallelStore;
    private RuleOptionsRegistry $rules;
    private LcomCollectionConfigurationStore $lcomStore;
    private ProfileSession $profile;
    private SwitchableProgressReporter $progress;
    private RuntimeConfigurator $configurator;
    private RuleChannelSnapshotFactoryInterface $snapshotFactory;
    private ResolvedComputedMetricDefinitions $snapshotDefinitions;

    /**
     * A real directory, not `/project`: resolving an enabled cache now refuses
     * a root it cannot write into, and these cases are about the stores.
     */
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/qmx-runtime-configurator-' . bin2hex(random_bytes(6));
        mkdir($this->projectRoot, 0o755, true);
        $this->cacheStore = new CacheConfigurationStore();
        $this->cacheFactory = new CacheFactory($this->cacheStore);
        $this->parallelStore = new ParallelConfigurationStore();
        $this->rules = new RuleOptionsRegistry();
        $this->lcomStore = new LcomCollectionConfigurationStore();
        $this->profile = new ProfileSession();
        $this->progress = new SwitchableProgressReporter();

        $this->snapshotDefinitions = new ResolvedComputedMetricDefinitions([]);
        $this->configurator = $this->createConfigurator();
    }

    protected function tearDown(): void
    {
        exec(\sprintf('rm -rf %s', escapeshellarg($this->projectRoot)));
    }

    private function createConfigurator(
        ?string $failingOwner = null,
        ?ComputedMetricConfiguratorInterface $computedMetricsOverride = null,
        ?RuleChannelSnapshotFactoryInterface $snapshotFactoryOverride = null,
        ?LoggerFactoryInterface $loggerFactoryOverride = null,
    ): RuntimeConfigurator {
        $architecture = self::createStub(ArchitecturePolicyConfiguratorInterface::class);
        $architectureToken = new class implements ResolvedArchitecturePolicyInterface {
            public function warnings(): array
            {
                return [];
            }
        };
        $architecture->method('resolve')->willReturn($architectureToken);
        if ($computedMetricsOverride === null) {
            $computedMetricsStub = self::createStub(ComputedMetricConfiguratorInterface::class);
            $computedMetricsStub->method('resolve')->willReturn(new ResolvedComputedMetricDefinitions([]));
            $computedMetrics = $computedMetricsStub;
        } else {
            $computedMetrics = $computedMetricsOverride;
        }
        $coupling = self::createStub(CouplingConfiguratorInterface::class);
        $coupling->method('resolve')->willReturn([]);
        $failure = new InvalidArgumentException('late ' . ($failingOwner ?? 'owner') . ' resolution failure');
        if ($failingOwner === 'architecture') {
            $architecture->method('resolve')->willThrowException($failure);
        } elseif ($failingOwner === 'computed metrics') {
            $computedMetricsStub = self::createStub(ComputedMetricConfiguratorInterface::class);
            $computedMetricsStub->method('resolve')->willThrowException($failure);
            $computedMetrics = $computedMetricsStub;
        } elseif ($failingOwner === 'coupling') {
            $coupling->method('resolve')->willThrowException($failure);
        }

        $defaultLoggerFactory = self::createStub(LoggerFactoryInterface::class);
        $defaultLoggerFactory->method('create')->willReturn(new NullLogger());
        $loggerFactory = $loggerFactoryOverride ?? $defaultLoggerFactory;
        $ruleRegistry = self::createStub(RuleRegistryInterface::class);
        $ruleRegistry->method('getClasses')->willReturn([LcomRule::class]);
        // The universe carries the addressable names, which is what the
        // validator reads; a registry stub alone no longer says which they are.
        $staticChannels = new ChannelUniverse(
            LcomRule::channelDeclarations(),
            [LcomRule::NAME => array_keys(LcomRule::channelDeclarations())],
            [LcomRule::NAME => false],
            new ResolvedComputedMetricDefinitions([]),
            ...self::unusedReachPorts(),
        );
        $this->snapshotFactory = $snapshotFactoryOverride ?? $staticChannels;
        $metadata = [new \Qualimetrix\Analysis\Finding\Contract\RuleMetadata(LcomRule::NAME, LcomRule::getOptionsClass(), LcomRule::getDescription(), [], false)];
        $ruleInputValidator = new RuleInputValidator(
            $ruleRegistry,
            $this->snapshotFactory,
            new \Qualimetrix\Analysis\Finding\Contract\Configuration\RuleOptionsBuild(\Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture::execution($metadata)),
            $computedMetrics,
            new RuleEnablementResolver(),
        );
        $analysis = new AnalysisRuntimeConfigurator(
            $this->rules,
            new LcomCollectionConfigurationResolver(),
            $this->lcomStore,
            $architecture,
            $computedMetrics,
            $coupling,
            $ruleInputValidator,
        );

        $errorStream = new ErrorStream();

        return new RuntimeConfigurator(
            new RuntimeLoggerConfigurator($loggerFactory, new LoggerHolder(), $errorStream, new RunTargets($loggerFactory)),
            new ProgressConfigurator($this->progress, $errorStream),
            $this->profile,
            $analysis,
            $this->cacheFactory,
            $this->parallelStore,
            new RuntimeLimitsController(),
            new \Qualimetrix\Infrastructure\Console\ProjectSourceConfigurator(
                new \Qualimetrix\Infrastructure\Composer\ComposerManifestReader(),
                new \Qualimetrix\Analysis\Evidence\Measurement\Namespace_\ProjectNamespaceResolver(),
                new ComposerAutoloadMap(new \Qualimetrix\Infrastructure\Composer\ComposerManifestReader()),
            ),
        );
    }

    #[Test]
    public function itAppliesOwnerConfigurationsOnlyAfterEveryValueResolves(): void
    {
        $document = $this->customDocument();

        $this->configurator->resetRunState();
        $this->configure(
            $document,
            AbsolutePath::fromString($this->projectRoot),
            $this->input(['--show-suppressed' => true, '--profile' => null]),
            new BufferedOutput(),
        );

        self::assertSame($this->projectRoot . '/cache', $this->cacheStore->current()->directory->value());
        self::assertFalse($this->cacheStore->current()->enabled);
        self::assertSame(3, $this->parallelStore->current()->workers);
        self::assertSame(['getName'], $this->lcomStore->current()->excludedMethods);
        self::assertTrue($this->rules->capturesExcludedFindings());
        self::assertTrue($this->profile->isEnabled());
    }

    #[Test]
    public function itRefusesAnIncompleteCacheClearWithTheDirectoryAndReason(): void
    {
        $directory = $this->projectRoot . '/cache-file';
        file_put_contents($directory, 'KEEP');
        $this->cacheFactory->replaceConfiguration(new CacheConfiguration(AbsolutePath::fromString($directory)));

        try {
            $this->configurator->clearCacheIfRequested($this->input(['--clear-cache' => true]));
            self::fail('An incomplete cache clear must refuse the run.');
        } catch (EnvironmentRefusal $refusal) {
            self::assertStringContainsString($directory, $refusal->summary());
            self::assertStringContainsString('Cache directory is not a directory', $refusal->summary());
            self::assertSame('KEEP', file_get_contents($directory));
        }
    }

    #[Test]
    public function itWarnsOnceWhenTheDefaultCacheIsDisabledForAnUnusableDirectory(): void
    {
        $reason = 'Default cache disabled: cache directory cannot be searched.';
        $logger = self::createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with($reason);
        $factory = self::createStub(LoggerFactoryInterface::class);
        $factory->method('create')->willReturn($logger);
        $configurator = $this->createConfigurator(loggerFactoryOverride: $factory);
        $document = $this->customDocument();
        $root = AbsolutePath::fromString($this->projectRoot);
        $run = new \Qualimetrix\Infrastructure\Console\ResolvedRunConfiguration(
            $this->runConfigurationFor($document),
            new CacheConfiguration(AbsolutePath::fromString($this->projectRoot . '/cache'), false, $reason),
            (new ParallelConfigurationResolver())->resolve($document),
        );

        $configurator->configure($document, $run, $this->findingConfigurationFor($document), $this->input(), new BufferedOutput());
    }

    #[Test]
    public function itResolvesDefinitionsOnceAndCommitsTheExactDefinitionsAndChannelSnapshot(): void
    {
        $definitions = new ResolvedComputedMetricDefinitions([]);
        $computedMetrics = $this->createMock(ComputedMetricConfiguratorInterface::class);
        $computedMetrics->expects(self::once())->method('resolve')->willReturn($definitions);
        $computedMetrics->expects(self::once())->method('replace')->with(self::identicalTo($definitions));
        $snapshot = self::createStub(ChannelUniverseInterface::class);
        $factory = new class ($snapshot) implements RuleChannelSnapshotFactoryInterface {
            public ?ResolvedComputedMetricDefinitions $received = null;

            public function __construct(private readonly ChannelUniverseInterface $snapshot) {}

            public function snapshot(ResolvedComputedMetricDefinitions $definitions): ChannelUniverseInterface
            {
                $this->received = $definitions;

                return $this->snapshot;
            }
        };
        $this->snapshotDefinitions = $definitions;
        $this->configurator = $this->createConfigurator(null, $computedMetrics, $factory);

        $this->configurator->resetRunState();
        $this->configure(
            self::document([], AbsolutePath::fromString($this->projectRoot)),
            AbsolutePath::fromString($this->projectRoot),
            $this->input(),
            new BufferedOutput(),
        );

        self::assertSame($definitions, $factory->received);
        self::assertSame($snapshot, $this->rules->channelUniverse());
    }

    #[Test]
    public function itLeavesAllStoresAtDefaultsWhenSelectorValidationFails(): void
    {
        $root = AbsolutePath::fromString($this->projectRoot);
        $this->configurator->resetRunState();

        try {
            $this->configure(
                self::document([
                    ['source' => 'test', 'values' => [
                        'cache.enabled' => false,
                        'parallel.workers' => 0,
                        'only_rules' => ['unknown.selector'],
                    ]],
                ], $root),
                $root,
                $this->input(['--show-suppressed' => true, '--profile' => null]),
                new BufferedOutput(),
            );
            self::fail('Unknown selector validation must fail.');
        } catch (ConfigurationRefusal) {
            self::assertTrue($this->cacheStore->current()->enabled);
            self::assertNull($this->parallelStore->current()->workers);
            self::assertSnapshotUnavailable($this->rules);
            self::assertFalse($this->rules->capturesExcludedFindings());
            self::assertSame([], $this->lcomStore->current()->excludedMethods);
            self::assertFalse($this->profile->isEnabled());
        }
    }

    #[Test]
    public function itResetsACustomRunBeforeApplyingDefaultValuesInTheSameProcess(): void
    {
        $root = AbsolutePath::fromString($this->projectRoot);
        $this->configurator->resetRunState();
        $this->configure(
            $this->customDocument(),
            $root,
            $this->input(['--show-suppressed' => true, '--profile' => null]),
            new BufferedOutput(),
        );
        $firstCache = $this->cacheFactory->create();
        $firstSnapshot = $this->rules->resolvedOptions();

        $this->configurator->resetRunState();
        $this->configure(
            self::document([], $root),
            $root,
            $this->input(),
            new BufferedOutput(),
        );
        $secondCache = $this->cacheFactory->create();

        self::assertNotSame($firstCache, $secondCache);
        self::assertSame($this->projectRoot . '/.qmx-cache', $this->cacheStore->current()->directory->value());
        self::assertTrue($this->cacheStore->current()->enabled);
        self::assertNull($this->parallelStore->current()->workers);
        $secondSnapshot = $this->rules->resolvedOptions();
        self::assertNotSame($firstSnapshot, $secondSnapshot);
        self::assertSame(['cohesion.lcom'], array_keys($secondSnapshot->all()));
        $options = $secondSnapshot->for('cohesion.lcom');
        self::assertInstanceOf(LcomOptions::class, $options);
        self::assertTrue($options->enabled);
        self::assertNotSame($firstSnapshot->for('cohesion.lcom'), $options);
        $enablement = $this->rules->enablement();
        self::assertNotNull($enablement);
        self::assertNull($enablement->filter());
        self::assertNotEmpty($enablement->decisions());
        self::assertSame([], array_filter($enablement->decisions(), static fn($decision): bool => $decision->statement !== null));
        self::assertFalse($this->rules->capturesExcludedFindings());
        self::assertSame([], $this->lcomStore->current()->excludedMethods);
        self::assertFalse($this->profile->isEnabled());
    }

    #[Test]
    public function itClearsTheCommittedRunBeforeAnyConfigurationResolution(): void
    {
        $analysis = (new ReflectionClass($this->configurator))->getProperty('analysisRuntimeConfigurator')->getValue($this->configurator);
        self::assertInstanceOf(AnalysisRuntimeConfigurator::class, $analysis);
        $validator = (new ReflectionClass($analysis))->getProperty('ruleInputValidator')->getValue($analysis);
        self::assertInstanceOf(RuleInputValidator::class, $validator);
        self::assertSame($this->snapshotFactory, (new ReflectionClass($validator))->getProperty('ruleChannelSnapshotFactory')->getValue($validator));
        $root = AbsolutePath::fromString($this->projectRoot);
        $this->configure($this->customDocument(), $root, $this->input(['--show-suppressed' => true, '--profile' => null]), new BufferedOutput());
        self::assertNotNull($this->rules->enablement());

        $this->configurator->resetRunState();

        self::assertNull($this->rules->enablement());
        self::assertTrue($this->cacheStore->current()->enabled);
        self::assertNull($this->parallelStore->current()->workers);
        self::assertSnapshotUnavailable($this->rules);
        self::assertSame([], $this->lcomStore->current()->excludedMethods);
        self::assertFalse($this->profile->isEnabled());
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Rule channels are unavailable before analysis preflight.');
        $this->rules->channelUniverse();
    }

    #[Test]
    #[DataProvider('lateFailureOwners')]
    public function itLeavesEveryMutableOwnerAtDefaultsWhenLateResolutionFails(string $owner): void
    {
        $root = AbsolutePath::fromString($this->projectRoot);
        $this->configurator = $this->createConfigurator($owner);
        $this->configurator->resetRunState();

        try {
            $this->configure(
                self::document([
                    ['source' => 'custom', 'values' => [
                        'cache.enabled' => false,
                        'parallel.workers' => 0,
                        'rules' => ['cohesion.lcom' => ['exclude_methods' => ['getName']]],
                    ]],
                ], $root),
                $root,
                $this->input(['--show-suppressed' => true, '--profile' => null]),
                new BufferedOutput(),
            );
            self::fail('Late owner resolution must fail');
        } catch (InvalidArgumentException) {
            self::assertNull($this->parallelStore->current()->workers);
            self::assertTrue($this->cacheStore->current()->enabled);
            self::assertSnapshotUnavailable($this->rules);
            self::assertFalse($this->rules->capturesExcludedFindings());
            self::assertSame([], $this->lcomStore->current()->excludedMethods);
            self::assertFalse($this->profile->isEnabled());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function lateFailureOwners(): iterable
    {
        yield 'Architecture' => ['architecture'];
        yield 'ComputedMetrics' => ['computed metrics'];
        yield 'Coupling' => ['coupling'];
    }

    #[Test]
    public function itReportsAnUnapplicableMemoryLimitAfterCommittingStoresAndBeforeLaterEffects(): void
    {
        $root = AbsolutePath::fromString($this->projectRoot);
        $document = self::document([
            ['source' => 'custom', 'values' => [
                'cache.enabled' => false,
                'parallel.workers' => 0,
                'memory_limit' => '1',
                'rules' => ['cohesion.lcom' => ['exclude_methods' => ['getName']]],
            ]],
        ], $root);
        $this->configurator->resetRunState();

        try {
            $this->configure($document, $root, $this->input(['--profile' => null]), new BufferedOutput());
            self::fail('A memory limit below current usage must fail.');
        } catch (RuntimeException $exception) {
            self::assertStringStartsWith('Cannot set requested memory_limit "1": the PHP runtime refused it.', $exception->getMessage());
        }

        self::assertFalse($this->cacheStore->current()->enabled);
        self::assertSame(0, $this->parallelStore->current()->workers);
        self::assertSame(['getName'], $this->lcomStore->current()->excludedMethods);
        self::assertFalse($this->profile->isEnabled());
    }

    /**
     * An integer is a byte count the document accepts; dropped here, the run
     * went on under the default limit the author wrote over.
     */
    #[Test]
    public function itAppliesAnIntegerMemoryLimit(): void
    {
        $root = AbsolutePath::fromString($this->projectRoot);
        $document = self::document([['source' => 'custom', 'values' => ['memory_limit' => 1]]], $root);
        $this->configurator->resetRunState();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot set requested memory_limit "1": the PHP runtime refused it.');

        $this->configure($document, $root, $this->input([]), new BufferedOutput());
    }

    #[Test]
    public function itRestoresThePreviousErrorHandlerAfterApplyFailure(): void
    {
        $warnings = 0;
        $handler = static function () use (&$warnings): bool {
            ++$warnings;

            return true;
        };
        set_error_handler($handler);

        try {
            try {
                (new RuntimeLimitsController())->apply(new RuntimeLimits('1'));
                self::fail('An unapplicable memory limit must fail.');
            } catch (RuntimeException $exception) {
                self::assertStringStartsWith('Cannot set requested memory_limit "1": the PHP runtime refused it.', $exception->getMessage());
            }

            self::assertSame(0, $warnings);
            $installed = set_error_handler(static fn(): bool => true);
            self::assertSame($handler, $installed);
            restore_error_handler();
        } finally {
            restore_error_handler();
        }
    }

    #[Test]
    public function itRestoresThePreviousErrorHandlerAfterResetFailure(): void
    {
        $reflection = new ReflectionClass(RuntimeLimitsController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('startupLimit')->setValue($controller, '1');
        $warnings = 0;
        $handler = static function () use (&$warnings): bool {
            ++$warnings;

            return true;
        };
        set_error_handler($handler);

        try {
            try {
                $controller->reset();
                self::fail('An unapplicable startup memory limit must fail.');
            } catch (RuntimeException $exception) {
                self::assertSame('Cannot restore process-start memory_limit.', $exception->getMessage());
            }

            self::assertSame(0, $warnings);
            $installed = set_error_handler(static fn(): bool => true);
            self::assertSame($handler, $installed);
            restore_error_handler();
        } finally {
            restore_error_handler();
        }
    }

    #[Test]
    public function itPreservesTheMemoryLimitAuthorAndRuntimeCauseWhenIniSetThrows(): void
    {
        $script = \sprintf(<<<'PHP'
namespace Qualimetrix\Infrastructure\Console {
    function ini_set(string $option, string $value): string|false
    {
        throw new \RuntimeException('the runtime refused this limit');
    }
}

namespace {
    require %s;

    $document = \Qualimetrix\Tests\Analysis\Configuration\Support\LayeredDocument::of([
        ['source' => 'qmx.yaml', 'values' => ['memory_limit' => '512M']],
    ], \Qualimetrix\Core\Path\AbsolutePath::fromString('/project'));

    try {
        (new \Qualimetrix\Infrastructure\Console\RuntimeLimitsController())->apply(
            \Qualimetrix\Infrastructure\Console\RuntimeLimits::fromResolvedValue(
                $document->resolved()->get('memory_limit'),
            ),
        );
    } catch (\Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal $refusal) {
        echo json_encode([
            'sources' => array_map(static fn($source) => [$source->source()->value, $source->locator()], $refusal->sources()),
            'position' => $refusal->position()?->segments,
            'previous' => $refusal->getPrevious()?->getMessage(),
        ], JSON_THROW_ON_ERROR);
    }
}
PHP, var_export(\dirname(__DIR__, 4) . '/vendor/autoload.php', true));

        $run = ChildProcess::run([\PHP_BINARY, '-r', $script]);

        self::assertSame(0, $run['exitCode'], $run['stderr']);
        self::assertSame(
            [
                'sources' => [['file', 'qmx.yaml']],
                'position' => ['memory_limit'],
                'previous' => 'the runtime refused this limit',
            ],
            json_decode($run['stdout'], true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * The payload goes to stdout and the bar to stderr; a test that reads only
     * one of the two cannot tell the arrangement from its predecessor, which
     * drew the bar into the payload.
     */
    #[Test]
    public function itDrawsProgressOnTheErrorStreamOnly(): void
    {
        $output = new SplitStreamConsoleOutput(stdoutDecorated: true, stderrDecorated: true);

        $this->configureWith($output, $this->input());

        $this->progress->start(100);
        $this->progress->advance();
        $this->progress->finish();

        self::assertStringContainsString('0/100', $output->errorOutputContent());
        self::assertSame('', $output->standardOutputContent());
    }

    /**
     * The run this package exists for: `qmx check --format=json > report.json`
     * from a terminal. stdout is a file and undecorated, stderr is the
     * terminal — and the bar must still be drawn. Asking stdout instead, as
     * the previous gate did, silently answers "no progress here".
     */
    #[Test]
    public function itEnablesProgressWhenOnlyTheErrorStreamIsATerminal(): void
    {
        $output = new SplitStreamConsoleOutput(stdoutDecorated: false, stderrDecorated: true);

        $this->configureWith($output, $this->input());

        $this->progress->start(100);
        $this->progress->finish();

        self::assertStringContainsString('0/100', $output->errorOutputContent());
        self::assertSame('', $output->standardOutputContent());
    }

    /** The mirror case: a live stdout does not license drawing into a redirected stderr. */
    #[Test]
    public function itDisablesProgressWhenTheErrorStreamIsNotATerminal(): void
    {
        $output = new SplitStreamConsoleOutput(stdoutDecorated: true, stderrDecorated: false);

        $this->configureWith($output, $this->input());

        $this->progress->start(100);
        $this->progress->finish();

        self::assertSame('', $output->errorOutputContent());
        self::assertSame('', $output->standardOutputContent());
    }

    #[Test]
    public function itDisablesProgressWhenNoProgressIsRequested(): void
    {
        $output = new SplitStreamConsoleOutput(stdoutDecorated: true, stderrDecorated: true);

        $this->configureWith($output, $this->input(['--no-progress' => true]));

        $this->progress->start(100);
        $this->progress->finish();

        self::assertSame('', $output->errorOutputContent());
        self::assertSame('', $output->standardOutputContent());
    }

    /** An output with no distinguishable error stream cannot carry a bar at all. */
    #[Test]
    public function itDisablesProgressForAnOutputWithoutAnErrorStream(): void
    {
        $output = new BufferedOutput(decorated: true);

        $this->configureWith($output, $this->input());

        $this->progress->start(100);
        $this->progress->finish();

        self::assertSame('', $output->fetch());
    }

    private function configureWith(OutputInterface $output, ArrayInput $input): void
    {
        $this->configurator->resetRunState();

        $document = $this->customDocument();
        $this->configurator->configure($document, new \Qualimetrix\Infrastructure\Console\ResolvedRunConfiguration($this->runConfigurationFor($document), (new CacheConfigurationResolver())->resolve($document, AbsolutePath::fromString($this->projectRoot)), (new ParallelConfigurationResolver())->resolve($document)), $this->findingConfigurationFor($document), $input, $output);
    }

    private function configure(
        ConfigurationDocument $document,
        AbsolutePath $projectRoot,
        ArrayInput $input,
        BufferedOutput $output,
    ): void {
        $this->configurator->configure($document, new \Qualimetrix\Infrastructure\Console\ResolvedRunConfiguration($this->runConfigurationFor($document), (new CacheConfigurationResolver())->resolve($document, $projectRoot), (new ParallelConfigurationResolver())->resolve($document)), $this->findingConfigurationFor($document), $input, $output);
    }

    private function customDocument(): ConfigurationDocument
    {
        return self::document([
            ['source' => 'qmx.yaml', 'values' => [
                'cache.dir' => 'cache',
                'cache.enabled' => false,
                'parallel.workers' => 3,
                'rules' => ['cohesion.lcom' => ['exclude_methods' => ['getName']]],
                'only_rules' => ['cohesion.lcom'],
            ]],
        ], AbsolutePath::fromString($this->projectRoot));
    }

    /** @param array<string, mixed> $options */
    private function input(array $options = []): ArrayInput
    {
        return new ArrayInput($options, new InputDefinition([
            new InputOption('log-file', null, InputOption::VALUE_REQUIRED),
            new InputOption('log-level', null, InputOption::VALUE_REQUIRED),
            new InputOption('show-suppressed', null, InputOption::VALUE_NONE),
            new InputOption('profile', null, InputOption::VALUE_OPTIONAL, '', false),
            new InputOption('no-progress', null, InputOption::VALUE_NONE),
            new InputOption('clear-cache', null, InputOption::VALUE_NONE),
        ]));
    }

    private function runConfigurationFor(ConfigurationDocument $document): RunConfiguration
    {
        $root = $document->workingDirectory();

        return new RunConfiguration(
            [],
            $root,
            GeneratedFilePolicy::Include,
            projectScope: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement(universe: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse(projectRoot: $root, pathsAuthored: true, denominator: [], prunedTargets: [], reasons: [], namespaceMapUsable: false, pathResolutions: []), paths: [$root], scopeState: \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeState::Unmeasured, uncoveredRoots: []),
            authoredPathExcludes: [],
            autoloadDevPolicy: \Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy::Exclude,
        );
    }

    private function findingConfigurationFor(ConfigurationDocument $document): \Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration
    {
        $configuration = FindingConfiguration::fromDocument($document);
        $metadata = [new \Qualimetrix\Analysis\Finding\Contract\RuleMetadata(LcomRule::NAME, LcomRule::getOptionsClass(), LcomRule::getDescription(), [], false)];
        return \Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture::ready($configuration, $metadata, channels: $this->snapshotFactory->snapshot($this->snapshotDefinitions));
    }

    /** @param list<array{source: string, values: array<string, mixed>}> $sources */
    private static function document(array $sources, AbsolutePath $root): ConfigurationDocument
    {
        return LayeredDocument::of($sources, $root, ...self::ruleSections());
    }

    /** @return list<\Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface> */
    private static function ruleSections(): array
    {
        $container = (new \Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory())->create();
        $execution = $container->get(\Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface::class);
        self::assertInstanceOf(\Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface::class, $execution);
        return array_map(static fn(string $key): \Qualimetrix\Analysis\Finding\RuleConfiguration\RulesSection => new \Qualimetrix\Analysis\Finding\RuleConfiguration\RulesSection($execution, $key), ['rules', 'only_rules', 'disabled_rules']);
    }

    private static function assertSnapshotUnavailable(\Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface $registry): void
    {
        try {
            $registry->resolvedOptions();
            self::fail('The invocation must have no ready rule options.');
        } catch (LogicException $refusal) {
            self::assertSame('Rule options are unavailable before analysis preflight.', $refusal->getMessage());
        }
    }

    /** @return array{\Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricReachCatalogInterface, \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricReachInterface} */
    private static function unusedReachPorts(): array
    {
        return [
            new class implements \Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricReachCatalogInterface {
                public function metricReach(string $metricKey): \Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricReach
                {
                    throw new LogicException('This fixture does not query measured-metric reach.');
                }
            },
            new class implements \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricReachInterface {
                public function reachAt(
                    string $metricName,
                    \Qualimetrix\Core\Symbol\SymbolLevel $level,
                    \Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface $definitions,
                ): \Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricReach {
                    throw new LogicException('This fixture does not query computed-metric reach.');
                }
            },
        ];
    }
}
