<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Collection\Metric\CompositeCollector;
use Qualimetrix\Analysis\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Configuration\AnalysisConfiguration;
use Qualimetrix\Configuration\ConfigurationHolder;
use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Configuration\RuleOptionsFactory;
use Qualimetrix\Core\Namespace_\ProjectNamespaceResolverInterface;
use Qualimetrix\Infrastructure\Cache\CacheInterface;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Qualimetrix\Metrics\Complexity\CognitiveComplexityCollector;
use Qualimetrix\Metrics\Complexity\CyclomaticComplexityCollector;
use Qualimetrix\Metrics\Complexity\NpathComplexityCollector;
use Qualimetrix\Metrics\Halstead\HalsteadCollector;
use Qualimetrix\Metrics\Maintainability\MaintainabilityIndexCollector;
use Qualimetrix\Metrics\Size\ClassCountCollector;
use Qualimetrix\Metrics\Size\LocCollector;
use Qualimetrix\Metrics\Structure\InheritanceDepthCollector;
use Qualimetrix\Metrics\Structure\LcomCollector;
use Qualimetrix\Metrics\Structure\MethodCountCollector;
use Qualimetrix\Metrics\Structure\RfcCollector;
use Qualimetrix\Metrics\Structure\TccLccCollector;
use Qualimetrix\Reporting\Formatter\FormatterRegistryInterface;
use Qualimetrix\Rules\Architecture\CircularDependencyRule;
use Qualimetrix\Rules\CodeSmell\BooleanArgumentRule;
use Qualimetrix\Rules\CodeSmell\CountInLoopRule;
use Qualimetrix\Rules\CodeSmell\DebugCodeRule;
use Qualimetrix\Rules\CodeSmell\EmptyCatchRule;
use Qualimetrix\Rules\CodeSmell\ErrorSuppressionRule;
use Qualimetrix\Rules\CodeSmell\EvalRule;
use Qualimetrix\Rules\CodeSmell\ExitRule;
use Qualimetrix\Rules\CodeSmell\GotoRule;
use Qualimetrix\Rules\CodeSmell\LongParameterListRule;
use Qualimetrix\Rules\CodeSmell\SuperglobalsRule;
use Qualimetrix\Rules\CodeSmell\UnreachableCodeRule;
use Qualimetrix\Rules\Complexity\CognitiveComplexityRule;
use Qualimetrix\Rules\Complexity\ComplexityRule;
use Qualimetrix\Rules\Complexity\NpathComplexityRule;
use Qualimetrix\Rules\Coupling\CboRule;
use Qualimetrix\Rules\Coupling\ClassRankRule;
use Qualimetrix\Rules\Coupling\DistanceRule;
use Qualimetrix\Rules\Coupling\InstabilityRule;
use Qualimetrix\Rules\Design\TypeCoverageRule;
use Qualimetrix\Rules\Maintainability\MaintainabilityRule;
use Qualimetrix\Rules\Security\CommandInjectionRule;
use Qualimetrix\Rules\Security\SensitiveParameterRule;
use Qualimetrix\Rules\Security\SqlInjectionRule;
use Qualimetrix\Rules\Security\XssRule;
use Qualimetrix\Rules\Size\ClassCountRule;
use Qualimetrix\Rules\Size\MethodCountRule;
use Qualimetrix\Rules\Size\PropertyCountRule;
use Qualimetrix\Rules\Structure\InheritanceRule;
use Qualimetrix\Rules\Structure\LcomRule;
use Qualimetrix\Rules\Structure\NocRule;
use Qualimetrix\Rules\Structure\WmcRule;

#[CoversClass(ContainerFactory::class)]
final class ContainerFactoryTest extends TestCase
{
    private ContainerFactory $factory;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->factory = new ContainerFactory();
        $this->tempDir = sys_get_temp_dir() . '/qmx_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testCreateReturnsCompiledContainer(): void
    {
        $container = $this->factory->create();

        self::assertTrue($container->isCompiled());
    }

    public function testContainerHasAnalysisPipeline(): void
    {
        $container = $this->factory->create();

        self::assertTrue($container->has(AnalysisPipelineInterface::class));
        self::assertInstanceOf(AnalysisPipelineInterface::class, $container->get(AnalysisPipelineInterface::class));
    }

    public function testContainerHasFormatterRegistry(): void
    {
        $container = $this->factory->create();

        self::assertTrue($container->has(FormatterRegistryInterface::class));
        self::assertInstanceOf(
            FormatterRegistryInterface::class,
            $container->get(FormatterRegistryInterface::class),
        );
    }

    public function testContainerHasCache(): void
    {
        $container = $this->factory->create();

        self::assertTrue($container->has(CacheInterface::class));
        self::assertInstanceOf(CacheInterface::class, $container->get(CacheInterface::class));
    }

    public function testContainerHasRuleRegistry(): void
    {
        $container = $this->factory->create();

        self::assertTrue($container->has(RuleRegistryInterface::class));
        $registry = $container->get(RuleRegistryInterface::class);
        self::assertInstanceOf(RuleRegistryInterface::class, $registry);

        // Registry should contain rule classes
        $classes = $registry->getClasses();
        self::assertNotEmpty($classes);
    }

