<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Infrastructure\Console;

use AiMessDetector\Configuration\AnalysisConfiguration;
use AiMessDetector\Configuration\ComputedMetricsConfigResolver;
use AiMessDetector\Configuration\ConfigurationProviderInterface;
use AiMessDetector\Configuration\PathsConfiguration;
use AiMessDetector\Configuration\Pipeline\ResolvedConfiguration;
use AiMessDetector\Configuration\RuleOptionsFactory;
use AiMessDetector\Core\ComputedMetric\ComputedMetricDefinitionHolder;
use AiMessDetector\Core\Profiler\ProfilerHolder;
use AiMessDetector\Infrastructure\Cache\CacheFactory;
use AiMessDetector\Infrastructure\Console\Progress\ProgressReporterHolder;
use AiMessDetector\Infrastructure\Console\RuntimeConfigurator;
use AiMessDetector\Infrastructure\Logging\LoggerFactory;
use AiMessDetector\Infrastructure\Logging\LoggerHolder;
use AiMessDetector\Infrastructure\Rule\RuleRegistryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[CoversClass(RuntimeConfigurator::class)]
final class RuntimeConfiguratorTest extends TestCase
{
    private ConfigurationProviderInterface&MockObject $configProvider;
    private RuleOptionsFactory $ruleOptionsFactory;
    private RuntimeConfigurator $configurator;

    protected function setUp(): void
    {
        $loggerFactory = new LoggerFactory();

        $this->configProvider = $this->createMock(ConfigurationProviderInterface::class);
        $this->ruleOptionsFactory = new RuleOptionsFactory();

        $ruleRegistry = $this->createStub(RuleRegistryInterface::class);
        $ruleRegistry->method('getClasses')->willReturn([]);

        $this->configurator = new RuntimeConfigurator(
            $loggerFactory,
            new LoggerHolder(),
            new ProgressReporterHolder(),
            new ProfilerHolder(),
            $this->configProvider,
            $this->ruleOptionsFactory,
            $ruleRegistry,
            new CacheFactory($this->configProvider),
            new ComputedMetricsConfigResolver(),
        );
    }

    #[Test]
    public function itResetsCliOptionsBetweenConfigureCalls(): void
    {
        // First configure call: set CLI options
        $resolved1 = new ResolvedConfiguration(
            paths: PathsConfiguration::defaults(),
            analysis: new AnalysisConfiguration(),
            ruleOptions: [],
        );

        $input1 = $this->createCliInput([
            'complexity.cyclomatic:warningThreshold=50',
        ]);

        $this->configProvider
            ->expects($this->exactly(2))
            ->method('setConfiguration');
        $this->configProvider
            ->expects($this->exactly(2))
            ->method('setRuleOptions');

        $this->configurator->configure($resolved1, $input1, $this->createOutput());

        // Verify CLI options were set
        self::assertArrayHasKey('complexity.cyclomatic', $this->ruleOptionsFactory->getCliOptions());

        // Second configure call: no CLI options
        $resolved2 = new ResolvedConfiguration(
            paths: PathsConfiguration::defaults(),
            analysis: new AnalysisConfiguration(),
            ruleOptions: [],
        );

        $input2 = $this->createCliInput([]);

        $this->configurator->configure($resolved2, $input2, $this->createOutput());

        // CLI options from first run should not persist
        self::assertEmpty(
            $this->ruleOptionsFactory->getCliOptions(),
            'CLI options from first configure() call should not leak into second call',
        );
    }

