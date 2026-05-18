<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules\Coupling;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Dependency\Dependency;
use Qualimetrix\Core\Dependency\DependencyGraphInterface;
use Qualimetrix\Core\Dependency\DependencyType;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Rule\AnalysisContext;
use Qualimetrix\Core\Rule\CliAliasReader;
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
    #[Test]
    public function itReturnsCorrectName(): void
    {
        $rule = new CboRule(new CboOptions());

        self::assertSame('coupling.cbo', $rule->getName());
    }

    #[Test]
    public function itReturnsCorrectDescription(): void
    {
        $rule = new CboRule(new CboOptions());

        self::assertSame(
            'Checks CBO (Coupling Between Objects) at class and namespace levels',
            $rule->getDescription(),
        );
    }

    #[Test]
    public function itReturnsCouplingCategory(): void
    {
        $rule = new CboRule(new CboOptions());

        self::assertSame(RuleCategory::Coupling, $rule->getCategory());
    }

    #[Test]
    public function itRequiresCboMetrics(): void
    {
        $rule = new CboRule(new CboOptions());

        self::assertSame(['cbo', 'ca', 'ce', 'cbo_app', 'ce_framework'], $rule->requires());
    }

    #[Test]
    public function itReturnsCorrectOptionsClass(): void
    {
        self::assertSame(
            CboOptions::class,
            CboRule::getOptionsClass(),
        );
    }

    #[Test]
    public function itReturnsClassAndNamespaceLevels(): void
    {
        $rule = new CboRule(new CboOptions());

        self::assertSame([RuleLevel::Class_, RuleLevel::Namespace_], $rule->getSupportedLevels());
    }

    #[Test]
    public function itDeclaresCorrectCliAliases(): void
    {
        self::assertSame([
            'cbo-warning' => 'class.warning',
            'cbo-error' => 'class.error',
            'cbo-ns-warning' => 'namespace.warning',
            'cbo-ns-error' => 'namespace.error',
        ], CliAliasReader::read(CboRule::class));
    }

    #[Test]
    public function itThrowsForInvalidOptionsType(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Expected');

        $invalidOptions = self::createStub(\Qualimetrix\Core\Rule\RuleOptionsInterface::class);
        new CboRule($invalidOptions);
    }

    // Class-level tests

    #[Test]
    public function itReturnsEmptyWhenClassLevelDisabled(): void
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

    #[Test]
    public function itReturnsEmptyWhenNoClasses(): void
    {
        $rule = new CboRule(new CboOptions());

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([]);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(RuleLevel::Class_, $context));
    }

    #[Test]
    public function itSkipsClassesWithoutCboMetric(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = new MetricBag();

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(RuleLevel::Class_, $context));
    }

    #[Test]
    public function itEmitsNoViolationWhenCboBelowThreshold(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        // CBO = 10, below warning threshold (14)
        $metricBag = (new MetricBag())
            ->with('cbo', 10)
            ->with('ca', 5)
            ->with('ce', 5);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

        self::assertCount(0, $violations);
    }

    #[Test]
    public function itGeneratesClassCboWarning(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        // CBO = 18, above warning (14), below error (20)
        $metricBag = (new MetricBag())
            ->with('cbo', 18)
            ->with('ca', 8)
            ->with('ce', 10);

        $repository = self::createStub(MetricRepositoryInterface::class);
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

    #[Test]
    public function itGeneratesClassCboError(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        // CBO = 25, above error threshold (20)
        $metricBag = (new MetricBag())
            ->with('cbo', 25)
            ->with('ca', 10)
            ->with('ce', 15);

        $repository = self::createStub(MetricRepositoryInterface::class);
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

    #[Test]
    public function itRespectsCustomClassCboThresholds(): void
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

        $repository = self::createStub(MetricRepositoryInterface::class);
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

    #[Test]
    public function itShowsAfferentDominantMessageWhenCaExceedsCeTwoToOne(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forClass('App\Core', 'OutputInterface');
        $classInfo = new SymbolInfo($symbolPath, 'src/Core/OutputInterface.php', 5);

        // Ca=44, Ce=1 — strongly afferent
        $metricBag = (new MetricBag())
            ->with('cbo', 45)
            ->with('ca', 44)
            ->with('ce', 1);

        $repository = self::createStub(MetricRepositoryInterface::class);
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

    #[Test]
    public function itShowsEfferentDominantMessageWhenCeExceedsCaTwoToOne(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'GodService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/GodService.php', 10);

        // Ca=3, Ce=22 — strongly efferent
        $metricBag = (new MetricBag())
            ->with('cbo', 25)
            ->with('ca', 3)
            ->with('ce', 22);

        $repository = self::createStub(MetricRepositoryInterface::class);
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

    #[Test]
    public function itShowsBalancedMessageWhenCaAndCeRoughlyEqual(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'MixedService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/MixedService.php', 10);

        // Ca=10, Ce=10 — balanced
        $metricBag = (new MetricBag())
            ->with('cbo', 20)
            ->with('ca', 10)
            ->with('ce', 10);

        $repository = self::createStub(MetricRepositoryInterface::class);
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

    #[Test]
    public function itReturnsEmptyWhenNamespaceLevelDisabled(): void
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

    #[Test]
    public function itGeneratesNamespaceCboWarning(): void
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

        $repository = self::createStub(MetricRepositoryInterface::class);
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

    #[Test]
    public function itGeneratesNamespaceCboError(): void
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

        $repository = self::createStub(MetricRepositoryInterface::class);
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

    #[Test]
    public function itSkipsNamespaceBelowMinClassCount(): void
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

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Namespace_, $context);

        self::assertCount(0, $violations);
    }

    // Legacy analyze() tests

    #[Test]
    public function itAnalyzesBothLevels(): void
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

        $repository = self::createStub(MetricRepositoryInterface::class);
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

    #[Test]
    public function itParsesClassOptionsFromArray(): void
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

    #[Test]
    public function itUsesClassOptionDefaults(): void
    {
        $options = ClassCboOptions::fromArray([]);

        self::assertTrue($options->enabled);
        self::assertSame(14, $options->warning);
        self::assertSame(20, $options->error);
    }

    #[Test]
    public function itParsesNamespaceOptionsFromArray(): void
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

    #[Test]
    public function itParsesCboOptionsFromHierarchicalArray(): void
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

    #[Test]
    public function itReturnsCorrectOptionsForLevel(): void
    {
        $options = new CboOptions();

        self::assertSame($options->class, $options->forLevel(RuleLevel::Class_));
        self::assertSame($options->namespace, $options->forLevel(RuleLevel::Namespace_));
    }

    #[Test]
    public function itThrowsForUnsupportedLevel(): void
    {
        $options = new CboOptions();

        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Level method is not supported by CboRule');

        $options->forLevel(RuleLevel::Method);
    }

    #[Test]
    public function itChecksWhetherLevelIsEnabled(): void
    {
        $options = new CboOptions(
            class: new ClassCboOptions(enabled: true),
            namespace: new NamespaceCboOptions(enabled: false),
        );

        self::assertTrue($options->isLevelEnabled(RuleLevel::Class_));
        self::assertFalse($options->isLevelEnabled(RuleLevel::Namespace_));
    }

    #[Test]
    public function itGetsSupportedLevels(): void
    {
        $options = new CboOptions();

        self::assertSame([RuleLevel::Class_, RuleLevel::Namespace_], $options->getSupportedLevels());
    }

    #[Test]
    public function itParsesNamespaceMinClassCountFromArray(): void
    {
        $options = NamespaceCboOptions::fromArray([
            'min_class_count' => 5,
        ]);

        self::assertSame(5, $options->minClassCount);
    }

    #[Test]
    public function itDefaultsNamespaceMinClassCountToThree(): void
    {
        $options = NamespaceCboOptions::fromArray([
            'enabled' => true,
        ]);

        self::assertSame(3, $options->minClassCount);
    }

    #[Test]
    public function itParsesNamespaceMinClassCountCamelCaseAlias(): void
    {
        $options = NamespaceCboOptions::fromArray([
            'minClassCount' => 7,
        ]);

        self::assertSame(7, $options->minClassCount);
    }

    // Dependency list in recommendation tests

    #[Test]
    public function itIncludesTopDependenciesInRecommendationWhenGraphAvailable(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'GodService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/GodService.php', 10);

        // CBO = 25, Ce = 22 — efferent dominant
        $metricBag = (new MetricBag())
            ->with('cbo', 25)
            ->with('ca', 3)
            ->with('ce', 22);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $location = new Location(RelativePath::fromString('src/Service/GodService.php'), 10);

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

        $graph = self::createStub(DependencyGraphInterface::class);
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

    #[Test]
    public function itLimitsRecommendationToFiveDependencies(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'HugeService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/HugeService.php', 10);

        $metricBag = (new MetricBag())
            ->with('cbo', 25)
            ->with('ca', 3)
            ->with('ce', 22);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $location = new Location(RelativePath::fromString('src/Service/HugeService.php'), 10);

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

        $graph = self::createStub(DependencyGraphInterface::class);
        $graph->method('getClassDependencies')
            ->willReturn($deps);

        $context = new AnalysisContext($repository, dependencyGraph: $graph);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

        self::assertCount(1, $violations);
        $recommendation = $violations[0]->recommendation;
        self::assertNotNull($recommendation);

        // Count the number of class names listed after "Top dependencies: "
        self::assertStringContainsString('Top dependencies:', $recommendation);
        if (preg_match('/Top dependencies: ([^.]+)\./', $recommendation, $matches) !== 1) {
            self::fail('Top dependencies pattern not found in: ' . $recommendation);
        }
        $listedDeps = explode(', ', $matches[1]);
        self::assertCount(5, $listedDeps);

        // Foxtrot and Golf should NOT be in the list (they are 6th and 7th)
        self::assertStringNotContainsString('Foxtrot', $recommendation);
        self::assertStringNotContainsString('Golf', $recommendation);
    }

    #[Test]
    public function itFallsBackToBaseMessageWithoutDependencyGraph(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = (new MetricBag())
            ->with('cbo', 25)
            ->with('ca', 10)
            ->with('ce', 15);

        $repository = self::createStub(MetricRepositoryInterface::class);
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

    #[Test]
    public function itFallsBackToBaseMessageWithEmptyDependencyList(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'Isolated');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/Isolated.php', 10);

        // CBO from afferent only
        $metricBag = (new MetricBag())
            ->with('cbo', 25)
            ->with('ca', 25)
            ->with('ce', 0);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $graph = self::createStub(DependencyGraphInterface::class);
        $graph->method('getClassDependencies')
            ->willReturn([]);

        $context = new AnalysisContext($repository, dependencyGraph: $graph);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

        self::assertCount(1, $violations);
        self::assertNotNull($violations[0]->recommendation);
        self::assertStringNotContainsString('Top dependencies:', $violations[0]->recommendation);
        self::assertStringContainsString('coupling magnet', $violations[0]->recommendation);
    }

    #[Test]
    public function itOmitsDependencyListFromNamespaceLevelRecommendation(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = new SymbolInfo($symbolPath, 'src/Service', null);

        $metricBag = (new MetricBag())
            ->with('cbo', 25)
            ->with('ca', 10)
            ->with('ce', 15)
            ->with('classCount.sum', 5);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $graph = self::createStub(DependencyGraphInterface::class);

        $context = new AnalysisContext($repository, dependencyGraph: $graph);
        $violations = $rule->analyzeLevel(RuleLevel::Namespace_, $context);

        self::assertCount(1, $violations);
        self::assertNotNull($violations[0]->recommendation);
        self::assertStringNotContainsString('Top dependencies:', $violations[0]->recommendation);
    }

    #[Test]
    public function itHandlesGlobalClassDependencyInRecommendation(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'MyService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/MyService.php', 10);

        $metricBag = (new MetricBag())
            ->with('cbo', 20)
            ->with('ca', 3)
            ->with('ce', 17);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $location = new Location(RelativePath::fromString('src/Service/MyService.php'), 10);

        // Dependency on a global class (no namespace)
        $deps = [
            new Dependency($symbolPath, SymbolPath::forClass('', 'stdClass'), DependencyType::New_, $location),
        ];

        $graph = self::createStub(DependencyGraphInterface::class);
        $graph->method('getClassDependencies')
            ->willReturn($deps);

        $context = new AnalysisContext($repository, dependencyGraph: $graph);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

        self::assertCount(1, $violations);
        self::assertNotNull($violations[0]->recommendation);
        self::assertStringContainsString('Top dependencies: stdClass', $violations[0]->recommendation);
    }

    #[Test]
    #[DataProvider('cboThresholdDataProvider')]
    public function itRespectsCboThresholdBoundaries(
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

        $repository = self::createStub(MetricRepositoryInterface::class);
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

    // Scope tests

    #[Test]
    public function itUsesCboAppMetricForApplicationScope(): void
    {
        $rule = new CboRule(
            new CboOptions(
                class: new ClassCboOptions(
                    warning: 5,
                    error: 10,
                    scope: 'application',
                ),
            ),
        );

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        // CBO = 15 (above error), CBO_APP = 3 (below warning)
        // With scope=application, should use CBO_APP → no violation
        $metricBag = (new MetricBag())
            ->with('cbo', 15)
            ->with('cbo_app', 3)
            ->with('ca', 5)
            ->with('ce', 10);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

        self::assertCount(0, $violations);
    }

    #[Test]
    public function itGeneratesWarningFromCboAppWhenApplicationScope(): void
    {
        $rule = new CboRule(
            new CboOptions(
                class: new ClassCboOptions(
                    warning: 5,
                    error: 10,
                    scope: 'application',
                ),
            ),
        );

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        // CBO = 30, CBO_APP = 7 (between warning=5 and error=10), CE_FRAMEWORK = 23
        $metricBag = (new MetricBag())
            ->with('cbo', 30)
            ->with('cbo_app', 7)
            ->with('ca', 5)
            ->with('ce', 25)
            ->with('ce_framework', 23);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertSame(7.0, $violations[0]->metricValue);
        // Message should reference CBO_APP metric name
        self::assertStringContainsString('CBO_APP', $violations[0]->message);
        // Message should use CBO_APP label and show framework exclusion count
        self::assertStringContainsString('CBO_APP: 7', $violations[0]->message);
        self::assertStringContainsString('framework: 23 classes excluded', $violations[0]->message);
    }

    #[Test]
    public function itUsesCboMetricForDefaultScopeAll(): void
    {
        // Default scope is 'all', should use CBO
        $rule = new CboRule(
            new CboOptions(
                class: new ClassCboOptions(
                    warning: 5,
                    error: 10,
                ),
            ),
        );

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        // CBO = 15 (above error), CBO_APP = 3 (below warning)
        // Default scope=all should use CBO → error
        $metricBag = (new MetricBag())
            ->with('cbo', 15)
            ->with('cbo_app', 3)
            ->with('ca', 5)
            ->with('ce', 10);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame(15.0, $violations[0]->metricValue);
    }

    // Scope options parsing tests

    #[Test]
    public function itParsesScopeInClassCboOptions(): void
    {
        $options = ClassCboOptions::fromArray([
            'scope' => 'application',
        ]);

        self::assertSame('application', $options->scope);
    }

    #[Test]
    public function itDefaultsClassCboScopeToAll(): void
    {
        $options = ClassCboOptions::fromArray([]);

        self::assertSame('all', $options->scope);
    }

    #[Test]
    public function itDefaultsInvalidScopeToAll(): void
    {
        $options = ClassCboOptions::fromArray([
            'scope' => 'invalid',
        ]);

        self::assertSame('all', $options->scope);
    }

    #[Test]
    public function itPropagatesTopLevelScopeToClassOptions(): void
    {
        $options = CboOptions::fromArray([
            'scope' => 'application',
        ]);

        self::assertSame('application', $options->class->scope);
    }

    #[Test]
    public function itAllowsClassLevelScopeToOverrideTopLevel(): void
    {
        $options = CboOptions::fromArray([
            'scope' => 'application',
            'class' => [
                'scope' => 'all',
            ],
        ]);

        self::assertSame('all', $options->class->scope);
    }
}
