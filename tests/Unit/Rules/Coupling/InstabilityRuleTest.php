<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Rules\Coupling;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Rules\Coupling\ClassInstabilityOptions;
use Qualimetrix\Rules\Coupling\InstabilityOptions;
use Qualimetrix\Rules\Coupling\InstabilityRule;
use Qualimetrix\Rules\Coupling\NamespaceInstabilityOptions;

#[CoversClass(InstabilityRule::class)]
#[CoversClass(InstabilityOptions::class)]
#[CoversClass(ClassInstabilityOptions::class)]
#[CoversClass(NamespaceInstabilityOptions::class)]
final class InstabilityRuleTest extends TestCase
{
    #[Test]
    public function itReturnsCorrectName(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        self::assertSame('coupling.instability', $rule->getName());
    }

    #[Test]
    public function itReturnsCorrectDescription(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        self::assertSame(
            'Checks instability at class and namespace levels',
            $rule->getDescription(),
        );
    }

    #[Test]
    public function itReturnsCouplingCategory(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        self::assertSame(RuleCategory::Coupling, $rule->getCategory());
    }

    #[Test]
    public function itRequiresInstabilityMetrics(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        self::assertSame(['instability', 'ca', 'ce'], $rule->requires());
    }

    #[Test]
    public function itReturnsCorrectOptionsClass(): void
    {
        self::assertSame(
            InstabilityOptions::class,
            InstabilityRule::getOptionsClass(),
        );
    }

    #[Test]
    public function itReturnsClassAndNamespaceLevels(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        self::assertSame([RuleLevel::Class_, RuleLevel::Namespace_], $rule->getSupportedLevels());
    }

    #[Test]
    public function itDeclaresCorrectCliAliases(): void
    {
        self::assertSame([
            'instability-class-warning' => 'class.max_warning',
            'instability-class-error' => 'class.max_error',
            'instability-ns-warning' => 'namespace.max_warning',
            'instability-ns-error' => 'namespace.max_error',
        ], CliAliasReader::read(InstabilityRule::class));
    }

    #[Test]
    public function itThrowsForInvalidOptionsType(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Expected');

        $invalidOptions = self::createStub(\Qualimetrix\Core\Rule\RuleOptionsInterface::class);
        new InstabilityRule($invalidOptions);
    }

    // Class-level tests

