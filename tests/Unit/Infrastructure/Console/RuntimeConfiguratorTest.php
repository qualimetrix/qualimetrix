<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Qualimetrix\Architecture\Domain\ArchitectureConfiguration;
use Qualimetrix\Architecture\Processing\ArchitectureProcessor;
use Qualimetrix\Architecture\Processing\ArchitectureProcessorInterface;
use Qualimetrix\Configuration\AnalysisConfiguration;
use Qualimetrix\Configuration\ComputedMetricFormulaValidator;
use Qualimetrix\Configuration\ComputedMetricsConfigResolver;
use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Configuration\HealthFormulaExcluder;
use Qualimetrix\Configuration\PathsConfiguration;
use Qualimetrix\Configuration\Pipeline\DeferredWarning;
use Qualimetrix\Configuration\Pipeline\ResolvedConfiguration;
use Qualimetrix\Configuration\RuleOptionsRegistry;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefinitionHolder;
use Qualimetrix\Core\Coupling\FrameworkNamespacesHolder;
use Qualimetrix\Core\Metric\CollectorConfigHolder;
use Qualimetrix\Core\Profiler\ProfilerHolder;
use Qualimetrix\Infrastructure\Cache\CacheFactory;
use Qualimetrix\Infrastructure\Console\Progress\ProgressReporterHolder;
use Qualimetrix\Infrastructure\Console\RuntimeConfigurator;
use Qualimetrix\Infrastructure\Logging\LoggerFactory;
use Qualimetrix\Infrastructure\Logging\LoggerHolder;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

#[CoversClass(RuntimeConfigurator::class)]
#[CoversClass(HealthFormulaExcluder::class)]
#[CoversClass(DeferredWarning::class)]
final class RuntimeConfiguratorTest extends TestCase
{
    private ConfigurationProviderInterface&Stub $configProvider;
    private RuleOptionsRegistry $ruleOptionsRegistry;
    private FrameworkNamespacesHolder $frameworkNamespacesHolder;
    private ArchitectureProcessorInterface $architectureProcessor;
    private RuntimeConfigurator $configurator;

    protected function setUp(): void
    {
        $this->ruleOptionsRegistry = new RuleOptionsRegistry();
        $this->frameworkNamespacesHolder = new FrameworkNamespacesHolder();
        $this->architectureProcessor = new ArchitectureProcessor();

        $this->configProvider = self::createStub(ConfigurationProviderInterface::class);
        $this->configurator = $this->buildConfigurator($this->configProvider);
    }

    /**
     * Replaces the default stub with a mock and rebuilds the configurator.
     *
     * Call this in tests that need `expects()` on the config provider.
     */
    private function useConfigProviderMock(): ConfigurationProviderInterface&MockObject
    {
        $mock = $this->createMock(ConfigurationProviderInterface::class);
        $this->configProvider = $mock;
        $this->configurator = $this->buildConfigurator($mock);

        return $mock;
    }

    private function buildConfigurator(ConfigurationProviderInterface $configProvider): RuntimeConfigurator
    {
        $loggerFactory = new LoggerFactory();
        $loggerHolder = new LoggerHolder();

        $ruleRegistry = self::createStub(RuleRegistryInterface::class);
        $ruleRegistry->method('getClasses')->willReturn([]);

        return new RuntimeConfigurator(
            $loggerFactory,
            $loggerHolder,
            new ProgressReporterHolder(),
            new ProfilerHolder(),
            $configProvider,
            $this->ruleOptionsRegistry,
            $ruleRegistry,
            new CacheFactory($configProvider),
            new ComputedMetricsConfigResolver(
                new ComputedMetricFormulaValidator(),
                new HealthFormulaExcluder(),
            ),
            $this->frameworkNamespacesHolder,
            $this->architectureProcessor,
        );
    }

