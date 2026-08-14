<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalResolvedConfiguration;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalRuntimeConfiguration;
use Qualimetrix\Analysis\Configuration\Contract\TransitionalRuntimeConfigurationProviderInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricAnalysis;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricFormulaValidator;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricsConfigResolver;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Configuration\ComputedMetricContributionReader;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Configuration\HealthFormulaExcluder;
use Qualimetrix\Analysis\Evidence\Coupling\CouplingAnalysis;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CollectorRuntimeConfigurableInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CollectorRuntimeConfiguration;
use Qualimetrix\Analysis\Evidence\Measurement\Runtime\CollectorRuntimeConfigurationStore;
use Qualimetrix\Analysis\Finding\Contract\RuleExclusionCaptureHolder;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Analysis\Policy\Architecture\ArchitecturePolicy;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitecturePolicyConfiguratorInterface;
use Qualimetrix\Core\Profiler\ProfilerHolder;
use Qualimetrix\Infrastructure\Cache\CacheFactory;
use Qualimetrix\Infrastructure\Console\AnalysisRuntimeConfigurator;
use Qualimetrix\Infrastructure\Console\DiagnosticOutput;
use Qualimetrix\Infrastructure\Console\Progress\ProgressReporterHolder;
use Qualimetrix\Infrastructure\Console\RuntimeConfigurator;
use Qualimetrix\Infrastructure\Console\RuntimeLoggerConfigurator;
use Qualimetrix\Infrastructure\Logging\LoggerFactory;
use Qualimetrix\Infrastructure\Logging\LoggerHolder;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

#[CoversClass(RuntimeConfigurator::class)]
#[CoversClass(RuntimeLoggerConfigurator::class)]
#[CoversClass(AnalysisRuntimeConfigurator::class)]
#[CoversClass(HealthFormulaExcluder::class)]
#[CoversClass(ArchitecturePolicy::class)]
#[CoversClass(CouplingAnalysis::class)]
final class RuntimeConfiguratorTest extends TestCase
{
    private TransitionalRuntimeConfigurationProviderInterface&Stub $configProvider;
    private RuleOptionsRegistry $ruleOptionsRegistry;
    private CouplingAnalysis $couplingAnalysis;
    private ArchitecturePolicyConfiguratorInterface $architecturePolicy;
    private CollectorRuntimeConfigurationStore $collectorRuntimeConfigurationStore;
    private CacheFactory $cacheFactory;
    private RuntimeConfigurator $configurator;
    private ComputedMetricAnalysis $computedMetricAnalysis;

    protected function setUp(): void
    {
        $this->ruleOptionsRegistry = new RuleOptionsRegistry();
        $this->couplingAnalysis = new CouplingAnalysis();
        $this->architecturePolicy = new ArchitecturePolicy();
        $this->collectorRuntimeConfigurationStore = new CollectorRuntimeConfigurationStore();

        $this->configProvider = self::createStub(TransitionalRuntimeConfigurationProviderInterface::class);
        $this->cacheFactory = new CacheFactory($this->configProvider);
        $this->configurator = $this->buildConfigurator($this->configProvider);
    }

    protected function tearDown(): void
    {
        RuleExclusionCaptureHolder::reset();
    }

    /**
     * Replaces the default stub with a mock and rebuilds the configurator.
     *
     * Call this in tests that need `expects()` on the config provider.
     */
    private function useConfigProviderMock(): TransitionalRuntimeConfigurationProviderInterface&MockObject
    {
        $mock = $this->createMock(TransitionalRuntimeConfigurationProviderInterface::class);
        $mock->method('getConfiguration')->willReturn(new TransitionalRuntimeConfiguration());
        $this->configProvider = $mock;
        $this->cacheFactory = new CacheFactory($mock);
        $this->configurator = $this->buildConfigurator($mock);

        return $mock;
    }