    #[Test]
    public function itReturnsEmptyWhenClassLevelDisabled(): void
    {
        $rule = new InstabilityRule(
            new InstabilityOptions(
                class: new ClassInstabilityOptions(enabled: false),
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
        $rule = new InstabilityRule(new InstabilityOptions());

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([]);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(RuleLevel::Class_, $context));
    }

    #[Test]
    public function itSkipsClassesWithoutInstabilityMetric(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

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
    public function itGeneratesClassInstabilityWarning(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        // 0.85 is above warning (0.8), below error (0.95)
        $metricBag = (new MetricBag())
            ->with('instability', 0.85)
            ->with('ca', 2)
            ->with('ce', 12);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertSame('Instability is 0.85 (Ca=2, Ce=12), exceeds threshold of 0.80. Reduce outgoing dependencies', $violations[0]->message);
        self::assertSame(0.85, $violations[0]->metricValue);
        self::assertSame('coupling.instability', $violations[0]->ruleName);
        self::assertSame('coupling.instability.class', $violations[0]->violationCode);
        self::assertSame(RuleLevel::Class_, $violations[0]->level);
    }

    #[Test]
    public function itGeneratesClassInstabilityError(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        // 0.97 is above error (0.95)
        $metricBag = (new MetricBag())
            ->with('instability', 0.97)
            ->with('ca', 1)
            ->with('ce', 32);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame(0.97, $violations[0]->metricValue);
    }

    #[Test]
    public function itSkipsClassBelowDefaultMinAfferent(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'LeafService');
        $classInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service/LeafService.php'), 10);

        // Ca=0, below default minAfferent=1, should be skipped
        $metricBag = (new MetricBag())
            ->with('instability', 1.0)
            ->with('ca', 0)
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
    public function itDoesNotSkipClassWhenMinAfferentIsZero(): void
    {
        $rule = new InstabilityRule(
            new InstabilityOptions(
                class: new ClassInstabilityOptions(minAfferent: 0),
            ),
        );

        $symbolPath = SymbolPath::forClass('App\Service', 'LeafService');
        $classInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service/LeafService.php'), 10);

        // Ca=0, but minAfferent=0 means check all classes
        $metricBag = (new MetricBag())
            ->with('instability', 1.0)
            ->with('ca', 0)
            ->with('ce', 5);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
    }

    #[Test]
    public function itSkipsClassWhenCaOneBelowMinAfferentTwo(): void
    {
        $rule = new InstabilityRule(
            new InstabilityOptions(
                class: new ClassInstabilityOptions(minAfferent: 2),
            ),
        );

        $symbolPath = SymbolPath::forClass('App\Service', 'NearLeafService');
        $classInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service/NearLeafService.php'), 10);

        // Ca=1, below minAfferent=2, should be skipped
        $metricBag = (new MetricBag())
            ->with('instability', 0.92)
            ->with('ca', 1)
            ->with('ce', 11);

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
    public function itDoesNotSkipClassWhenCaTwoMeetsMinAfferentTwo(): void
    {
        $rule = new InstabilityRule(
            new InstabilityOptions(
                class: new ClassInstabilityOptions(minAfferent: 2),
            ),
        );

        $symbolPath = SymbolPath::forClass('App\Service', 'CoreService');
        $classInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service/CoreService.php'), 10);

        // Ca=2, meets minAfferent=2, should NOT be skipped
        $metricBag = (new MetricBag())
            ->with('instability', 0.85)
            ->with('ca', 2)
            ->with('ce', 12);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
    }

    // Namespace-level tests

    #[Test]
    public function itReturnsEmptyWhenNamespaceLevelDisabled(): void
    {
        $rule = new InstabilityRule(
            new InstabilityOptions(
                namespace: new NamespaceInstabilityOptions(enabled: false),
            ),
        );

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(RuleLevel::Namespace_, $context));
    }

    #[Test]
    public function itGeneratesNamespaceInstabilityWarning(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service'), null);

        // 0.88 is above warning (0.8), below error (0.95)
        $metricBag = (new MetricBag())
            ->with('instability', 0.88)
            ->with('ca', 3)
            ->with('ce', 22)
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
        self::assertStringContainsString('Instability is 0.88 (Ca=3, Ce=22), exceeds threshold of 0.80. Reduce outgoing dependencies', $violations[0]->message);
        self::assertSame('coupling.instability.namespace', $violations[0]->violationCode);
        self::assertSame(RuleLevel::Namespace_, $violations[0]->level);
    }

    #[Test]
    public function itGeneratesNamespaceInstabilityError(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service'), null);

        // 0.98 is above error (0.95)
        $metricBag = (new MetricBag())
            ->with('instability', 0.98)
            ->with('ca', 1)
            ->with('ce', 49)
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
        self::assertSame(0.98, $violations[0]->metricValue);
    }

    #[Test]
    public function itSkipsNamespaceBelowDefaultMinAfferent(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service'), null);

        // Ca=0, below default minAfferent=1, should be skipped
        $metricBag = (new MetricBag())
            ->with('instability', 1.0)
            ->with('ca', 0)
            ->with('ce', 10)
            ->with('classCount.sum', 5);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Namespace_, $context);

        self::assertCount(0, $violations);
    }

    #[Test]
    public function itDoesNotSkipNamespaceWhenMinAfferentIsZero(): void
    {
        $rule = new InstabilityRule(
            new InstabilityOptions(
                namespace: new NamespaceInstabilityOptions(minAfferent: 0),
            ),
        );

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service'), null);

        // Ca=0, but minAfferent=0 means check all namespaces
        $metricBag = (new MetricBag())
            ->with('instability', 1.0)
            ->with('ca', 0)
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
        self::assertSame(Severity::Error, $violations[0]->severity);
    }

    #[Test]
    public function itSkipsNamespaceWhenCaOneBelowMinAfferentTwo(): void
    {
        $rule = new InstabilityRule(
            new InstabilityOptions(
                namespace: new NamespaceInstabilityOptions(minAfferent: 2),
            ),
        );

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service'), null);

        // Ca=1, below minAfferent=2, should be skipped
        $metricBag = (new MetricBag())
            ->with('instability', 0.92)
            ->with('ca', 1)
            ->with('ce', 11)
            ->with('classCount.sum', 5);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Namespace_, $context);

        self::assertCount(0, $violations);
    }

    // Namespace minClassCount tests