    #[Test]
    public function itResetsCliOptionsBetweenConfigureCalls(): void
    {
        $configProvider = $this->useConfigProviderMock();

        // First configure call: set CLI options
        $resolved1 = new ResolvedConfiguration(
            paths: PathsConfiguration::defaults(),
            analysis: new AnalysisConfiguration(),
            ruleOptions: [],
            architecture: ArchitectureConfiguration::empty(),
        );

        $input1 = $this->createCliInput([
            'complexity.cyclomatic:warningThreshold=50',
        ]);

        $configProvider
            ->expects($this->exactly(2))
            ->method('setConfiguration');
        $configProvider
            ->expects($this->exactly(2))
            ->method('setRuleOptions');

        $this->configurator->configure($resolved1, $input1, $this->createOutput());

        // Verify CLI options were set
        self::assertArrayHasKey('complexity.cyclomatic', $this->ruleOptionsRegistry->getCliOptions());

        // Second configure call: no CLI options
        $resolved2 = new ResolvedConfiguration(
            paths: PathsConfiguration::defaults(),
            analysis: new AnalysisConfiguration(),
            ruleOptions: [],
            architecture: ArchitectureConfiguration::empty(),
        );

        $input2 = $this->createCliInput([]);

        $this->configurator->configure($resolved2, $input2, $this->createOutput());

        // CLI options from first run should not persist
        self::assertEmpty( // @phpstan-ignore staticMethod.impossibleType
            $this->ruleOptionsRegistry->getCliOptions(),
            'CLI options from first configure() call should not leak into second call',
        );
    }

    #[Test]
    public function itResetsNamespaceExclusionsBetweenConfigureCalls(): void
    {
        $configProvider = $this->useConfigProviderMock();

        // First configure call: set exclude_namespaces via config
        $resolved1 = new ResolvedConfiguration(
            paths: PathsConfiguration::defaults(),
            analysis: new AnalysisConfiguration(),
            ruleOptions: [
                'coupling.cbo' => [
                    'exclude_namespaces' => ['App\\Tests'],
                ],
            ],
            architecture: ArchitectureConfiguration::empty(),
        );

        $input1 = $this->createCliInput([]);

        $configProvider
            ->expects($this->exactly(2))
            ->method('setConfiguration');
        $configProvider
            ->expects($this->exactly(2))
            ->method('setRuleOptions');

        $this->configurator->configure($resolved1, $input1, $this->createOutput());

        // Verify exclusions were set (create() is called lazily, so trigger it)
        // The factory stores config but doesn't call create() yet — exclusions are populated during create().
        // To verify the reset behavior, we manually check the provider after reset.
        $provider = $this->ruleOptionsRegistry->getExclusionProvider();

        // Simulate what happens when create() populates the provider
        $provider->setExclusions('coupling.cbo', ['App\\Tests']);
        self::assertTrue($provider->isExcluded('coupling.cbo', 'App\\Tests'));

        // Second configure call: no exclude_namespaces
        $resolved2 = new ResolvedConfiguration(
            paths: PathsConfiguration::defaults(),
            analysis: new AnalysisConfiguration(),
            ruleOptions: [],
            architecture: ArchitectureConfiguration::empty(),
        );

        $input2 = $this->createCliInput([]);

        $this->configurator->configure($resolved2, $input2, $this->createOutput());

        // Exclusions from first run should not persist
        self::assertFalse(
            $provider->isExcluded('coupling.cbo', 'App\\Tests'),
            'Namespace exclusions from first configure() call should not leak into second call',
        );
    }

    #[Test]
    public function cliOptionOverridesOnlySpecificKeysPreservingYamlOptions(): void
    {
        $configProvider = $this->useConfigProviderMock();

        $resolved = new ResolvedConfiguration(
            paths: PathsConfiguration::defaults(),
            analysis: new AnalysisConfiguration(),
            ruleOptions: [
                'complexity.cyclomatic' => [
                    'warningThreshold' => 10,
                    'errorThreshold' => 20,
                    'enabled' => true,
                ],
            ],
            architecture: ArchitectureConfiguration::empty(),
        );

        $input = $this->createCliInput([
            'complexity.cyclomatic:warningThreshold=15',
        ]);

        $configProvider
            ->expects($this->once())
            ->method('setRuleOptions')
            ->with(self::callback(function (array $options): bool {
                // CLI overrides warningThreshold
                self::assertSame(15, $options['complexity.cyclomatic']['warningThreshold']);
                // YAML values preserved
                self::assertSame(20, $options['complexity.cyclomatic']['errorThreshold']);
                self::assertTrue($options['complexity.cyclomatic']['enabled']);

                return true;
            }));

        $this->configurator->configure($resolved, $input, $this->createOutput());
    }

