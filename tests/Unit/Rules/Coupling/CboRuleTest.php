<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules\Coupling;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Dependency\Dependency;
use Qualimetrix\Core\Dependency\DependencyGraphInterface;
use Qualimetrix\Core\Dependency\DependencyType;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\RuleCategory;
use Qualimetrix\Core\Rule\RuleLevel;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\Coupling\CboOptions;
use Qualimetrix\Rules\Coupling\CboRule;
use Qualimetrix\Rules\Coupling\ClassCboOptions;
use Qualimetrix\Rules\Coupling\NamespaceCboOptions;

#[CoversClass(CboRule::class)]
#[CoversClass(CboOptions::class)]
#[CoversClass(ClassCboOptions::class)]
#[CoversClass(NamespaceCboOptions::class)]
final class CboRuleTest extends TestCase
{
    public function testGetName(): void
    {
        $rule = new CboRule(new CboOptions());

        self::assertSame('coupling.cbo', $rule->getName());
    }

    public function testGetDescription(): void
    {
        $rule = new CboRule(new CboOptions());

        self::assertSame(
            'Checks CBO (Coupling Between Objects) at class and namespace levels',
            $rule->getDescription(),
        );
    }

    public function testGetCategory(): void
    {
        $rule = new CboRule(new CboOptions());

        self::assertSame(RuleCategory::Coupling, $rule->getCategory());
    }

    public function testRequires(): void
    {
        $rule = new CboRule(new CboOptions());

        self::assertSame(['cbo', 'ca', 'ce'], $rule->requires());
    }

    public function testGetOptionsClass(): void
    {
        self::assertSame(
            CboOptions::class,
            CboRule::getOptionsClass(),
        );
    }

    public function testGetSupportedLevels(): void
    {
        $rule = new CboRule(new CboOptions());

        self::assertSame([RuleLevel::Class_, RuleLevel::Namespace_], $rule->getSupportedLevels());
    }

    public function testGetCliAliases(): void
    {
        self::assertSame([
            'cbo-warning' => 'class.warning',
            'cbo-error' => 'class.error',
            'cbo-ns-warning' => 'namespace.warning',
            'cbo-ns-error' => 'namespace.error',
        ], CboRule::getCliAliases());
    }

    public function testConstructorThrowsForInvalidOptions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected');