    #[Test]
    public function itResetsNamespaceExclusionsBetweenConfigureCalls(): void
    {
        // First configure call: set exclude_namespaces via config
        $resolved1 = new ResolvedConfiguration(
            paths: PathsConfiguration::defaults(),
            analysis: new AnalysisConfiguration(),
            ruleOptions: [
                'coupling.cbo' => [
                    'exclude_namespaces' => ['App\\Tests'],
                ],
            ],
        );

        $input1 = $this->createCliInput([]);

        $this->configProvider
            ->expects($this->exactly(2))
            ->method('setConfiguration');
        $this->configProvider
            ->expects($this->exactly(2))
            ->method('setRuleOptions');

        $this->configurator->configure($resolved1, $input1, $this->createOutput());

        // Verify exclusions were set (create() is called lazily, so trigger it)
        // The factory stores config but doesn't call create() yet — exclusions are populated during create().
        // To verify the reset behavior, we manually check the provider after reset.
        $provider = $this->ruleOptionsFactory->getExclusionProvider();

        // Simulate what happens when create() populates the provider
        $provider->setExclusions('coupling.cbo', ['App\\Tests']);
        self::assertTrue($provider->isExcluded('coupling.cbo', 'App\\Tests'));

        // Second configure call: no exclude_namespaces
        $resolved2 = new ResolvedConfiguration(
            paths: PathsConfiguration::defaults(),
            analysis: new AnalysisConfiguration(),
            ruleOptions: [],
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
        );

        $input = $this->createCliInput([
            'complexity.cyclomatic:warningThreshold=15',
        ]);

        $this->configProvider
            ->expects($this->once())
            ->method('setRuleOptions')
            ->with($this->callback(function (array $options): bool {
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
        $resolved = new ResolvedConfiguration(
            paths: PathsConfiguration::defaults(),
            analysis: new AnalysisConfiguration(),
            ruleOptions: [
                'complexity.cyclomatic' => [
                    'warningThreshold' => 10,
                ],
            ],
        );

        $input = $this->createCliInput([
            'complexity.cyclomatic:countNullsafe=false',
        ]);

        $this->configProvider
            ->expects($this->once())
            ->method('setRuleOptions')
            ->with($this->callback(function (array $options): bool {
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
        $resolved = new ResolvedConfiguration(
            paths: PathsConfiguration::defaults(),
            analysis: new AnalysisConfiguration(),
            ruleOptions: [
                'complexity.cyclomatic' => [
                    'warningThreshold' => 10,
                    'errorThreshold' => 20,
                ],
            ],
        );

        $input = $this->createCliInput([
            'complexity.cyclomatic:warningThreshold=15',
            'complexity.cyclomatic:errorThreshold=30',
        ]);

        $this->configProvider
            ->expects($this->once())
            ->method('setRuleOptions')
            ->with($this->callback(function (array $options): bool {
                self::assertSame(15, $options['complexity.cyclomatic']['warningThreshold']);
                self::assertSame(30, $options['complexity.cyclomatic']['errorThreshold']);

                return true;
            }));

        $this->configurator->configure($resolved, $input, $this->createOutput());
    }

    #[Test]
    public function cliOptionsForNewRuleAreAddedAlongsideYamlRules(): void
    {
        $resolved = new ResolvedConfiguration(
            paths: PathsConfiguration::defaults(),
            analysis: new AnalysisConfiguration(),
            ruleOptions: [
                'complexity.cyclomatic' => [
                    'warningThreshold' => 10,
                ],
            ],
        );

        $input = $this->createCliInput([
            'size.class-count:warningThreshold=50',
        ]);

        $this->configProvider
            ->expects($this->once())
            ->method('setRuleOptions')
            ->with($this->callback(function (array $options): bool {
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
        $input = $this->createStub(InputInterface::class);
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

        return $input;
    }

    #[Test]
    public function excludeHealthFiltersDimensionsAndNormalizesOverall(): void
    {
        $resolved = new ResolvedConfiguration(
            paths: PathsConfiguration::defaults(),
            analysis: new AnalysisConfiguration(excludeHealth: ['typing']),
            ruleOptions: [],
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
    public function excludeHealthAcceptsHealthPrefixedNames(): void
    {
        $resolved = new ResolvedConfiguration(
            paths: PathsConfiguration::defaults(),
            analysis: new AnalysisConfiguration(excludeHealth: ['health.complexity', 'cohesion']),
            ruleOptions: [],
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

    private function createOutput(): OutputInterface
    {
        $output = $this->createStub(OutputInterface::class);
        $output->method('isDecorated')->willReturn(false);
        $output->method('getVerbosity')->willReturn(OutputInterface::VERBOSITY_NORMAL);

        return $output;
    }
}