    #[Test]
    public function cliOptionCanAddNewKeysNotInYaml(): void
    {
        $configProvider = $this->useConfigProviderMock();

        $resolved = new ResolvedConfiguration(
            paths: PathsConfiguration::defaults(),
            analysis: new AnalysisConfiguration(),
            ruleOptions: [
                'complexity.cyclomatic' => [
                    'warningThreshold' => 10,
                ],
            ],
            architecture: ArchitectureConfiguration::empty(),
        );

        $input = $this->createCliInput([
            'complexity.cyclomatic:countNullsafe=false',
        ]);

        $configProvider
            ->expects($this->once())
            ->method('setRuleOptions')
            ->with(self::callback(function (array $options): bool {
                // Original key preserved
                self::assertSame(10, $options['complexity.cyclomatic']['warningThreshold']);
                // New key added from CLI (parser converts 'false' to boolean)
                self::assertFalse($options['complexity.cyclomatic']['countNullsafe']);

                return true;
            }));

        $this->configurator->configure($resolved, $input, $this->createOutput());
    }

    #[Test]
    public function cliCanReplaceAllKeysWhenProvidingCompleteOptions(): void
    {
        $configProvider = $this->useConfigProviderMock();

        $resolved = new ResolvedConfiguration(
            paths: PathsConfiguration::defaults(),
            analysis: new AnalysisConfiguration(),
            ruleOptions: [
                'complexity.cyclomatic' => [
                    'warningThreshold' => 10,
                    'errorThreshold' => 20,
                ],
            ],
            architecture: ArchitectureConfiguration::empty(),
        );

        $input = $this->createCliInput([
            'complexity.cyclomatic:warningThreshold=15',
            'complexity.cyclomatic:errorThreshold=30',
        ]);

        $configProvider
            ->expects($this->once())
            ->method('setRuleOptions')
            ->with(self::callback(function (array $options): bool {
                self::assertSame(15, $options['complexity.cyclomatic']['warningThreshold']);
                self::assertSame(30, $options['complexity.cyclomatic']['errorThreshold']);

                return true;
            }));

        $this->configurator->configure($resolved, $input, $this->createOutput());
    }

    #[Test]
    public function cliOptionsForNewRuleAreAddedAlongsideYamlRules(): void
    {
        $configProvider = $this->useConfigProviderMock();

        $resolved = new ResolvedConfiguration(
            paths: PathsConfiguration::defaults(),
            analysis: new AnalysisConfiguration(),
            ruleOptions: [
                'complexity.cyclomatic' => [
                    'warningThreshold' => 10,
                ],
            ],
            architecture: ArchitectureConfiguration::empty(),
        );

        $input = $this->createCliInput([
            'size.class-count:warningThreshold=50',
        ]);

        $configProvider
            ->expects($this->once())
            ->method('setRuleOptions')
            ->with(self::callback(function (array $options): bool {
                // YAML rule preserved
                self::assertSame(10, $options['complexity.cyclomatic']['warningThreshold']);
                // New rule from CLI added
                self::assertSame(50, $options['size.class-count']['warningThreshold']);

                return true;
            }));

        $this->configurator->configure($resolved, $input, $this->createOutput());
    }

    /**
     * Creates a mock InputInterface that returns the given rule-opt values.
     *
     * @param list<string> $ruleOpts
     */
    private function createCliInput(array $ruleOpts): InputInterface
    {
        $input = self::createStub(InputInterface::class);
        $input->method('getOption')->willReturnCallback(
            static function (string $name) use ($ruleOpts): mixed {
                return match ($name) {
                    'rule-opt' => $ruleOpts,
                    'log-file' => null,
                    'log-level' => 'info',
                    'no-progress' => false,
                    'profile' => false,
                    'cyclomatic-warning', 'cyclomatic-error',
                    'class-count-warning', 'class-count-error' => null,
                    default => null,
                };
            },
        );
        // RuntimeConfigurator / CliOptionsParser now probe `hasOption()` before
        // calling `getOption()` so the configurator can be reused from commands
        // that don't expose the full `check`-command option set
        // (`debug:layer-assignment`). The stub mirrors `CheckCommand`'s surface
        // by reporting every known check-command option as present.
        $input->method('hasOption')->willReturn(true);

        return $input;
    }

