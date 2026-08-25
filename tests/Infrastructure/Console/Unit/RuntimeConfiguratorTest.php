<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Evidence\Cohesion\Configuration\LcomCollectionConfigurationResolver;
use Qualimetrix\Analysis\Evidence\Cohesion\LcomRule;
use Qualimetrix\Analysis\Evidence\Cohesion\Runtime\LcomCollectionConfigurationStore;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Configuration\ComputedMetricConfiguratorInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Evidence\Coupling\Contract\Configuration\CouplingConfiguratorInterface;
use Qualimetrix\Analysis\Finding\Configuration\FindingConfigurationResolver;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingCliOverrides;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleChannelRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitecturePolicyConfiguratorInterface;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ResolvedArchitecturePolicyInterface;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Cache\CacheConfigurationResolver;
use Qualimetrix\Infrastructure\Cache\CacheConfigurationStore;
use Qualimetrix\Infrastructure\Cache\CacheFactory;
use Qualimetrix\Infrastructure\Console\AnalysisRuntimeConfigurator;
use Qualimetrix\Infrastructure\Console\Progress\SwitchableProgressReporter;
use Qualimetrix\Infrastructure\Console\RuleInputValidator;
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
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

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

    protected function setUp(): void
    {
        $this->cacheStore = new CacheConfigurationStore();
        $this->cacheFactory = new CacheFactory($this->cacheStore);
        $this->parallelStore = new ParallelConfigurationStore();
        $this->rules = new RuleOptionsRegistry();
        $this->lcomStore = new LcomCollectionConfigurationStore();
        $this->profile = new ProfileSession();
        $this->progress = new SwitchableProgressReporter();

        $this->configurator = $this->createConfigurator();
    }

    private function createConfigurator(
        ?string $failingOwner = null,
        ?ComputedMetricConfiguratorInterface $computedMetricsOverride = null,
        ?RuleChannelSnapshotFactoryInterface $snapshotFactoryOverride = null,
        ?RuleSelector $selectorOverride = null,
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

        $loggerFactory = self::createStub(LoggerFactoryInterface::class);
        $loggerFactory->method('create')->willReturn(new NullLogger());
        $ruleRegistry = self::createStub(RuleRegistryInterface::class);
        $ruleRegistry->method('getClasses')->willReturn([LcomRule::class]);
        // The universe carries the addressable names, which is what the
        // validator reads; a registry stub alone no longer says which they are.
        $staticChannels = new ChannelUniverse(
            [],
            [],
            [LcomRule::NAME => false],
            new ResolvedComputedMetricDefinitions([]),
        );
        $ruleSelector = $selectorOverride ?? new RuleSelector($staticChannels);
        $ruleInputValidator = new RuleInputValidator(
            $ruleRegistry,
            $ruleSelector,
            new FindingConfigurationResolver(),
            $snapshotFactoryOverride ?? $staticChannels,
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

        return new RuntimeConfigurator(
            new RuntimeLoggerConfigurator($loggerFactory, new LoggerHolder()),
            $this->progress,
            $this->profile,
            $analysis,
            $this->cacheFactory,
            $this->parallelStore,
            new RuntimeLimitsController(),
        );
    }

    #[Test]
    public function itAppliesOwnerConfigurationsOnlyAfterEveryValueResolves(): void
    {
        $document = $this->customDocument();

        $this->configurator->resetRunState();
        $this->configure(
            $document,
            AbsolutePath::fromString('/project'),
            $this->input(['--show-suppressed' => true, '--profile' => null]),
            new BufferedOutput(),
        );

        self::assertSame('/project/cache', $this->cacheStore->current()->directory->value());
        self::assertFalse($this->cacheStore->current()->enabled);
        self::assertSame(3, $this->parallelStore->current()->workers);
        self::assertSame(['getName'], $this->lcomStore->current()->excludedMethods);
        self::assertTrue($this->rules->capturesExcludedFindings());
        self::assertTrue($this->profile->isEnabled());
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
        $static = self::createStub(RuleChannelRegistryInterface::class);
        $selector = new RuleSelector($static);
        $this->configurator = $this->createConfigurator(null, $computedMetrics, $factory, $selector);

        $this->configurator->resetRunState();
        $this->configure(
            new ConfigurationDocument([], AbsolutePath::fromString('/project')),
            AbsolutePath::fromString('/project'),
            $this->input(),
            new BufferedOutput(),
        );

        self::assertSame($definitions, $factory->received);
        $property = (new ReflectionClass($selector))->getProperty('channels');
        self::assertSame($snapshot, $property->getValue($selector));
    }

    #[Test]
    public function itLeavesStaticChannelsAndAllStoresAtDefaultsWhenSelectorValidationFails(): void
    {
        $root = AbsolutePath::fromString('/project');
        $this->configurator->resetRunState();

        try {
            $this->configure(
                new ConfigurationDocument([
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
        } catch (InvalidArgumentException) {
            self::assertTrue($this->cacheStore->current()->enabled);
            self::assertNull($this->parallelStore->current()->workers);
            self::assertSame([], $this->rules->all());
            self::assertFalse($this->rules->capturesExcludedFindings());
            self::assertSame([], $this->lcomStore->current()->excludedMethods);
            self::assertFalse($this->profile->isEnabled());
        }
    }

    #[Test]
    public function itResetsACustomRunBeforeApplyingDefaultValuesInTheSameProcess(): void
    {
        $root = AbsolutePath::fromString('/project');
        $this->configurator->resetRunState();
        $this->configure(
            $this->customDocument(),
            $root,
            $this->input(['--show-suppressed' => true, '--profile' => null]),
            new BufferedOutput(),
        );
        $firstCache = $this->cacheFactory->create();

        $this->configurator->resetRunState();
        $this->configure(
            new ConfigurationDocument([], $root),
            $root,
            $this->input(),
            new BufferedOutput(),
        );
        $secondCache = $this->cacheFactory->create();

        self::assertNotSame($firstCache, $secondCache);
        self::assertSame('/project/.qmx-cache', $this->cacheStore->current()->directory->value());
        self::assertTrue($this->cacheStore->current()->enabled);
        self::assertNull($this->parallelStore->current()->workers);
        self::assertSame([], $this->rules->all());
        self::assertSame([], $this->rules->selection()->only);
        self::assertSame([], $this->rules->selection()->disabled);
        self::assertFalse($this->rules->capturesExcludedFindings());
        self::assertSame([], $this->lcomStore->current()->excludedMethods);
        self::assertFalse($this->profile->isEnabled());
    }

    #[Test]
    public function itRestoresStaticOnlyChannelsBeforeAnyConfigurationResolution(): void
    {
        $analysis = (new ReflectionClass($this->configurator))->getProperty('analysisRuntimeConfigurator')->getValue($this->configurator);
        self::assertInstanceOf(AnalysisRuntimeConfigurator::class, $analysis);
        $validator = (new ReflectionClass($analysis))->getProperty('ruleInputValidator')->getValue($analysis);
        self::assertInstanceOf(RuleInputValidator::class, $validator);
        $selector = (new ReflectionClass($validator))->getProperty('ruleSelector')->getValue($validator);
        self::assertInstanceOf(RuleSelector::class, $selector);
        $staticChannels = (new ReflectionClass($selector))->getProperty('defaultChannels')->getValue($selector);
        $selector->replaceChannels(self::createStub(RuleChannelRegistryInterface::class));

        $this->configurator->resetRunState();

        self::assertSame(
            $staticChannels,
            (new ReflectionClass($selector))->getProperty('channels')->getValue($selector),
        );
        self::assertTrue($this->cacheStore->current()->enabled);
        self::assertNull($this->parallelStore->current()->workers);
        self::assertSame([], $this->rules->all());
        self::assertSame([], $this->lcomStore->current()->excludedMethods);
        self::assertFalse($this->profile->isEnabled());
    }

    #[Test]
    #[DataProvider('lateFailureOwners')]
    public function itLeavesEveryMutableOwnerAtDefaultsWhenLateResolutionFails(string $owner): void
    {
        $root = AbsolutePath::fromString('/project');
        $this->configurator = $this->createConfigurator($owner);
        $this->configurator->resetRunState();

        try {
            $this->configure(
                new ConfigurationDocument([
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
            self::assertSame([], $this->rules->all());
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
        $root = AbsolutePath::fromString('/project');
        $document = new ConfigurationDocument([
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
            self::assertSame('Cannot set requested memory_limit.', $exception->getMessage());
        }

        self::assertFalse($this->cacheStore->current()->enabled);
        self::assertSame(0, $this->parallelStore->current()->workers);
        self::assertSame(['getName'], $this->lcomStore->current()->excludedMethods);
        self::assertFalse($this->profile->isEnabled());
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
                self::assertSame('Cannot set requested memory_limit.', $exception->getMessage());
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

    private function configure(
        ConfigurationDocument $document,
        AbsolutePath $projectRoot,
        ArrayInput $input,
        BufferedOutput $output,
    ): void {
        $this->configurator->configure(
            $document,
            (new FindingConfigurationResolver())->resolve($document, new FindingCliOverrides([])),
            (new CacheConfigurationResolver())->resolve($document, $projectRoot),
            (new ParallelConfigurationResolver())->resolve($document),
            $input,
            $output,
        );
    }

    private function customDocument(): ConfigurationDocument
    {
        return new ConfigurationDocument([
            ['source' => 'qmx.yaml', 'values' => [
                'cache.dir' => 'cache',
                'cache.enabled' => false,
                'parallel.workers' => 3,
                'rules' => ['cohesion.lcom' => ['exclude_methods' => ['getName']]],
                'only_rules' => ['cohesion.lcom'],
            ]],
        ], AbsolutePath::fromString('/project'));
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
        ]));
    }
}