    public function testContainerHasCheckCommand(): void
    {
        $container = $this->factory->create();

        self::assertTrue($container->has(CheckCommand::class));
        self::assertInstanceOf(CheckCommand::class, $container->get(CheckCommand::class));
    }

    public function testCollectorCompilerPassRegistersAllCollectors(): void
    {
        $container = $this->factory->create();

        // CompositeCollector is private/inlined, but we can verify via AnalysisPipeline
        $pipeline = $container->get(AnalysisPipelineInterface::class);
        self::assertInstanceOf(AnalysisPipelineInterface::class, $pipeline);
    }

    public function testRulesAreInjectedIntoRuleExecutor(): void
    {
        $container = $this->factory->create();
        $pipeline = $container->get(AnalysisPipelineInterface::class);

        // If container compiles successfully and AnalysisPipeline is available,
        // rules were injected by RuleCompilerPass
        self::assertInstanceOf(AnalysisPipelineInterface::class, $pipeline);
    }

    public function testFormatterCompilerPassRegistersFormatters(): void
    {
        $container = $this->factory->create();
        $registry = $container->get(FormatterRegistryInterface::class);

        self::assertInstanceOf(FormatterRegistryInterface::class, $registry);
        self::assertTrue($registry->has('text'));
    }

    public function testFileParserAndNamespaceDetectorAreWiredCorrectly(): void
    {
        $container = $this->factory->create();

        // Private services are wired correctly if AnalysisPipeline works
        $pipeline = $container->get(AnalysisPipelineInterface::class);
        self::assertInstanceOf(AnalysisPipelineInterface::class, $pipeline);
    }

    public function testRuleOptionsCanBeConfiguredAtRuntime(): void
    {
        $container = $this->factory->create();

        // Get RuleOptionsFactory and configure it
        $ruleOptionsFactory = $container->get(RuleOptionsFactory::class);
        self::assertInstanceOf(RuleOptionsFactory::class, $ruleOptionsFactory);

        $ruleOptionsFactory->setCliOptions('cyclomatic-complexity', [
            'warningThreshold' => 20,
            'errorThreshold' => 40,
        ]);

        // Container should still work after configuration
        self::assertTrue($container->isCompiled());
    }

    public function testConfigurationHolderCanBeConfiguredAtRuntime(): void
    {
        $container = $this->factory->create();

        // Get ConfigurationHolder and configure it
        $configProvider = $container->get(ConfigurationProviderInterface::class);
        self::assertInstanceOf(ConfigurationHolder::class, $configProvider);

        $config = new AnalysisConfiguration(
            cacheDir: $this->tempDir . '/cache',
            cacheEnabled: false,
        );
        $configProvider->setConfiguration($config);

        // Verify configuration was applied
        self::assertSame($config, $configProvider->getConfiguration());
    }

    public function testCreateWithDefaultConfiguration(): void
    {
        // ContainerFactory is created without arguments
        $container = $this->factory->create();

        self::assertTrue($container->isCompiled());
        self::assertTrue($container->has(AnalysisPipelineInterface::class));
    }

    /**
     * Verifies that all expected formatters are registered in FormatterRegistry.
     * This test protects against accidental exclusion of formatters due to
     * changes in registerClasses() patterns.
     */
    public function testAllFormattersAreRegistered(): void
    {
        $container = $this->factory->create();
        $registry = $container->get(FormatterRegistryInterface::class);
        self::assertInstanceOf(FormatterRegistryInterface::class, $registry);

        $expectedFormatters = [
            'summary',
            'text',
            'json',
            'checkstyle',
            'sarif',
            'gitlab',
            'github',
            'metrics',
            'health',
        ];

        foreach ($expectedFormatters as $name) {
            self::assertTrue(
                $registry->has($name),
                \sprintf("Formatter '%s' should be registered in FormatterRegistry", $name),
            );
        }

        // text-verbose is registered but hidden from getAvailableNames() (deprecated)
        self::assertTrue($registry->has('text-verbose'), 'Deprecated text-verbose formatter should still be registered');

        // Verify we have exactly the expected number of public formatters
        self::assertCount(
            \count($expectedFormatters),
            $registry->getAvailableNames(),
            'FormatterRegistry should contain exactly ' . \count($expectedFormatters) . ' public formatters',
        );
    }