    #[Test]
    public function configureSetsFrameworkNamespacesFromConfig(): void
    {
        $resolved = new ResolvedConfiguration(
            paths: PathsConfiguration::defaults(),
            analysis: new AnalysisConfiguration(frameworkNamespaces: ['Symfony', 'Doctrine']),
            ruleOptions: [],
            architecture: ArchitectureConfiguration::empty(),
        );

        $input = $this->createCliInput([]);

        $this->configurator->configure($resolved, $input, $this->createOutput());

        $namespaces = $this->frameworkNamespacesHolder->get();
        self::assertFalse($namespaces->isEmpty());
        self::assertTrue($namespaces->isFramework('Symfony\\Component\\Console'));
        self::assertTrue($namespaces->isFramework('Doctrine\\ORM\\EntityManager'));
        self::assertFalse($namespaces->isFramework('App\\Service\\UserService'));
    }

    #[Test]
    public function resetClearsFrameworkNamespacesBetweenConfigureCalls(): void
    {
        $configProvider = $this->useConfigProviderMock();

        // First configure: set framework namespaces
        $resolved1 = new ResolvedConfiguration(
            paths: PathsConfiguration::defaults(),
            analysis: new AnalysisConfiguration(frameworkNamespaces: ['Symfony']),
            ruleOptions: [],
            architecture: ArchitectureConfiguration::empty(),
        );

        $configProvider
            ->expects($this->exactly(2))
            ->method('setConfiguration');
        $configProvider
            ->expects($this->exactly(2))
            ->method('setRuleOptions');

        $this->configurator->configure($resolved1, $this->createCliInput([]), $this->createOutput());

        self::assertFalse($this->frameworkNamespacesHolder->get()->isEmpty());
        self::assertTrue($this->frameworkNamespacesHolder->get()->isFramework('Symfony\\Console'));

        // Second configure: no framework namespaces
        $resolved2 = new ResolvedConfiguration(
            paths: PathsConfiguration::defaults(),
            analysis: new AnalysisConfiguration(),
            ruleOptions: [],
            architecture: ArchitectureConfiguration::empty(),
        );

        $this->configurator->configure($resolved2, $this->createCliInput([]), $this->createOutput());

        // Framework namespaces from first run should be cleared
        self::assertTrue(
            $this->frameworkNamespacesHolder->get()->isEmpty(),
            'Framework namespaces from first configure() call should not leak into second call',
        );
    }

    #[Test]
    public function excludeHealthFiltersDimensionsAndNormalizesOverall(): void
    {
        $resolved = new ResolvedConfiguration(
            paths: PathsConfiguration::defaults(),
            analysis: new AnalysisConfiguration(excludeHealth: ['typing']),
            ruleOptions: [],
            architecture: ArchitectureConfiguration::empty(),
        );

        $input = $this->createCliInput([]);

        $this->configurator->configure($resolved, $input, $this->createOutput());

        $definitions = ComputedMetricDefinitionHolder::getDefinitions();
        $names = array_map(static fn($d) => $d->name, $definitions);

        // health.typing should be excluded
        self::assertNotContains('health.typing', $names);

        // Other dimensions should remain
        self::assertContains('health.complexity', $names);
        self::assertContains('health.cohesion', $names);
        self::assertContains('health.coupling', $names);
        self::assertContains('health.maintainability', $names);
        self::assertContains('health.overall', $names);

        // health.overall formula should not reference typing
        $overall = null;
        foreach ($definitions as $def) {
            if ($def->name === 'health.overall') {
                $overall = $def;
                break;
            }
        }
        self::assertNotNull($overall);

        foreach ($overall->formulas as $formula) {
            self::assertStringNotContainsString('health__typing', $formula);
        }
    }