    private function buildConfigurator(TransitionalRuntimeConfigurationProviderInterface $configProvider): RuntimeConfigurator
    {
        $loggerFactory = new LoggerFactory();
        $loggerHolder = new LoggerHolder();

        $ruleRegistry = self::createStub(RuleRegistryInterface::class);
        $ruleRegistry->method('getClasses')->willReturn([]);

        $this->computedMetricAnalysis = new ComputedMetricAnalysis(
            new ComputedMetricsConfigResolver(new ComputedMetricFormulaValidator(), new HealthFormulaExcluder()),
            new ComputedMetricContributionReader(),
        );

        return new RuntimeConfigurator(
            new RuntimeLoggerConfigurator($loggerFactory, $loggerHolder),
            new ProgressReporterHolder(),
            new ProfilerHolder(),
            new AnalysisRuntimeConfigurator(
                $configProvider,
                $this->ruleOptionsRegistry,
                $ruleRegistry,
                $this->collectorRuntimeConfigurationStore,
                $this->cacheFactory,
            ),
            $this->architecturePolicy,
            $this->computedMetricAnalysis,
            $this->couplingAnalysis,
            new DiagnosticOutput(),
        );
    }

    #[Test]
    public function itResetsCliOptionsBetweenConfigureCalls(): void
    {
        $configProvider = $this->useConfigProviderMock();

        // First configure call: set CLI options
        $resolved1 = new TransitionalResolvedConfiguration(
            paths: ["."],
            pathExcludes: ["vendor", "node_modules", ".git"],
            runtime: new TransitionalRuntimeConfiguration(),
            ruleOptions: [],
            document: new ConfigurationDocument([]),
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

        $cacheBeforeFirstConfigure = $this->cacheFactory->create();
        $collector = new class ($this->cacheFactory, $cacheBeforeFirstConfigure) implements CollectorRuntimeConfigurableInterface {
            /** @var list<bool> */
            public array $cacheWasResetBeforeApply = [];

            public function __construct(
                private readonly CacheFactory $cacheFactory,
                private object $previousCache,
            ) {}

            public function applyRuntimeConfiguration(CollectorRuntimeConfiguration $configuration): void
            {
                $this->cacheWasResetBeforeApply[] = $this->cacheFactory->create() !== $this->previousCache;
            }

            public function expectResetFrom(object $cache): void
            {
                $this->previousCache = $cache;
            }
        };
        $this->collectorRuntimeConfigurationStore = new CollectorRuntimeConfigurationStore([$collector]);
        $this->configurator = $this->buildConfigurator($configProvider);

        $this->configurator->configure($resolved1, $input1, $this->createOutput());

        $cacheAfterFirstConfigure = $this->cacheFactory->create();
        self::assertNotSame($cacheBeforeFirstConfigure, $cacheAfterFirstConfigure);
        self::assertTrue($collector->cacheWasResetBeforeApply[0]);

        // Verify CLI options were set
        self::assertArrayHasKey('complexity.cyclomatic', $this->ruleOptionsRegistry->getCliOptions());

        // Second configure call: no CLI options
        $resolved2 = new TransitionalResolvedConfiguration(
            paths: ["."],
            pathExcludes: ["vendor", "node_modules", ".git"],
            runtime: new TransitionalRuntimeConfiguration(),
            ruleOptions: [],
            document: new ConfigurationDocument([]),
        );

        $input2 = $this->createCliInput([]);

        $collector->expectResetFrom($cacheAfterFirstConfigure);
        $this->configurator->configure($resolved2, $input2, $this->createOutput());

        self::assertNotSame($cacheAfterFirstConfigure, $this->cacheFactory->create());
        self::assertTrue($collector->cacheWasResetBeforeApply[2]);

        // CLI options from first run should not persist
        self::assertEmpty(
            $this->ruleOptionsRegistry->getCliOptions(),
            'CLI options from first configure() call should not leak into second call',
        );
    }

    #[Test]
    public function itResetsNamespaceExclusionsBetweenConfigureCalls(): void
    {
        $configProvider = $this->useConfigProviderMock();

        // First configure call: set exclude_namespaces via config
        $resolved1 = new TransitionalResolvedConfiguration(
            paths: ["."],
            pathExcludes: ["vendor", "node_modules", ".git"],
            runtime: new TransitionalRuntimeConfiguration(),
            ruleOptions: [
                'coupling.cbo' => [
                    'exclude_namespaces' => ['App\\Tests'],
                ],
            ],
            document: new ConfigurationDocument([]),
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
        // Simulate what happens when create() populates the provider
        $this->ruleOptionsRegistry->configureNamespaceExclusions('coupling.cbo', ['App\\Tests']);
        self::assertTrue($this->ruleOptionsRegistry->isNamespaceExcluded('coupling.cbo', 'App\\Tests'));

        // Second configure call: no exclude_namespaces
        $resolved2 = new TransitionalResolvedConfiguration(
            paths: ["."],
            pathExcludes: ["vendor", "node_modules", ".git"],
            runtime: new TransitionalRuntimeConfiguration(),
            ruleOptions: [],
            document: new ConfigurationDocument([]),
        );

        $input2 = $this->createCliInput([]);

        $this->configurator->configure($resolved2, $input2, $this->createOutput());

        // Exclusions from first run should not persist
        self::assertFalse(
            $this->ruleOptionsRegistry->isNamespaceExcluded('coupling.cbo', 'App\\Tests'),
            'Namespace exclusions from first configure() call should not leak into second call',
        );
    }

    #[Test]
    public function cliOptionOverridesOnlySpecificKeysPreservingYamlOptions(): void
    {
        $configProvider = $this->useConfigProviderMock();

        $resolved = new TransitionalResolvedConfiguration(
            paths: ["."],
            pathExcludes: ["vendor", "node_modules", ".git"],
            runtime: new TransitionalRuntimeConfiguration(),
            ruleOptions: [
                'complexity.cyclomatic' => [
                    'warningThreshold' => 10,
                    'errorThreshold' => 20,
                    'enabled' => true,
                ],
            ],
            document: new ConfigurationDocument([]),
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

        $resolved = new TransitionalResolvedConfiguration(
            paths: ["."],
            pathExcludes: ["vendor", "node_modules", ".git"],
            runtime: new TransitionalRuntimeConfiguration(),
            ruleOptions: [
                'complexity.cyclomatic' => [
                    'warningThreshold' => 10,
                ],
            ],
            document: new ConfigurationDocument([]),
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

        $resolved = new TransitionalResolvedConfiguration(
            paths: ["."],
            pathExcludes: ["vendor", "node_modules", ".git"],
            runtime: new TransitionalRuntimeConfiguration(),
            ruleOptions: [
                'complexity.cyclomatic' => [
                    'warningThreshold' => 10,
                    'errorThreshold' => 20,
                ],
            ],
            document: new ConfigurationDocument([]),
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

        $resolved = new TransitionalResolvedConfiguration(
            paths: ["."],
            pathExcludes: ["vendor", "node_modules", ".git"],
            runtime: new TransitionalRuntimeConfiguration(),
            ruleOptions: [
                'complexity.cyclomatic' => [
                    'warningThreshold' => 10,
                ],
            ],
            document: new ConfigurationDocument([]),
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
    public function itDisablesRuleExclusionCaptureByDefault(): void
    {
        RuleExclusionCaptureHolder::set(true); // simulate a leftover from a previous run

        $resolved = new TransitionalResolvedConfiguration(
            paths: ["."],
            pathExcludes: ["vendor", "node_modules", ".git"],
            runtime: new TransitionalRuntimeConfiguration(),
            ruleOptions: [],
            document: new ConfigurationDocument([]),
        );

        $this->configurator->configure($resolved, $this->createCliInput([]), $this->createOutput());

        self::assertFalse(RuleExclusionCaptureHolder::isEnabled());
    }

    #[Test]
    public function itEnablesRuleExclusionCaptureFromShowSuppressedOption(): void
    {
        $resolved = new TransitionalResolvedConfiguration(
            paths: ["."],
            pathExcludes: ["vendor", "node_modules", ".git"],
            runtime: new TransitionalRuntimeConfiguration(),
            ruleOptions: [],
            document: new ConfigurationDocument([]),
        );

        $input = self::createStub(InputInterface::class);
        $input->method('hasOption')->willReturn(true);
        $input->method('getOption')->willReturnCallback(
            static fn(string $name): mixed => match ($name) {
                'show-suppressed' => true,
                'no-progress' => false,
                'profile' => false,
                default => null,
            },
        );

        $this->configurator->configure($resolved, $input, $this->createOutput());

        self::assertTrue(RuleExclusionCaptureHolder::isEnabled());
    }

    #[Test]
    public function itDisablesRuleExclusionCaptureWhenCommandDoesNotExposeTheOption(): void
    {
        RuleExclusionCaptureHolder::set(true); // simulate a leftover from a previous run

        $resolved = new TransitionalResolvedConfiguration(
            paths: ["."],
            pathExcludes: ["vendor", "node_modules", ".git"],
            runtime: new TransitionalRuntimeConfiguration(),
            ruleOptions: [],
            document: new ConfigurationDocument([]),
        );

        // Mirrors commands like `debug:layer-assignment` that reuse this
        // configurator but don't expose `--show-suppressed`.
        $input = self::createStub(InputInterface::class);
        $input->method('hasOption')->willReturn(false);
        $input->method('getOption')->willReturn(null);

        $this->configurator->configure($resolved, $input, $this->createOutput());

        self::assertFalse(RuleExclusionCaptureHolder::isEnabled());
    }

    #[Test]
    public function itConfiguresFrameworkNamespacesFromTheDocument(): void
    {
        $resolved = new TransitionalResolvedConfiguration(
            paths: ["."],
            pathExcludes: ["vendor", "node_modules", ".git"],
            runtime: new TransitionalRuntimeConfiguration(),
            ruleOptions: [],
            document: new ConfigurationDocument([["coupling" => ["frameworkNamespaces" => ['Symfony', 'Doctrine']]]]),
        );

        $input = $this->createCliInput([]);

        $this->configurator->configure($resolved, $input, $this->createOutput());

        self::assertFalse($this->couplingAnalysis->isEmpty());
        self::assertTrue($this->couplingAnalysis->isFramework('Symfony\\Component\\Console'));
        self::assertTrue($this->couplingAnalysis->isFramework('Doctrine\\ORM\\EntityManager'));
        self::assertFalse($this->couplingAnalysis->isFramework('App\\Service\\UserService'));
    }

    #[Test]
    public function itReplacesFrameworkNamespacesWithAnEmptyDocumentBetweenRuns(): void
    {
        $configProvider = $this->useConfigProviderMock();

        // First configure: set framework namespaces
        $resolved1 = new TransitionalResolvedConfiguration(
            paths: ["."],
            pathExcludes: ["vendor", "node_modules", ".git"],
            runtime: new TransitionalRuntimeConfiguration(),
            ruleOptions: [],
            document: new ConfigurationDocument([["coupling" => ["frameworkNamespaces" => ['Symfony']]]]),
        );

        $configProvider
            ->expects($this->exactly(2))
            ->method('setConfiguration');
        $configProvider
            ->expects($this->exactly(2))
            ->method('setRuleOptions');

        $this->configurator->configure($resolved1, $this->createCliInput([]), $this->createOutput());

        self::assertFalse($this->couplingAnalysis->isEmpty());
        self::assertTrue($this->couplingAnalysis->isFramework('Symfony\\Console'));

        // Second configure: no framework namespaces
        $resolved2 = new TransitionalResolvedConfiguration(
            paths: ["."],
            pathExcludes: ["vendor", "node_modules", ".git"],
            runtime: new TransitionalRuntimeConfiguration(),
            ruleOptions: [],
            document: new ConfigurationDocument([]),
        );

        $this->configurator->configure($resolved2, $this->createCliInput([]), $this->createOutput());

        // Framework namespaces from first run should be cleared
        self::assertTrue($this->couplingAnalysis->isEmpty());
    }

    #[Test]
    public function excludeHealthFiltersDimensionsAndNormalizesOverall(): void
    {
        $resolved = new TransitionalResolvedConfiguration(
            paths: ["."],
            pathExcludes: ["vendor", "node_modules", ".git"],
            runtime: new TransitionalRuntimeConfiguration(),
            ruleOptions: [],
            document: new ConfigurationDocument([['excludeHealth' => ['typing']]]),
        );

        $input = $this->createCliInput([]);

        $this->configurator->configure($resolved, $input, $this->createOutput());

        $definitions = $this->computedMetricAnalysis->all();
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
        $resolved = new TransitionalResolvedConfiguration(
            paths: ["."],
            pathExcludes: ["vendor", "node_modules", ".git"],
            runtime: new TransitionalRuntimeConfiguration(),
            ruleOptions: [
                'design.lcom' => [
                    'exclude_methods' => ['getName', 'getDescription'],
                ],
            ],
            document: new ConfigurationDocument([]),
        );

        $this->configurator->configure($resolved, $this->createCliInput([]), $this->createOutput());

        self::assertSame(
            ['getName', 'getDescription'],
            $this->collectorRuntimeConfigurationStore->current()->lcomExcludedMethods,
        );
    }

    #[Test]
    public function configureCollectorsSetsExcludeMethodsFromCommaSeparatedString(): void
    {
        $resolved = new TransitionalResolvedConfiguration(
            paths: ["."],
            pathExcludes: ["vendor", "node_modules", ".git"],
            runtime: new TransitionalRuntimeConfiguration(),
            ruleOptions: [
                'design.lcom' => [
                    'exclude_methods' => 'getName, getDescription',
                ],
            ],
            document: new ConfigurationDocument([]),
        );

        $this->configurator->configure($resolved, $this->createCliInput([]), $this->createOutput());

        self::assertSame(
            ['getName', 'getDescription'],
            $this->collectorRuntimeConfigurationStore->current()->lcomExcludedMethods,
        );
    }

    #[Test]
    public function configureCollectorsSetsExcludeMethodsFromSingleString(): void
    {
        $resolved = new TransitionalResolvedConfiguration(
            paths: ["."],
            pathExcludes: ["vendor", "node_modules", ".git"],
            runtime: new TransitionalRuntimeConfiguration(),
            ruleOptions: [
                'design.lcom' => [
                    'exclude_methods' => 'getName',
                ],
            ],
            document: new ConfigurationDocument([]),
        );

        $this->configurator->configure($resolved, $this->createCliInput([]), $this->createOutput());

        self::assertSame(
            ['getName'],
            $this->collectorRuntimeConfigurationStore->current()->lcomExcludedMethods,
        );
    }

    #[Test]
    public function configureCollectorsSkipsWhenNoExcludeMethods(): void
    {
        $resolved = new TransitionalResolvedConfiguration(
            paths: ["."],
            pathExcludes: ["vendor", "node_modules", ".git"],
            runtime: new TransitionalRuntimeConfiguration(),
            ruleOptions: [
                'design.lcom' => [
                    'warning' => 5,
                ],
            ],
            document: new ConfigurationDocument([]),
        );

        $this->configurator->configure($resolved, $this->createCliInput([]), $this->createOutput());

        self::assertSame([], $this->collectorRuntimeConfigurationStore->current()->lcomExcludedMethods);
    }

    #[Test]
    public function excludeHealthAcceptsHealthPrefixedNames(): void
    {
        $resolved = new TransitionalResolvedConfiguration(
            paths: ["."],
            pathExcludes: ["vendor", "node_modules", ".git"],
            runtime: new TransitionalRuntimeConfiguration(),
            ruleOptions: [],
            document: new ConfigurationDocument([['excludeHealth' => ['health.complexity', 'cohesion']]]),
        );

        $input = $this->createCliInput([]);

        $this->configurator->configure($resolved, $input, $this->createOutput());

        $definitions = $this->computedMetricAnalysis->all();
        $names = array_map(static fn($d) => $d->name, $definitions);

        self::assertNotContains('health.complexity', $names);
        self::assertNotContains('health.cohesion', $names);
        self::assertContains('health.coupling', $names);
        self::assertContains('health.typing', $names);
    }

    // -------------------------------------------------------------------------
    // Architecture warning logging
    // -------------------------------------------------------------------------

    #[Test]
    public function architectureWarningsAreLoggedThroughConfiguredLogger(): void
    {
        // Architecture configures only after LoggerHolder carries the
        // user-facing logger, so its warnings are logged immediately.

        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $loggerHolder = $this->buildConfiguratorWithBufferedOutput();

        $resolved = new TransitionalResolvedConfiguration(
            paths: ["."],
            pathExcludes: ["vendor", "node_modules", ".git"],
            runtime: new TransitionalRuntimeConfiguration(),
            ruleOptions: [],
            document: new ConfigurationDocument([['architecture' => [
                'layers' => [['name' => 'domain-orders', 'patterns' => ['App\\Domain\\Orders\\**']]],
                'allow' => ['domain-*' => ['domain-*']],
            ]]]),
        );

        $this->configurator->configure($resolved, $this->createCliInput([]), self::diagnosticConsole($output));

        // The buffered ConsoleLogger emits warnings at VERBOSITY_NORMAL with a
        // <comment> tag, so plain text should be visible regardless of decoration.
        $rendered = $output->fetch();
        self::assertStringContainsString('wildcard-self-allow detected', $rendered);
        self::assertStringContainsString("'domain-*'", $rendered);

        // Sanity: the logger that received the warning is the one in the holder,
        // proving Architecture configures after configureLogger swaps it in.
        self::assertNotInstanceOf(\Psr\Log\NullLogger::class, $loggerHolder->getLogger());
    }

    #[Test]
    public function emptyArchitectureDocumentProducesNoLogOutput(): void
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $this->buildConfiguratorWithBufferedOutput();

        $resolved = new TransitionalResolvedConfiguration(
            paths: ["."],
            pathExcludes: ["vendor", "node_modules", ".git"],
            runtime: new TransitionalRuntimeConfiguration(),
            ruleOptions: [],
            document: new ConfigurationDocument([]),
        );

        $this->configurator->configure($resolved, $this->createCliInput([]), self::diagnosticConsole($output));

        $rendered = $output->fetch();
        self::assertSame('', $rendered, 'No warnings should be logged for an empty architecture document');
    }

    #[Test]
    public function architectureWarningsUseWarningLevel(): void
    {
        // Architecture warnings have one contract level: warning. ConsoleLogger
        // renders that level with the warning prefix and never as an error.

        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true);
        $this->buildConfiguratorWithBufferedOutput();

        $resolved = new TransitionalResolvedConfiguration(
            paths: ["."],
            pathExcludes: ["vendor", "node_modules", ".git"],
            runtime: new TransitionalRuntimeConfiguration(),
            ruleOptions: [],
            document: new ConfigurationDocument([['architecture' => [
                'layers' => [['name' => 'domain-orders', 'patterns' => ['App\\Domain\\Orders\\**']]],
                'allow' => ['domain-*' => ['domain-*']],
            ]]]),
        );

        $this->configurator->configure($resolved, $this->createCliInput([]), self::diagnosticConsole($output));

        $rendered = $output->fetch();
        self::assertStringContainsString('[WARNING]', $rendered);
        self::assertStringContainsString('wildcard-self-allow', $rendered);
        self::assertStringNotContainsString('[ERROR]', $rendered);
    }

    /**
     * Wires the test configurator with a BufferedOutput-friendly LoggerFactory
     * and returns the LoggerHolder so the test can inspect it after configure().
     */
    private function buildConfiguratorWithBufferedOutput(): LoggerHolder
    {
        // The real LoggerFactory honors verbosity, so a VERBOSITY_NORMAL
        // BufferedOutput will produce a ConsoleLogger that writes warnings to
        // the buffer (default level == WARNING).
        $loggerFactory = new LoggerFactory();
        $loggerHolder = new LoggerHolder();

        $ruleRegistry = self::createStub(RuleRegistryInterface::class);
        $ruleRegistry->method('getClasses')->willReturn([]);

        $this->computedMetricAnalysis = new ComputedMetricAnalysis(
            new ComputedMetricsConfigResolver(new ComputedMetricFormulaValidator(), new HealthFormulaExcluder()),
            new ComputedMetricContributionReader(),
        );

        $this->configurator = new RuntimeConfigurator(
            new RuntimeLoggerConfigurator($loggerFactory, $loggerHolder),
            new ProgressReporterHolder(),
            new ProfilerHolder(),
            new AnalysisRuntimeConfigurator(
                $this->configProvider,
                $this->ruleOptionsRegistry,
                $ruleRegistry,
                $this->collectorRuntimeConfigurationStore,
                $this->cacheFactory,
            ),
            $this->architecturePolicy,
            $this->computedMetricAnalysis,
            $this->couplingAnalysis,
            new DiagnosticOutput(),
        );

        return $loggerHolder;
    }

    private static function diagnosticConsole(BufferedOutput $diagnostics): ConsoleOutput
    {
        $output = new ConsoleOutput($diagnostics->getVerbosity(), false);
        $output->setErrorOutput($diagnostics);

        return $output;
    }

    private function createOutput(): OutputInterface
    {
        $output = self::createStub(OutputInterface::class);
        $output->method('isDecorated')->willReturn(false);
        $output->method('getVerbosity')->willReturn(OutputInterface::VERBOSITY_NORMAL);

        return $output;
    }
}