        $invalidOptions = $this->createStub(\Qualimetrix\Core\Rule\RuleOptionsInterface::class);
        new CboRule($invalidOptions);
    }

    // Class-level tests

    public function testAnalyzeLevelClassReturnsEmptyWhenDisabled(): void
    {
        $rule = new CboRule(
            new CboOptions(
                class: new ClassCboOptions(enabled: false),
            ),
        );

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(RuleLevel::Class_, $context));
    }

    public function testAnalyzeLevelClassReturnsEmptyWhenNoClasses(): void
    {
        $rule = new CboRule(new CboOptions());

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([]);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(RuleLevel::Class_, $context));
    }

    public function testAnalyzeLevelClassSkipsWhenNoCboMetric(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = new MetricBag();

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(RuleLevel::Class_, $context));
    }

    public function testAnalyzeLevelClassNoViolationBelowThreshold(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        // CBO = 10, below warning threshold (14)
        $metricBag = (new MetricBag())
            ->with('cbo', 10)
            ->with('ca', 5)
            ->with('ce', 5);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

        self::assertCount(0, $violations);
    }

    public function testAnalyzeLevelClassCboGeneratesWarning(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        // CBO = 18, above warning (14), below error (20)
        $metricBag = (new MetricBag())
            ->with('cbo', 18)
            ->with('ca', 8)
            ->with('ce', 10);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertStringContainsString('Coupling too high: 8 inbound + 10 outbound (CBO: 18, threshold: 14)', $violations[0]->message);
        self::assertSame(18.0, $violations[0]->metricValue);
        self::assertSame('coupling.cbo', $violations[0]->ruleName);
        self::assertSame('coupling.cbo.class', $violations[0]->violationCode);
        self::assertSame(RuleLevel::Class_, $violations[0]->level);
    }

    public function testAnalyzeLevelClassCboGeneratesError(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        // CBO = 25, above error threshold (20)
        $metricBag = (new MetricBag())
            ->with('cbo', 25)
            ->with('ca', 10)
            ->with('ce', 15);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertStringContainsString('threshold: 20', $violations[0]->message);
        self::assertSame(25.0, $violations[0]->metricValue);
    }

    public function testAnalyzeLevelClassCboCustomThresholds(): void
    {
        $rule = new CboRule(
            new CboOptions(
                class: new ClassCboOptions(
                    warning: 10,
                    error: 15,
                ),
            ),
        );

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        // CBO = 12, above custom warning (10), below custom error (15)
        $metricBag = (new MetricBag())
            ->with('cbo', 12)
            ->with('ca', 6)
            ->with('ce', 6);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertStringContainsString('threshold: 10', $violations[0]->message);
    }

    // Direction-aware message tests

    public function testAfferentDominantMessageWhenCaExceedsCeTwoToOne(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forClass('App\Core', 'OutputInterface');
        $classInfo = new SymbolInfo($symbolPath, 'src/Core/OutputInterface.php', 5);

        // Ca=44, Ce=1 — strongly afferent
        $metricBag = (new MetricBag())
            ->with('cbo', 45)
            ->with('ca', 44)
            ->with('ce', 1);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

        self::assertCount(1, $violations);
        self::assertStringContainsString('Afferent coupling too high: 44 classes depend on this', $violations[0]->message);
        self::assertNotNull($violations[0]->recommendation);
        self::assertStringContainsString('coupling magnet', $violations[0]->recommendation);
    }

    public function testEfferentDominantMessageWhenCeExceedsCaTwoToOne(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'GodService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/GodService.php', 10);

        // Ca=3, Ce=22 — strongly efferent
        $metricBag = (new MetricBag())
            ->with('cbo', 25)
            ->with('ca', 3)
            ->with('ce', 22);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

        self::assertCount(1, $violations);
        self::assertStringContainsString('Efferent coupling too high: depends on 22 classes', $violations[0]->message);
        self::assertNotNull($violations[0]->recommendation);
        self::assertStringContainsString('extract dependencies', $violations[0]->recommendation);
    }

    public function testBalancedMessageWhenCaAndCeRoughlyEqual(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'MixedService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/MixedService.php', 10);

        // Ca=10, Ce=10 — balanced
        $metricBag = (new MetricBag())
            ->with('cbo', 20)
            ->with('ca', 10)
            ->with('ce', 10);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

        self::assertCount(1, $violations);
        self::assertStringContainsString('Coupling too high: 10 inbound + 10 outbound', $violations[0]->message);
        self::assertNotNull($violations[0]->recommendation);
        self::assertStringContainsString('reduce both inbound and outbound', $violations[0]->recommendation);
    }

    // Namespace-level tests

    public function testAnalyzeLevelNamespaceReturnsEmptyWhenDisabled(): void
    {
        $rule = new CboRule(
            new CboOptions(
                namespace: new NamespaceCboOptions(enabled: false),
            ),
        );

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(RuleLevel::Namespace_, $context));
    }

    public function testAnalyzeLevelNamespaceCboGeneratesWarning(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = new SymbolInfo($symbolPath, 'src/Service', null);

        // CBO = 16
        $metricBag = (new MetricBag())
            ->with('cbo', 16)
            ->with('ca', 6)
            ->with('ce', 10)
            ->with('classCount.sum', 5);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Namespace_, $context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertStringContainsString('Coupling too high: 6 inbound + 10 outbound (CBO: 16, threshold: 14)', $violations[0]->message);
        self::assertSame('coupling.cbo.namespace', $violations[0]->violationCode);
        self::assertSame(RuleLevel::Namespace_, $violations[0]->level);
    }

    public function testAnalyzeLevelNamespaceCboGeneratesError(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = new SymbolInfo($symbolPath, 'src/Service', null);

        // CBO = 25
        $metricBag = (new MetricBag())
            ->with('cbo', 25)
            ->with('ca', 10)
            ->with('ce', 15)
            ->with('classCount.sum', 5);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Namespace_, $context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame(25.0, $violations[0]->metricValue);
    }

    // Namespace minClassCount tests

    public function testAnalyzeLevelNamespaceSkipsWhenBelowMinClassCount(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = new SymbolInfo($symbolPath, 'src/Service', null);

        // classCount.sum = 1, below default minClassCount (3)
        $metricBag = (new MetricBag())
            ->with('cbo', 50)
            ->with('ca', 20)
            ->with('ce', 30)
            ->with('classCount.sum', 1);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Namespace_, $context);

        self::assertCount(0, $violations);
    }

    // Legacy analyze() tests

    public function testAnalyzeCallsBothLevels(): void
    {
        $rule = new CboRule(new CboOptions());

        $classPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($classPath, 'src/Service/UserService.php', 10);

        $nsPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = new SymbolInfo($nsPath, 'src/Service', null);

        $classBag = (new MetricBag())
            ->with('cbo', 18)
            ->with('ca', 8)
            ->with('ce', 10);
        $nsBag = (new MetricBag())
            ->with('cbo', 16)
            ->with('ca', 6)
            ->with('ce', 10)
            ->with('classCount.sum', 5);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolType $type) => match ($type) {
                SymbolType::Class_ => [$classInfo],
                SymbolType::Namespace_ => [$nsInfo],
                default => [],
            });
        $repository->method('get')
            ->willReturnCallback(fn(SymbolPath $path) => match ($path) {
                $classPath => $classBag,
                $nsPath => $nsBag,
                default => new MetricBag(),
            });

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(2, $violations);
        self::assertSame(RuleLevel::Class_, $violations[0]->level);
        self::assertSame(RuleLevel::Namespace_, $violations[1]->level);
    }

    // Options tests

    public function testClassOptionsFromArray(): void
    {
        $options = ClassCboOptions::fromArray([
            'enabled' => false,
            'warning' => 10,
            'error' => 15,
        ]);

        self::assertFalse($options->enabled);
        self::assertSame(10, $options->warning);
        self::assertSame(15, $options->error);
    }

    public function testClassOptionsFromEmptyArray(): void
    {
        $options = ClassCboOptions::fromArray([]);

        self::assertTrue($options->enabled);
        self::assertSame(14, $options->warning);
        self::assertSame(20, $options->error);
    }

    public function testNamespaceOptionsFromArray(): void
    {
        $options = NamespaceCboOptions::fromArray([
            'enabled' => false,
            'warning' => 10,
            'error' => 16,
        ]);

        self::assertFalse($options->enabled);
        self::assertSame(10, $options->warning);
        self::assertSame(16, $options->error);
    }

    public function testCboOptionsFromHierarchicalArray(): void
    {
        $options = CboOptions::fromArray([
            'class' => [
                'warning' => 10,
                'error' => 15,
            ],
            'namespace' => [
                'warning' => 12,
                'error' => 18,
            ],
        ]);

        self::assertTrue($options->isEnabled());
        self::assertTrue($options->class->isEnabled());
        self::assertSame(10, $options->class->warning);
        self::assertTrue($options->namespace->isEnabled());
        self::assertSame(12, $options->namespace->warning);
    }

    public function testCboOptionsForLevel(): void
    {
        $options = new CboOptions();

        self::assertSame($options->class, $options->forLevel(RuleLevel::Class_));
        self::assertSame($options->namespace, $options->forLevel(RuleLevel::Namespace_));
    }

    public function testCboOptionsForLevelThrowsForUnsupportedLevel(): void
    {
        $options = new CboOptions();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Level method is not supported by CboRule');

        $options->forLevel(RuleLevel::Method);
    }

    public function testCboOptionsIsLevelEnabled(): void
    {
        $options = new CboOptions(
            class: new ClassCboOptions(enabled: true),
            namespace: new NamespaceCboOptions(enabled: false),
        );

        self::assertTrue($options->isLevelEnabled(RuleLevel::Class_));
        self::assertFalse($options->isLevelEnabled(RuleLevel::Namespace_));
    }

    public function testCboOptionsGetSupportedLevels(): void
    {
        $options = new CboOptions();

        self::assertSame([RuleLevel::Class_, RuleLevel::Namespace_], $options->getSupportedLevels());
    }

    public function testNamespaceOptionsFromArrayIncludesMinClassCount(): void
    {
        $options = NamespaceCboOptions::fromArray([
            'min_class_count' => 5,
        ]);

        self::assertSame(5, $options->minClassCount);
    }

    public function testNamespaceOptionsFromArrayMinClassCountDefaultsToThree(): void
    {
        $options = NamespaceCboOptions::fromArray([
            'enabled' => true,
        ]);

        self::assertSame(3, $options->minClassCount);
    }

    public function testNamespaceOptionsFromArrayMinClassCountCamelCaseAlias(): void
    {
        $options = NamespaceCboOptions::fromArray([
            'minClassCount' => 7,
        ]);

        self::assertSame(7, $options->minClassCount);
    }

    // Dependency list in recommendation tests

    public function testRecommendationIncludesTopDependenciesWhenGraphAvailable(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'GodService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/GodService.php', 10);

        // CBO = 25, Ce = 22 — efferent dominant
        $metricBag = (new MetricBag())
            ->with('cbo', 25)
            ->with('ca', 3)
            ->with('ce', 22);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $location = new Location('src/Service/GodService.php', 10);

        // Create mock dependencies — 7 unique targets with varying occurrence counts
        $deps = [
            new Dependency($symbolPath, SymbolPath::forClass('App\Repository', 'UserRepository'), DependencyType::TypeHint, $location),
            new Dependency($symbolPath, SymbolPath::forClass('App\Repository', 'UserRepository'), DependencyType::New_, $location),
            new Dependency($symbolPath, SymbolPath::forClass('App\Repository', 'UserRepository'), DependencyType::TypeHint, $location),
            new Dependency($symbolPath, SymbolPath::forClass('App\Service', 'Logger'), DependencyType::TypeHint, $location),
            new Dependency($symbolPath, SymbolPath::forClass('App\Service', 'Logger'), DependencyType::TypeHint, $location),
            new Dependency($symbolPath, SymbolPath::forClass('App\Dto', 'UserDto'), DependencyType::New_, $location),
            new Dependency($symbolPath, SymbolPath::forClass('App\Event', 'UserCreated'), DependencyType::New_, $location),
            new Dependency($symbolPath, SymbolPath::forClass('App\Contract', 'EventDispatcher'), DependencyType::TypeHint, $location),
            new Dependency($symbolPath, SymbolPath::forClass('App\Validator', 'EmailValidator'), DependencyType::New_, $location),
            new Dependency($symbolPath, SymbolPath::forClass('App\Cache', 'CacheManager'), DependencyType::TypeHint, $location),
        ];

        $graph = $this->createStub(DependencyGraphInterface::class);
        $graph->method('getClassDependencies')
            ->willReturn($deps);

        $context = new AnalysisContext($repository, dependencyGraph: $graph);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

        self::assertCount(1, $violations);
        self::assertNotNull($violations[0]->recommendation);
        // UserRepository has 3 occurrences, Logger has 2, rest have 1
        self::assertStringContainsString('Top dependencies: UserRepository, Logger', $violations[0]->recommendation);
        // Should also contain the base recommendation
        self::assertStringContainsString('extract dependencies to reduce outbound coupling', $violations[0]->recommendation);
    }

    public function testRecommendationLimitsToFiveDependencies(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'HugeService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/HugeService.php', 10);

        $metricBag = (new MetricBag())
            ->with('cbo', 25)
            ->with('ca', 3)
            ->with('ce', 22);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $location = new Location('src/Service/HugeService.php', 10);

        // Create 7 unique dependencies — only top 5 should appear
        $deps = [];
        $classes = ['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo', 'Foxtrot', 'Golf'];
        foreach ($classes as $className) {
            $deps[] = new Dependency(
                $symbolPath,
                SymbolPath::forClass('App\Deps', $className),
                DependencyType::TypeHint,
                $location,
            );
        }

        $graph = $this->createStub(DependencyGraphInterface::class);
        $graph->method('getClassDependencies')
            ->willReturn($deps);

        $context = new AnalysisContext($repository, dependencyGraph: $graph);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

        self::assertCount(1, $violations);
        $recommendation = $violations[0]->recommendation;
        self::assertNotNull($recommendation);

        // Count the number of class names listed after "Top dependencies: "
        self::assertStringContainsString('Top dependencies:', $recommendation);
        preg_match('/Top dependencies: ([^.]+)\./', $recommendation, $matches);
        self::assertNotEmpty($matches);
        $listedDeps = explode(', ', $matches[1]);
        self::assertCount(5, $listedDeps);

        // Foxtrot and Golf should NOT be in the list (they are 6th and 7th)
        self::assertStringNotContainsString('Foxtrot', $recommendation);
        self::assertStringNotContainsString('Golf', $recommendation);
    }

    public function testRecommendationWithoutDependencyGraphFallsBackToBaseMessage(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())
            ->with('cbo', 25)
            ->with('ca', 10)
            ->with('ce', 15);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        // No dependency graph
        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

        self::assertCount(1, $violations);
        self::assertNotNull($violations[0]->recommendation);
        self::assertStringNotContainsString('Top dependencies:', $violations[0]->recommendation);
    }

    public function testRecommendationWithEmptyDependencyListFallsBackToBaseMessage(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'Isolated');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/Isolated.php', 10);

        // CBO from afferent only
        $metricBag = (new MetricBag())
            ->with('cbo', 25)
            ->with('ca', 25)
            ->with('ce', 0);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $graph = $this->createStub(DependencyGraphInterface::class);
        $graph->method('getClassDependencies')
            ->willReturn([]);

        $context = new AnalysisContext($repository, dependencyGraph: $graph);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

        self::assertCount(1, $violations);
        self::assertNotNull($violations[0]->recommendation);
        self::assertStringNotContainsString('Top dependencies:', $violations[0]->recommendation);
        self::assertStringContainsString('coupling magnet', $violations[0]->recommendation);
    }

    public function testNamespaceLevelDoesNotIncludeDependencyList(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = new SymbolInfo($symbolPath, 'src/Service', null);

        $metricBag = (new MetricBag())
            ->with('cbo', 25)
            ->with('ca', 10)
            ->with('ce', 15)
            ->with('classCount.sum', 5);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $graph = $this->createStub(DependencyGraphInterface::class);

        $context = new AnalysisContext($repository, dependencyGraph: $graph);
        $violations = $rule->analyzeLevel(RuleLevel::Namespace_, $context);

        self::assertCount(1, $violations);
        self::assertNotNull($violations[0]->recommendation);
        self::assertStringNotContainsString('Top dependencies:', $violations[0]->recommendation);
    }

    public function testRecommendationWithGlobalClassDependency(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'MyService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/MyService.php', 10);

        $metricBag = (new MetricBag())
            ->with('cbo', 20)
            ->with('ca', 3)
            ->with('ce', 17);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $location = new Location('src/Service/MyService.php', 10);

        // Dependency on a global class (no namespace)
        $deps = [
            new Dependency($symbolPath, SymbolPath::forClass('', 'stdClass'), DependencyType::New_, $location),
        ];

        $graph = $this->createStub(DependencyGraphInterface::class);
        $graph->method('getClassDependencies')
            ->willReturn($deps);

        $context = new AnalysisContext($repository, dependencyGraph: $graph);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

        self::assertCount(1, $violations);
        self::assertNotNull($violations[0]->recommendation);
        self::assertStringContainsString('Top dependencies: stdClass', $violations[0]->recommendation);
    }

    #[DataProvider('cboThresholdDataProvider')]
    public function testCboThresholdBoundaries(
        int $cbo,
        int $warning,
        int $error,
        ?Severity $expectedSeverity,
    ): void {
        $rule = new CboRule(
            new CboOptions(
                class: new ClassCboOptions(
                    warning: $warning,
                    error: $error,
                ),
            ),
        );

        $symbolPath = SymbolPath::forClass('App', 'Test');
        $classInfo = new SymbolInfo($symbolPath, 'test.php', 1);

        $metricBag = (new MetricBag())
            ->with('cbo', $cbo)
            ->with('ca', 5)
            ->with('ce', $cbo - 5);

        $repository = $this->createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

        if ($expectedSeverity === null) {
            self::assertCount(0, $violations);
        } else {
            self::assertCount(1, $violations);
            self::assertSame($expectedSeverity, $violations[0]->severity);
        }
    }

    /**
     * @return iterable<string, array{int, int, int, ?Severity}>
     */
    public static function cboThresholdDataProvider(): iterable
    {
        yield 'below warning threshold' => [13, 14, 20, null];
        yield 'at warning threshold' => [14, 14, 20, Severity::Warning];
        yield 'above warning, below error' => [18, 14, 20, Severity::Warning];
        yield 'at error threshold' => [20, 14, 20, Severity::Error];
        yield 'above error threshold' => [25, 14, 20, Severity::Error];
    }
}