    #[Test]
    public function configureCollectorsSetsExcludeMethodsFromArray(): void
    {
        CollectorConfigHolder::reset();

        $resolved = new ResolvedConfiguration(
            paths: PathsConfiguration::defaults(),
            analysis: new AnalysisConfiguration(),
            ruleOptions: [
                'design.lcom' => [
                    'exclude_methods' => ['getName', 'getDescription'],
                ],
            ],
            architecture: ArchitectureConfiguration::empty(),
        );

        $this->configurator->configure($resolved, $this->createCliInput([]), $this->createOutput());

        self::assertSame(
            ['getName', 'getDescription'],
            CollectorConfigHolder::get(CollectorConfigHolder::LCOM_EXCLUDE_METHODS),
        );
    }

    #[Test]
    public function configureCollectorsSetsExcludeMethodsFromCommaSeparatedString(): void
    {
        CollectorConfigHolder::reset();

        $resolved = new ResolvedConfiguration(
            paths: PathsConfiguration::defaults(),
            analysis: new AnalysisConfiguration(),
            ruleOptions: [
                'design.lcom' => [
                    'exclude_methods' => 'getName, getDescription',
                ],
            ],
            architecture: ArchitectureConfiguration::empty(),
        );

        $this->configurator->configure($resolved, $this->createCliInput([]), $this->createOutput());

        self::assertSame(
            ['getName', 'getDescription'],
            CollectorConfigHolder::get(CollectorConfigHolder::LCOM_EXCLUDE_METHODS),
        );
    }

    #[Test]
    public function configureCollectorsSetsExcludeMethodsFromSingleString(): void
    {
        CollectorConfigHolder::reset();

        $resolved = new ResolvedConfiguration(
            paths: PathsConfiguration::defaults(),
            analysis: new AnalysisConfiguration(),
            ruleOptions: [
                'design.lcom' => [
                    'exclude_methods' => 'getName',
                ],
            ],
            architecture: ArchitectureConfiguration::empty(),
        );

        $this->configurator->configure($resolved, $this->createCliInput([]), $this->createOutput());

        self::assertSame(
            ['getName'],
            CollectorConfigHolder::get(CollectorConfigHolder::LCOM_EXCLUDE_METHODS),
        );
    }

    #[Test]
    public function configureCollectorsSkipsWhenNoExcludeMethods(): void
    {
        CollectorConfigHolder::reset();

        $resolved = new ResolvedConfiguration(
            paths: PathsConfiguration::defaults(),
            analysis: new AnalysisConfiguration(),
            ruleOptions: [
                'design.lcom' => [
                    'warning' => 5,
                ],
            ],
            architecture: ArchitectureConfiguration::empty(),
        );

        $this->configurator->configure($resolved, $this->createCliInput([]), $this->createOutput());

        self::assertNull(
            CollectorConfigHolder::get(CollectorConfigHolder::LCOM_EXCLUDE_METHODS),
        );
    }

    #[Test]
    public function excludeHealthAcceptsHealthPrefixedNames(): void
    {
        $resolved = new ResolvedConfiguration(
            paths: PathsConfiguration::defaults(),
            analysis: new AnalysisConfiguration(excludeHealth: ['health.complexity', 'cohesion']),
            ruleOptions: [],
            architecture: ArchitectureConfiguration::empty(),
        );

        $input = $this->createCliInput([]);

        $this->configurator->configure($resolved, $input, $this->createOutput());

        $definitions = ComputedMetricDefinitionHolder::getDefinitions();
        $names = array_map(static fn($d) => $d->name, $definitions);

        self::assertNotContains('health.complexity', $names);
        self::assertNotContains('health.cohesion', $names);
        self::assertContains('health.coupling', $names);
        self::assertContains('health.typing', $names);
    }

    // -------------------------------------------------------------------------
    // Deferred-warning drain
    // -------------------------------------------------------------------------