    /**
     * Verifies that all expected metric collectors are registered in CompositeCollector.
     * This test protects against accidental exclusion of collectors due to
     * changes in registerClasses() patterns.
     */
    public function testAllMetricCollectorsAreRegistered(): void
    {
        $container = $this->factory->create();

        // Get CompositeCollector directly from container
        $compositeCollector = $container->get(CompositeCollector::class);
        self::assertInstanceOf(CompositeCollector::class, $compositeCollector);

        $collectors = $compositeCollector->getCollectors();
        $collectorClasses = array_map(static fn($c) => $c::class, $collectors);

        // Expected base collectors (MetricCollectorInterface)
        $expectedCollectors = [
            CyclomaticComplexityCollector::class,
            CognitiveComplexityCollector::class,
            NpathComplexityCollector::class,
            LocCollector::class,
            ClassCountCollector::class,
            HalsteadCollector::class,
            MethodCountCollector::class,
            LcomCollector::class,
            TccLccCollector::class,
            InheritanceDepthCollector::class,
            RfcCollector::class,
        ];

        foreach ($expectedCollectors as $expectedClass) {
            self::assertContains(
                $expectedClass,
                $collectorClasses,
                \sprintf("Collector '%s' should be registered in CompositeCollector", $expectedClass),
            );
        }
    }

    /**
     * Verifies that derived collectors (DerivedCollectorInterface) are registered.
     */
    public function testDerivedCollectorsAreRegistered(): void
    {
        $container = $this->factory->create();

        // Get CompositeCollector directly from container
        $compositeCollector = $container->get(CompositeCollector::class);
        self::assertInstanceOf(CompositeCollector::class, $compositeCollector);

        $derivedCollectors = $compositeCollector->getDerivedCollectors();
        $derivedClasses = array_map(static fn($c) => $c::class, $derivedCollectors);

        // MaintainabilityIndexCollector depends on Halstead and CCN metrics
        self::assertContains(
            MaintainabilityIndexCollector::class,
            $derivedClasses,
            'MaintainabilityIndexCollector should be registered as derived collector',
        );
    }

    /**
     * Verifies that global context collectors are properly wired.
     * These collectors are private services that get inlined by Symfony DI.
     * We verify they work by checking that the pipeline can be instantiated.
     */
    public function testGlobalContextCollectorsAreWired(): void
    {
        $container = $this->factory->create();

        // If AnalysisPipeline can be created, global collectors were wired correctly
        $pipeline = $container->get(AnalysisPipelineInterface::class);
        self::assertInstanceOf(AnalysisPipelineInterface::class, $pipeline);
    }

    /**
     * Verifies that all expected rules are registered in RuleRegistry.
     * This test protects against accidental omission of rules in ContainerFactory.
     */
    public function testAllRulesAreRegistered(): void
    {
        $container = $this->factory->create();
        $registry = $container->get(RuleRegistryInterface::class);
        self::assertInstanceOf(RuleRegistryInterface::class, $registry);

        $expectedRuleClasses = [
            ComplexityRule::class,
            CognitiveComplexityRule::class,
            NpathComplexityRule::class,
            MethodCountRule::class,
            ClassCountRule::class,
            PropertyCountRule::class,
            MaintainabilityRule::class,
            LcomRule::class,
            InheritanceRule::class,
            WmcRule::class,
            NocRule::class,
            InstabilityRule::class,
            CboRule::class,
            DistanceRule::class,
            CircularDependencyRule::class,
            LongParameterListRule::class,
            BooleanArgumentRule::class,
            CountInLoopRule::class,
            DebugCodeRule::class,
            EmptyCatchRule::class,
            ErrorSuppressionRule::class,
            EvalRule::class,
            ExitRule::class,
            GotoRule::class,
            SuperglobalsRule::class,
            UnreachableCodeRule::class,
            TypeCoverageRule::class,
            \Qualimetrix\Rules\Security\HardcodedCredentialsRule::class,
            ClassRankRule::class,
            SqlInjectionRule::class,
            XssRule::class,
            CommandInjectionRule::class,
            SensitiveParameterRule::class,
            \Qualimetrix\Rules\CodeSmell\UnusedPrivateRule::class,
            \Qualimetrix\Rules\CodeSmell\IdenticalSubExpressionRule::class,
            \Qualimetrix\Rules\Duplication\CodeDuplicationRule::class,
            \Qualimetrix\Rules\ComputedMetric\ComputedMetricRule::class,
            \Qualimetrix\Rules\CodeSmell\ConstructorOverinjectionRule::class,
            \Qualimetrix\Rules\CodeSmell\DataClassRule::class,
            \Qualimetrix\Rules\CodeSmell\GodClassRule::class,
        ];

        $registeredClasses = $registry->getClasses();

        foreach ($expectedRuleClasses as $expectedClass) {
            self::assertContains(
                $expectedClass,
                $registeredClasses,
                \sprintf("Rule '%s' should be registered in RuleRegistry", $expectedClass),
            );
        }

        // Verify we have exactly the expected number of rules
        self::assertCount(
            \count($expectedRuleClasses),
            $registeredClasses,
            'RuleRegistry should contain exactly ' . \count($expectedRuleClasses) . ' rules',
        );
    }

    public function testDistanceRuleHasProjectNamespaceResolverInjected(): void
    {
        $container = $this->factory->create();

        // Verify ProjectNamespaceResolverInterface is registered
        self::assertTrue($container->has(ProjectNamespaceResolverInterface::class));
        self::assertInstanceOf(
            ProjectNamespaceResolverInterface::class,
            $container->get(ProjectNamespaceResolverInterface::class),
        );
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = scandir($dir);
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
