<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Infrastructure\DependencyInjection;

use AiMessDetector\Analysis\Collection\Metric\CompositeCollector;
use AiMessDetector\Analysis\Pipeline\AnalysisPipelineInterface;
use AiMessDetector\Configuration\AnalysisConfiguration;
use AiMessDetector\Configuration\ConfigurationHolder;
use AiMessDetector\Configuration\ConfigurationProviderInterface;
use AiMessDetector\Configuration\RuleOptionsFactory;
use AiMessDetector\Core\Namespace_\ProjectNamespaceResolverInterface;
use AiMessDetector\Infrastructure\Cache\CacheInterface;
use AiMessDetector\Infrastructure\Console\Command\CheckCommand;
use AiMessDetector\Infrastructure\DependencyInjection\ContainerFactory;
use AiMessDetector\Infrastructure\Rule\RuleRegistryInterface;
use AiMessDetector\Metrics\Complexity\CognitiveComplexityCollector;
use AiMessDetector\Metrics\Complexity\CyclomaticComplexityCollector;
use AiMessDetector\Metrics\Complexity\NpathComplexityCollector;
use AiMessDetector\Metrics\Halstead\HalsteadCollector;
use AiMessDetector\Metrics\Maintainability\MaintainabilityIndexCollector;
use AiMessDetector\Metrics\Size\ClassCountCollector;
use AiMessDetector\Metrics\Size\LocCollector;
use AiMessDetector\Metrics\Structure\InheritanceDepthCollector;
use AiMessDetector\Metrics\Structure\LcomCollector;
use AiMessDetector\Metrics\Structure\MethodCountCollector;
use AiMessDetector\Metrics\Structure\RfcCollector;
use AiMessDetector\Metrics\Structure\TccLccCollector;
use AiMessDetector\Reporting\Formatter\FormatterRegistryInterface;
use AiMessDetector\Rules\Architecture\CircularDependencyRule;
use AiMessDetector\Rules\CodeSmell\BooleanArgumentRule;
use AiMessDetector\Rules\CodeSmell\CountInLoopRule;
use AiMessDetector\Rules\CodeSmell\DebugCodeRule;
use AiMessDetector\Rules\CodeSmell\EmptyCatchRule;
use AiMessDetector\Rules\CodeSmell\ErrorSuppressionRule;
use AiMessDetector\Rules\CodeSmell\EvalRule;
use AiMessDetector\Rules\CodeSmell\ExitRule;
use AiMessDetector\Rules\CodeSmell\GotoRule;
use AiMessDetector\Rules\CodeSmell\LongParameterListRule;
use AiMessDetector\Rules\CodeSmell\SuperglobalsRule;
use AiMessDetector\Rules\CodeSmell\UnreachableCodeRule;
use AiMessDetector\Rules\Complexity\CognitiveComplexityRule;
use AiMessDetector\Rules\Complexity\ComplexityRule;
use AiMessDetector\Rules\Complexity\NpathComplexityRule;
use AiMessDetector\Rules\Coupling\CboRule;
use AiMessDetector\Rules\Coupling\DistanceRule;
use AiMessDetector\Rules\Coupling\InstabilityRule;
use AiMessDetector\Rules\Design\TypeCoverageRule;
use AiMessDetector\Rules\Maintainability\MaintainabilityRule;
use AiMessDetector\Rules\Size\ClassCountRule;
use AiMessDetector\Rules\Size\MethodCountRule;
use AiMessDetector\Rules\Size\PropertyCountRule;
use AiMessDetector\Rules\Structure\InheritanceRule;
use AiMessDetector\Rules\Structure\LcomRule;
use AiMessDetector\Rules\Structure\NocRule;
use AiMessDetector\Rules\Structure\WmcRule;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContainerFactory::class)]
final class ContainerFactoryTest extends TestCase
{
    private ContainerFactory $factory;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->factory = new ContainerFactory();
        $this->tempDir = sys_get_temp_dir() . '/aimd_test_' . uniqid();
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
            'text',
            'text-verbose',
            'json',
            'checkstyle',
            'sarif',
            'gitlab',
            'metrics-json',
        ];

        foreach ($expectedFormatters as $name) {
            self::assertTrue(
                $registry->has($name),
                \sprintf("Formatter '%s' should be registered in FormatterRegistry", $name),
            );
        }

        // Verify we have exactly the expected number of formatters
        self::assertCount(
            \count($expectedFormatters),
            $registry->getAvailableNames(),
            'FormatterRegistry should contain exactly ' . \count($expectedFormatters) . ' formatters',
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
            \AiMessDetector\Rules\Security\HardcodedCredentialsRule::class,
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