    #[Test]
    public function deferredWarningsAreReplayedThroughConfiguredLogger(): void
    {
        // The architecture factory captures mutual-allow / pattern-collision
        // warnings as DeferredWarnings during pipeline resolution. They must
        // reach whichever logger LoggerHolder ends up carrying after
        // configureLogger() has run — NOT the NullLogger placeholder that was
        // in the holder during pipeline resolution.

        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $loggerHolder = $this->buildConfiguratorWithBufferedOutput($output);

        $resolved = new ResolvedConfiguration(
            paths: PathsConfiguration::defaults(),
            analysis: new AnalysisConfiguration(),
            ruleOptions: [],
            deferredWarnings: [
                new DeferredWarning(LogLevel::WARNING, 'architecture.allow: mutual-allow detected between layer pair(s): a ↔ b.'),
            ],
            architecture: ArchitectureConfiguration::empty(),
        );

        $this->configurator->configure($resolved, $this->createCliInput([]), $output);

        // The buffered ConsoleLogger emits warnings at VERBOSITY_NORMAL with a
        // <comment> tag, so plain text should be visible regardless of decoration.
        $rendered = $output->fetch();
        self::assertStringContainsString('mutual-allow detected', $rendered);
        self::assertStringContainsString('a ↔ b', $rendered);

        // Sanity: the logger that received the warning is the one in the holder
        // (proving the drain happens AFTER configureLogger swapped it in).
        self::assertNotInstanceOf(\Psr\Log\NullLogger::class, $loggerHolder->getLogger());
    }

    #[Test]
    public function emptyDeferredWarningsListProducesNoLogOutput(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $this->buildConfiguratorWithBufferedOutput($output);

        $resolved = new ResolvedConfiguration(
            paths: PathsConfiguration::defaults(),
            analysis: new AnalysisConfiguration(),
            ruleOptions: [],
            architecture: ArchitectureConfiguration::empty(),
        );

        $this->configurator->configure($resolved, $this->createCliInput([]), $output);

        $rendered = $output->fetch();
        self::assertSame('', $rendered, 'No warnings should be logged when deferredWarnings is empty');
    }

    #[Test]
    public function deferredWarningLevelIsRoutedToTheLoggerVerbatim(): void
    {
        // Multiple deferred warnings at different levels must each travel through
        // log($level, ...) on the configured logger. The ConsoleLogger renders
        // warnings inside <comment> tags and errors inside <error> tags; the
        // distinct prefixes prove the level was preserved when drained.

        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $this->buildConfiguratorWithBufferedOutput($output);

        $resolved = new ResolvedConfiguration(
            paths: PathsConfiguration::defaults(),
            analysis: new AnalysisConfiguration(),
            ruleOptions: [],
            deferredWarnings: [
                new DeferredWarning(LogLevel::WARNING, 'first warning'),
                new DeferredWarning(LogLevel::ERROR, 'second error'),
            ],
            architecture: ArchitectureConfiguration::empty(),
        );

        $this->configurator->configure($resolved, $this->createCliInput([]), $output);

        $rendered = $output->fetch();
        self::assertStringContainsString('[WARNING]', $rendered);
        self::assertStringContainsString('first warning', $rendered);
        self::assertStringContainsString('[ERROR]', $rendered);
        self::assertStringContainsString('second error', $rendered);
    }

    /**
     * Wires the test configurator with a BufferedOutput-friendly LoggerFactory
     * and returns the LoggerHolder so the test can inspect it after configure().
     */
    private function buildConfiguratorWithBufferedOutput(BufferedOutput $output): LoggerHolder
    {
        // The real LoggerFactory honors verbosity, so a VERBOSITY_NORMAL
        // BufferedOutput will produce a ConsoleLogger that writes warnings to
        // the buffer (default level == WARNING).
        $loggerFactory = new LoggerFactory();
        $loggerHolder = new LoggerHolder();

        $ruleRegistry = self::createStub(RuleRegistryInterface::class);
        $ruleRegistry->method('getClasses')->willReturn([]);

        $this->configurator = new RuntimeConfigurator(
            $loggerFactory,
            $loggerHolder,
            new ProgressReporterHolder(),
            new ProfilerHolder(),
            $this->configProvider,
            $this->ruleOptionsRegistry,
            $ruleRegistry,
            new CacheFactory($this->configProvider),
            new ComputedMetricsConfigResolver(
                new ComputedMetricFormulaValidator(),
                new HealthFormulaExcluder(),
            ),
            $this->frameworkNamespacesHolder,
            $this->architectureProcessor,
        );

        return $loggerHolder;
    }

    private function createOutput(): OutputInterface
    {
        $output = self::createStub(OutputInterface::class);
        $output->method('isDecorated')->willReturn(false);
        $output->method('getVerbosity')->willReturn(OutputInterface::VERBOSITY_NORMAL);

        return $output;
    }
}