    #[Test]
    public function itSkipsNamespaceBelowMinClassCount(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service'), null);

        // classCount.sum = 1, below default minClassCount (3)
        $metricBag = (new MetricBag())
            ->with('instability', 0.98)
            ->with('ca', 1)
            ->with('ce', 49)
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

    #[Test]
    public function itChecksNamespaceWhenAboveMinClassCount(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = new SymbolInfo($symbolPath, RelativePath::fromString('src/Service'), null);

        // classCount.sum = 5, above default minClassCount (3)
        $metricBag = (new MetricBag())
            ->with('instability', 0.98)
            ->with('ca', 1)
            ->with('ce', 49)
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
    }

    // Legacy analyze() tests

    #[Test]
    public function itAnalyzesBothLevels(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        $classPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($classPath, RelativePath::fromString('src/Service/UserService.php'), 10);

        $nsPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = new SymbolInfo($nsPath, RelativePath::fromString('src/Service'), null);

        $classBag = (new MetricBag())
            ->with('instability', 0.85)
            ->with('ca', 2)
            ->with('ce', 12);
        $nsBag = (new MetricBag())
            ->with('instability', 0.88)
            ->with('ca', 3)
            ->with('ce', 22)
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
        $options = ClassInstabilityOptions::fromArray([
            'enabled' => false,
            'max_warning' => 0.7,
            'max_error' => 0.9,
        ]);

        self::assertFalse($options->enabled);
        self::assertSame(0.7, $options->maxWarning);
        self::assertSame(0.9, $options->maxError);
    }

    #[Test]
    public function itUsesClassOptionDefaults(): void
    {
        $options = ClassInstabilityOptions::fromArray([]);

        self::assertTrue($options->enabled);
        self::assertSame(0.8, $options->maxWarning);
        self::assertSame(0.95, $options->maxError);
    }

    #[Test]
    public function itParsesNamespaceOptionsFromArray(): void
    {
        $options = NamespaceInstabilityOptions::fromArray([
            'enabled' => false,
            'max_warning' => 0.75,
            'max_error' => 0.92,
        ]);

        self::assertFalse($options->enabled);
        self::assertSame(0.75, $options->maxWarning);
        self::assertSame(0.92, $options->maxError);
    }

    #[Test]
    public function itParsesInstabilityOptionsFromHierarchicalArray(): void
    {
        $options = InstabilityOptions::fromArray([
            'class' => [
                'max_warning' => 0.7,
                'max_error' => 0.9,
            ],
            'namespace' => [
                'max_warning' => 0.75,
                'max_error' => 0.92,
            ],
        ]);

        self::assertTrue($options->isEnabled());
        self::assertTrue($options->class->isEnabled());
        self::assertSame(0.7, $options->class->maxWarning);
        self::assertTrue($options->namespace->isEnabled());
        self::assertSame(0.75, $options->namespace->maxWarning);
    }

    #[Test]
    public function itReturnsCorrectOptionsForLevel(): void
    {
        $options = new InstabilityOptions();

        self::assertSame($options->class, $options->forLevel(RuleLevel::Class_));
        self::assertSame($options->namespace, $options->forLevel(RuleLevel::Namespace_));
    }

    #[Test]
    public function itThrowsForUnsupportedLevel(): void
    {
        $options = new InstabilityOptions();

        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Level method is not supported by InstabilityRule');

        $options->forLevel(RuleLevel::Method);
    }

    #[Test]
    public function itChecksWhetherLevelIsEnabled(): void
    {
        $options = new InstabilityOptions(
            class: new ClassInstabilityOptions(enabled: true),
            namespace: new NamespaceInstabilityOptions(enabled: false),
        );

        self::assertTrue($options->isLevelEnabled(RuleLevel::Class_));
        self::assertFalse($options->isLevelEnabled(RuleLevel::Namespace_));
    }

    #[Test]
    public function itGetsSupportedLevels(): void
    {
        $options = new InstabilityOptions();

        self::assertSame([RuleLevel::Class_, RuleLevel::Namespace_], $options->getSupportedLevels());
    }

    #[Test]
    public function itParsesNamespaceMinClassCountFromArray(): void
    {
        $options = NamespaceInstabilityOptions::fromArray([
            'min_class_count' => 5,
        ]);

        self::assertSame(5, $options->minClassCount);
    }

    #[Test]
    public function itDefaultsNamespaceMinClassCountToThree(): void
    {
        $options = NamespaceInstabilityOptions::fromArray([
            'enabled' => true,
        ]);

        self::assertSame(3, $options->minClassCount);
    }

    #[Test]
    public function itParsesNamespaceMinClassCountCamelCaseAlias(): void
    {
        $options = NamespaceInstabilityOptions::fromArray([
            'minClassCount' => 7,
        ]);

        self::assertSame(7, $options->minClassCount);
    }

    #[Test]
    #[DataProvider('instabilityThresholdDataProvider')]
    public function itRespectsInstabilityThresholdBoundaries(
        float $instability,
        float $warning,
        float $error,
        ?Severity $expectedSeverity,
    ): void {
        $rule = new InstabilityRule(
            new InstabilityOptions(
                class: new ClassInstabilityOptions(
                    maxWarning: $warning,
                    maxError: $error,
                ),
            ),
        );

        $symbolPath = SymbolPath::forClass('App', 'Test');
        $classInfo = new SymbolInfo($symbolPath, RelativePath::fromString('test.php'), 1);

        $metricBag = (new MetricBag())
            ->with('instability', $instability)
            ->with('ca', 5)
            ->with('ce', 10);

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
     * @return iterable<string, array{float, float, float, ?Severity}>
     */
    public static function instabilityThresholdDataProvider(): iterable
    {
        yield 'below warning threshold' => [0.79, 0.8, 0.95, null];
        yield 'at warning threshold' => [0.8, 0.8, 0.95, Severity::Warning];
        yield 'above warning, below error' => [0.9, 0.8, 0.95, Severity::Warning];
        yield 'at error threshold' => [0.95, 0.8, 0.95, Severity::Error];
        yield 'above error threshold' => [1.0, 0.8, 0.95, Severity::Error];
    }

    #[Test]
    public function itParsesClassMinAfferentFromArray(): void
    {
        $options = ClassInstabilityOptions::fromArray([
            'min_afferent' => 3,
        ]);

        self::assertSame(3, $options->minAfferent);
    }

    #[Test]
    public function itParsesClassMinAfferentCamelCaseAlias(): void
    {
        $options = ClassInstabilityOptions::fromArray([
            'minAfferent' => 5,
        ]);

        self::assertSame(5, $options->minAfferent);
    }

    #[Test]
    public function itDefaultsClassMinAfferentToOne(): void
    {
        $options = ClassInstabilityOptions::fromArray([]);

        self::assertSame(1, $options->minAfferent);
    }

    #[Test]
    public function itPreservesClassMinAfferentOnOverride(): void
    {
        $options = new ClassInstabilityOptions(minAfferent: 3);
        $overridden = $options->withOverride(0.9, null);

        self::assertSame(3, $overridden->minAfferent);
        self::assertSame(0.9, $overridden->maxWarning);
    }

    #[Test]
    public function itParsesNamespaceMinAfferentFromArray(): void
    {
        $options = NamespaceInstabilityOptions::fromArray([
            'min_afferent' => 2,
        ]);

        self::assertSame(2, $options->minAfferent);
    }

    #[Test]
    public function itDefaultsNamespaceMinAfferentToOne(): void
    {
        $options = NamespaceInstabilityOptions::fromArray([
            'enabled' => true,
        ]);

        self::assertSame(1, $options->minAfferent);
    }

    #[Test]
    public function itPreservesNamespaceMinAfferentOnOverride(): void
    {
        $options = new NamespaceInstabilityOptions(minAfferent: 4);
        $overridden = $options->withOverride(0.9, null);

        self::assertSame(4, $overridden->minAfferent);
        self::assertSame(0.9, $overridden->maxWarning);
        self::assertSame(3, $overridden->minClassCount);
    }

    #[Test]
    public function itParsesClassOptionsFromArrayWithCamelCase(): void
    {
        $options = ClassInstabilityOptions::fromArray([
            'enabled' => false,
            'maxWarning' => 0.7,
            'maxError' => 0.9,
        ]);

        self::assertFalse($options->enabled);
        self::assertSame(0.7, $options->maxWarning);
        self::assertSame(0.9, $options->maxError);
    }

    #[Test]
    public function itParsesNamespaceOptionsFromArrayWithCamelCase(): void
    {
        $options = NamespaceInstabilityOptions::fromArray([
            'enabled' => false,
            'maxWarning' => 0.75,
            'maxError' => 0.92,
        ]);

        self::assertFalse($options->enabled);
        self::assertSame(0.75, $options->maxWarning);
        self::assertSame(0.92, $options->maxError);
    }
}
