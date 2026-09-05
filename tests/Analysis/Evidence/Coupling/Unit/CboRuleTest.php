<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Coupling\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Coupling\CboOptions;
use Qualimetrix\Analysis\Evidence\Coupling\CboRule;
use Qualimetrix\Analysis\Evidence\Coupling\ClassCboOptions;
use Qualimetrix\Analysis\Evidence\Coupling\NamespaceCboOptions;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\CliAliasReader;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsFactory;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\TestSupport\Logging\Support\RecordingLogger;

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

        self::assertSame([SymbolLevel::Class_, SymbolLevel::Namespace_], $rule->getSupportedLevels());
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

        $invalidOptions = self::createStub(\Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface::class);
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

        self::assertSame([], $rule->analyzeLevel(SymbolLevel::Class_, $context));
    }

    #[Test]
    public function itReturnsEmptyForTheUnsupportedCallableDispatchWithoutReadingMetrics(): void
    {
        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('allDeclarations');
        $repository->expects(self::never())->method('all');

        self::assertSame([], (new CboRule(new CboOptions()))
            ->analyzeLevel(SymbolLevel::Callable, new AnalysisContext($repository)));
    }

    #[Test]
    public function itReadsCouplingPresentationMetricsThroughTheAnalysisContext(): void
    {
        $symbolPath = SymbolPath::forClass('App', 'ContextOwned');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/ContextOwned.php'), 10);
        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')->willReturn([$classInfo]);
        $repository->expects(self::exactly(2))
            ->method('get')
            ->with($symbolPath)
            ->willReturn((new MetricBag())->with('coupling.cbo', 18)->with('coupling.ca', 8)->with('coupling.ce', 10));

        $findings = (new CboRule(new CboOptions()))
            ->analyzeLevel(SymbolLevel::Class_, new AnalysisContext($repository));

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame($classInfo->subject?->toCanonical(), $findings[0]->subject->toCanonical());
    }

    #[Test]
    public function itReturnsEmptyWhenNoClasses(): void
    {
        $rule = new CboRule(new CboOptions());

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([]);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(SymbolLevel::Class_, $context));
    }

    #[Test]
    public function itSkipsClassesWithoutCboMetric(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        $metricBag = new MetricBag();

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(SymbolLevel::Class_, $context));
    }

    #[Test]
    public function itEmitsNoFindingWhenCboBelowThreshold(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        // CBO = 10, below warning threshold (14)
        $metricBag = (new MetricBag())
            ->with('coupling.cbo', 10)
            ->with('coupling.ca', 5)
            ->with('coupling.ce', 5);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyzeLevel(SymbolLevel::Class_, $context);

        self::assertCount(0, $findings);
    }

    #[Test]
    public function itGeneratesClassCboWarning(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        // CBO = 18, above warning (14), below error (20)
        $metricBag = (new MetricBag())
            ->with('coupling.cbo', 18)
            ->with('coupling.ca', 8)
            ->with('coupling.ce', 10);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyzeLevel(SymbolLevel::Class_, $context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertStringContainsString('Coupling too high: 8 inbound + 10 outbound (CBO: 18, threshold: 14)', $findings[0]->message);
        self::assertSame(18.0, $findings[0]->metricValue);
        self::assertSame('coupling.cbo', $findings[0]->ruleName);
        self::assertSame('coupling.cbo', $findings[0]->code);
    }

    #[Test]
    public function itGeneratesClassCboError(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        // CBO = 25, above error threshold (20)
        $metricBag = (new MetricBag())
            ->with('coupling.cbo', 25)
            ->with('coupling.ca', 10)
            ->with('coupling.ce', 15);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyzeLevel(SymbolLevel::Class_, $context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Error, $findings[0]->severity);
        self::assertStringContainsString('threshold: 20', $findings[0]->message);
        self::assertSame(25.0, $findings[0]->metricValue);
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
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        // CBO = 12, above custom warning (10), below custom error (15)
        $metricBag = (new MetricBag())
            ->with('coupling.cbo', 12)
            ->with('coupling.ca', 6)
            ->with('coupling.ce', 6);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyzeLevel(SymbolLevel::Class_, $context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertStringContainsString('threshold: 10', $findings[0]->message);
    }

    // Direction-aware message tests

    #[Test]
    public function itShowsAfferentDominantMessageWhenCaExceedsCeTwoToOne(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forClass('App\Core', 'OutputInterface');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Core/OutputInterface.php'), 5);

        // Ca=44, Ce=1 — strongly afferent
        $metricBag = (new MetricBag())
            ->with('coupling.cbo', 45)
            ->with('coupling.ca', 44)
            ->with('coupling.ce', 1);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyzeLevel(SymbolLevel::Class_, $context);

        self::assertCount(1, $findings);
        self::assertStringContainsString('Afferent coupling too high: 44 classes depend on this', $findings[0]->message);
        self::assertNotNull($findings[0]->recommendation);
        self::assertStringContainsString('coupling magnet', $findings[0]->recommendation);
    }

    #[Test]
    public function itShowsEfferentDominantMessageWhenCeExceedsCaTwoToOne(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'GodService');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/GodService.php'), 10);

        // Ca=3, Ce=22 — strongly efferent
        $metricBag = (new MetricBag())
            ->with('coupling.cbo', 25)
            ->with('coupling.ca', 3)
            ->with('coupling.ce', 22);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyzeLevel(SymbolLevel::Class_, $context);

        self::assertCount(1, $findings);
        self::assertStringContainsString('Efferent coupling too high: depends on 22 classes', $findings[0]->message);
        self::assertNotNull($findings[0]->recommendation);
        self::assertStringContainsString('extract dependencies', $findings[0]->recommendation);
    }

    #[Test]
    public function itShowsBalancedMessageWhenCaAndCeRoughlyEqual(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'MixedService');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/MixedService.php'), 10);

        // Ca=10, Ce=10 — balanced
        $metricBag = (new MetricBag())
            ->with('coupling.cbo', 20)
            ->with('coupling.ca', 10)
            ->with('coupling.ce', 10);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyzeLevel(SymbolLevel::Class_, $context);

        self::assertCount(1, $findings);
        self::assertStringContainsString('Coupling too high: 10 inbound + 10 outbound', $findings[0]->message);
        self::assertNotNull($findings[0]->recommendation);
        self::assertStringContainsString('reduce both inbound and outbound', $findings[0]->recommendation);
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

        self::assertSame([], $rule->analyzeLevel(SymbolLevel::Namespace_, $context));
    }

    #[Test]
    public function itGeneratesNamespaceCboWarning(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service'), null);

        // CBO = 16
        $metricBag = (new MetricBag())
            ->with('coupling.cbo', 16)
            ->with('coupling.ca', 6)
            ->with('coupling.ce', 10)
            ->with('size.class-count.sum', 5);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyzeLevel(SymbolLevel::Namespace_, $context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertStringContainsString('Coupling too high: 6 inbound + 10 outbound (CBO: 16, threshold: 14)', $findings[0]->message);
        self::assertSame('coupling.cbo', $findings[0]->code);
    }

    #[Test]
    public function itGeneratesNamespaceCboError(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service'), null);

        // CBO = 25
        $metricBag = (new MetricBag())
            ->with('coupling.cbo', 25)
            ->with('coupling.ca', 10)
            ->with('coupling.ce', 15)
            ->with('size.class-count.sum', 5);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyzeLevel(SymbolLevel::Namespace_, $context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Error, $findings[0]->severity);
        self::assertSame(25.0, $findings[0]->metricValue);
    }

    // Namespace minClassCount tests

    #[Test]
    public function itSkipsNamespaceBelowMinClassCount(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service'), null);

        // classCount.sum = 1, below default minClassCount (3)
        $metricBag = (new MetricBag())
            ->with('coupling.cbo', 50)
            ->with('coupling.ca', 20)
            ->with('coupling.ce', 30)
            ->with('size.class-count.sum', 1);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyzeLevel(SymbolLevel::Namespace_, $context);

        self::assertCount(0, $findings);
    }

    // Legacy analyze() tests

    #[Test]
    public function itAnalyzesBothLevels(): void
    {
        $rule = new CboRule(new CboOptions());

        $classPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = self::subjectInfo($classPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        $nsPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = self::subjectInfo($nsPath, RelativePath::fromString('src/Service'), null);

        $classBag = (new MetricBag())
            ->with('coupling.cbo', 18)
            ->with('coupling.ca', 8)
            ->with('coupling.ce', 10);
        $nsBag = (new MetricBag())
            ->with('coupling.cbo', 16)
            ->with('coupling.ca', 6)
            ->with('coupling.ce', 10)
            ->with('size.class-count.sum', 5);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturnCallback(fn(SymbolLevel $level) => match ($level) {
                SymbolLevel::Class_ => [$classInfo],
                SymbolLevel::Namespace_ => [$nsInfo],
                default => [],
            });
        $repository->method('allDeclarations')->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturnCallback(fn(SymbolPath $path) => match ($path) {
                $classPath => $classBag,
                $nsPath => $nsBag,
                default => new MetricBag(),
            });

        $context = new AnalysisContext($repository);
        $findings = $rule->analyze($context);

        self::assertCount(2, $findings);
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

        self::assertSame($options->class, $options->forLevel(SymbolLevel::Class_));
        self::assertSame($options->namespace, $options->forLevel(SymbolLevel::Namespace_));
    }

    #[Test]
    public function itThrowsForUnsupportedLevel(): void
    {
        $options = new CboOptions();

        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Level callable is not supported by CboRule');

        $options->forLevel(SymbolLevel::Callable);
    }

    #[Test]
    public function itChecksWhetherLevelIsEnabled(): void
    {
        $options = new CboOptions(
            class: new ClassCboOptions(enabled: true),
            namespace: new NamespaceCboOptions(enabled: false),
        );

        self::assertTrue($options->isLevelEnabled(SymbolLevel::Class_));
        self::assertFalse($options->isLevelEnabled(SymbolLevel::Namespace_));
    }

    #[Test]
    public function itGetsSupportedLevels(): void
    {
        $options = new CboOptions();

        self::assertSame([SymbolLevel::Class_, SymbolLevel::Namespace_], $options->getSupportedLevels());
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
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/GodService.php'), 10);

        // CBO = 25, Ce = 22 — efferent dominant
        $metricBag = (new MetricBag())
            ->with('coupling.cbo', 25)
            ->with('coupling.ca', 3)
            ->with('coupling.ce', 22);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $location = new Location(RelativePath::fromString('src/Service/GodService.php'), 10);

        // Create mock dependencies — 7 unique targets with varying occurrence counts
        $deps = [
            $this->dependency($symbolPath, SymbolPath::forClass('App\Repository', 'UserRepository'), DependencyType::TypeHint, $location),
            $this->dependency($symbolPath, SymbolPath::forClass('App\Repository', 'UserRepository'), DependencyType::New_, $location),
            $this->dependency($symbolPath, SymbolPath::forClass('App\Repository', 'UserRepository'), DependencyType::TypeHint, $location),
            $this->dependency($symbolPath, SymbolPath::forClass('App\Service', 'Logger'), DependencyType::TypeHint, $location),
            $this->dependency($symbolPath, SymbolPath::forClass('App\Service', 'Logger'), DependencyType::TypeHint, $location),
            $this->dependency($symbolPath, SymbolPath::forClass('App\Dto', 'UserDto'), DependencyType::New_, $location),
            $this->dependency($symbolPath, SymbolPath::forClass('App\Event', 'UserCreated'), DependencyType::New_, $location),
            $this->dependency($symbolPath, SymbolPath::forClass('App\Contract', 'EventDispatcher'), DependencyType::TypeHint, $location),
            $this->dependency($symbolPath, SymbolPath::forClass('App\Validator', 'EmailValidator'), DependencyType::New_, $location),
            $this->dependency($symbolPath, SymbolPath::forClass('App\Cache', 'CacheManager'), DependencyType::TypeHint, $location),
        ];

        $graph = self::createStub(DependencyGraphInterface::class);
        $graph->method('getClassDependencies')
            ->willReturn($deps);

        $context = new AnalysisContext($repository, dependencyGraph: $graph);
        $findings = $rule->analyzeLevel(SymbolLevel::Class_, $context);

        self::assertCount(1, $findings);
        self::assertNotNull($findings[0]->recommendation);
        // UserRepository has 3 occurrences, Logger has 2, rest have 1
        self::assertStringContainsString('Top dependencies: UserRepository, Logger', $findings[0]->recommendation);
        // Should also contain the base recommendation
        self::assertStringContainsString('extract dependencies to reduce outbound coupling', $findings[0]->recommendation);
    }

    #[Test]
    public function itLimitsRecommendationToFiveDependencies(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'HugeService');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/HugeService.php'), 10);

        $metricBag = (new MetricBag())
            ->with('coupling.cbo', 25)
            ->with('coupling.ca', 3)
            ->with('coupling.ce', 22);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $location = new Location(RelativePath::fromString('src/Service/HugeService.php'), 10);

        // Create 7 unique dependencies — only top 5 should appear
        $deps = [];
        $classes = ['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo', 'Foxtrot', 'Golf'];
        foreach ($classes as $className) {
            $deps[] = $this->dependency(
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
        $findings = $rule->analyzeLevel(SymbolLevel::Class_, $context);

        self::assertCount(1, $findings);
        $recommendation = $findings[0]->recommendation;
        self::assertNotNull($recommendation);

        // Count the number of class names listed after "Top dependencies: "
        self::assertStringContainsString('Top dependencies:', $recommendation);
        if (preg_match('/Top dependencies: ([^.]+)\./', $recommendation, $matches) !== 1) {
            self::fail('Top dependencies pattern not found in: ' . $recommendation);
        }
        $listedDeps = explode(', ', $matches[1]);
        self::assertSame(['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo'], $listedDeps);

        // Foxtrot and Golf should NOT be in the list (they are 6th and 7th)
        self::assertStringNotContainsString('Foxtrot', $recommendation);
        self::assertStringNotContainsString('Golf', $recommendation);
    }

    #[Test]
    public function itFallsBackToBaseMessageWithoutDependencyGraph(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        $metricBag = (new MetricBag())
            ->with('coupling.cbo', 25)
            ->with('coupling.ca', 10)
            ->with('coupling.ce', 15);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        // No dependency graph
        $context = new AnalysisContext($repository);
        $findings = $rule->analyzeLevel(SymbolLevel::Class_, $context);

        self::assertCount(1, $findings);
        self::assertNotNull($findings[0]->recommendation);
        self::assertStringNotContainsString('Top dependencies:', $findings[0]->recommendation);
    }

    #[Test]
    public function itFallsBackToBaseMessageWithEmptyDependencyList(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'Isolated');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/Isolated.php'), 10);

        // CBO from afferent only
        $metricBag = (new MetricBag())
            ->with('coupling.cbo', 25)
            ->with('coupling.ca', 25)
            ->with('coupling.ce', 0);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $graph = self::createStub(DependencyGraphInterface::class);
        $graph->method('getClassDependencies')
            ->willReturn([]);

        $context = new AnalysisContext($repository, dependencyGraph: $graph);
        $findings = $rule->analyzeLevel(SymbolLevel::Class_, $context);

        self::assertCount(1, $findings);
        self::assertNotNull($findings[0]->recommendation);
        self::assertStringNotContainsString('Top dependencies:', $findings[0]->recommendation);
        self::assertStringContainsString('coupling magnet', $findings[0]->recommendation);
    }

    #[Test]
    public function itOmitsDependencyListFromNamespaceLevelRecommendation(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service'), null);

        $metricBag = (new MetricBag())
            ->with('coupling.cbo', 25)
            ->with('coupling.ca', 10)
            ->with('coupling.ce', 15)
            ->with('size.class-count.sum', 5);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $graph = self::createStub(DependencyGraphInterface::class);

        $context = new AnalysisContext($repository, dependencyGraph: $graph);
        $findings = $rule->analyzeLevel(SymbolLevel::Namespace_, $context);

        self::assertCount(1, $findings);
        self::assertNotNull($findings[0]->recommendation);
        self::assertStringNotContainsString('Top dependencies:', $findings[0]->recommendation);
    }

    #[Test]
    public function itHandlesGlobalClassDependencyInRecommendation(): void
    {
        $rule = new CboRule(new CboOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'MyService');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/MyService.php'), 10);

        $metricBag = (new MetricBag())
            ->with('coupling.cbo', 20)
            ->with('coupling.ca', 3)
            ->with('coupling.ce', 17);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $location = new Location(RelativePath::fromString('src/Service/MyService.php'), 10);

        // Dependency on a global class (no namespace)
        $deps = [
            $this->dependency($symbolPath, SymbolPath::forClass('', 'stdClass'), DependencyType::New_, $location),
        ];

        $graph = self::createStub(DependencyGraphInterface::class);
        $graph->method('getClassDependencies')
            ->willReturn($deps);

        $context = new AnalysisContext($repository, dependencyGraph: $graph);
        $findings = $rule->analyzeLevel(SymbolLevel::Class_, $context);

        self::assertCount(1, $findings);
        self::assertNotNull($findings[0]->recommendation);
        self::assertStringContainsString('Top dependencies: stdClass', $findings[0]->recommendation);
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
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('test.php'), 1);

        $metricBag = (new MetricBag())
            ->with('coupling.cbo', $cbo)
            ->with('coupling.ca', 5)
            ->with('coupling.ce', $cbo - 5);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyzeLevel(SymbolLevel::Class_, $context);

        if ($expectedSeverity === null) {
            self::assertCount(0, $findings);
        } else {
            self::assertCount(1, $findings);
            self::assertSame($expectedSeverity, $findings[0]->severity);
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
    public function itAppliesTopLevelApplicationScopeThroughTheFactoryWithoutAnUnknownOptionWarning(): void
    {
        $registry = new RuleOptionsRegistry();
        $logger = new RecordingLogger();
        $factory = new RuleOptionsFactory($registry, $logger);
        $registry->setConfigFileOptions([
            'coupling.cbo' => [
                'scope' => 'application',
                'class' => ['warning' => 5, 'error' => 10],
            ],
        ]);

        /** @var CboOptions $options */
        $options = $factory->create('coupling.cbo', CboOptions::class);
        $rule = new CboRule($options);

        $symbolPath = SymbolPath::forClass('App\\Service', 'UserService');
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);
        $metricBag = (new MetricBag())
            ->with('coupling.cbo', 30)
            ->with('coupling.cbo-app', 7)
            ->with('coupling.ca', 5)
            ->with('coupling.ce', 25)
            ->with('coupling.ce-framework', 23);
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')->willReturn([$classInfo]);
        $repository->method('get')->willReturn($metricBag);

        $findings = $rule->analyzeLevel(SymbolLevel::Class_, new AnalysisContext($repository));

        self::assertSame([], $logger->records);
        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame(7.0, $findings[0]->metricValue);
        self::assertStringContainsString('CBO_APP: 7', $findings[0]->message);
    }

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
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        // CBO = 15 (above error), CBO_APP = 3 (below warning)
        // With scope=application, should use CBO_APP → no finding
        $metricBag = (new MetricBag())
            ->with('coupling.cbo', 15)
            ->with('coupling.cbo-app', 3)
            ->with('coupling.ca', 5)
            ->with('coupling.ce', 10);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyzeLevel(SymbolLevel::Class_, $context);

        self::assertCount(0, $findings);
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
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        // CBO = 30, CBO_APP = 7 (between warning=5 and error=10), CE_FRAMEWORK = 23
        $metricBag = (new MetricBag())
            ->with('coupling.cbo', 30)
            ->with('coupling.cbo-app', 7)
            ->with('coupling.ca', 5)
            ->with('coupling.ce', 25)
            ->with('coupling.ce-framework', 23);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyzeLevel(SymbolLevel::Class_, $context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Warning, $findings[0]->severity);
        self::assertSame(7.0, $findings[0]->metricValue);
        // Message should reference CBO_APP metric name
        self::assertStringContainsString('CBO_APP', $findings[0]->message);
        // Message should use CBO_APP label and show framework exclusion count
        self::assertStringContainsString('CBO_APP: 7', $findings[0]->message);
        self::assertStringContainsString('framework: 23 classes excluded', $findings[0]->message);
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
        $classInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        // CBO = 15 (above error), CBO_APP = 3 (below warning)
        // Default scope=all should use CBO → error
        $metricBag = (new MetricBag())
            ->with('coupling.cbo', 15)
            ->with('coupling.cbo-app', 3)
            ->with('coupling.ca', 5)
            ->with('coupling.ce', 10);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $findings = $rule->analyzeLevel(SymbolLevel::Class_, $context);

        self::assertCount(1, $findings);
        self::assertSame(Severity::Error, $findings[0]->severity);
        self::assertSame(15.0, $findings[0]->metricValue);
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

    #[Test]
    public function itProjectsDuplicateLogicalClassScoresToIndependentExactDeclarations(): void
    {
        $class = SymbolPath::forClass('App\\Service', 'Twin');
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('allDeclarations')->willReturn([
            self::subjectInfo($class, RelativePath::fromString('src/A.php'), 100),
            self::subjectInfo($class, RelativePath::fromString('src/B.php'), 200),
        ]);
        $repository->method('get')->willReturn(
            (new MetricBag())->with('coupling.cbo', 18)->with('coupling.ca', 8)->with('coupling.ce', 10),
        );

        $findings = (new CboRule(new CboOptions()))
            ->analyzeLevel(SymbolLevel::Class_, new AnalysisContext($repository));

        self::assertCount(2, $findings);
        $subjects = array_map(static fn($finding): string => $finding->subject->toCanonical(), $findings);
        sort($subjects);
        self::assertSame([
            'declaration:class:App\\Service\\Twin@src/A.php',
            'declaration:class:App\\Service\\Twin@src/B.php',
        ], $subjects);
    }

    private function dependency(SymbolPath $source, SymbolPath $target, DependencyType $type, Location $location): Dependency
    {
        return new Dependency(
            DeclarationPath::of($source, $location->file ?? RelativePath::fromString('test.php'), DeclarationOrdinal::fromRank(0)),
            new LogicalClassPath($target),
            $type,
            $location,
        );
    }
    private static function subjectInfo(\Qualimetrix\Core\Symbol\SymbolPath $symbolPath, ?\Qualimetrix\Core\Path\RelativePath $file, ?int $line): \Qualimetrix\Core\Symbol\SymbolInfo
    {
        $type = $symbolPath->getType();
        if (\in_array($type, [\Qualimetrix\Core\Symbol\SymbolType::File, \Qualimetrix\Core\Symbol\SymbolType::Namespace_, \Qualimetrix\Core\Symbol\SymbolType::Project], true)) {
            return new \Qualimetrix\Core\Symbol\SymbolInfo(\Qualimetrix\Core\Symbol\MetricSubject::aggregate($symbolPath), $file, $line);
        }

        \assert($file !== null);
        $kind = $type === \Qualimetrix\Core\Symbol\SymbolType::Class_ ? null : ($type === \Qualimetrix\Core\Symbol\SymbolType::Function_ ? \Qualimetrix\Core\Symbol\CallableKind::Function : \Qualimetrix\Core\Symbol\CallableKind::Method);

        return new \Qualimetrix\Core\Symbol\SymbolInfo(
            \Qualimetrix\Core\Symbol\MetricSubject::declaration(\Qualimetrix\Core\Symbol\DeclarationPath::of($symbolPath, $file, \Qualimetrix\Core\Symbol\DeclarationOrdinal::fromRank(0))),
            $file,
            $line,
            $kind,
        );
    }
}
