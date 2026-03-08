<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Rules\Coupling;

use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricRepositoryInterface;
use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Rule\RuleLevel;
use AiMessDetector\Core\Symbol\SymbolInfo;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\SymbolPath;
use AiMessDetector\Rules\Coupling\ClassInstabilityOptions;
use AiMessDetector\Rules\Coupling\InstabilityOptions;
use AiMessDetector\Rules\Coupling\InstabilityRule;
use AiMessDetector\Rules\Coupling\NamespaceInstabilityOptions;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(InstabilityRule::class)]
#[CoversClass(InstabilityOptions::class)]
#[CoversClass(ClassInstabilityOptions::class)]
#[CoversClass(NamespaceInstabilityOptions::class)]
final class InstabilityRuleTest extends TestCase
{
    public function testGetName(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        self::assertSame('coupling.instability', $rule->getName());
    }

    public function testGetDescription(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        self::assertSame(
            'Checks instability at class and namespace levels',
            $rule->getDescription(),
        );
    }

    public function testGetCategory(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        self::assertSame(RuleCategory::Coupling, $rule->getCategory());
    }

    public function testRequires(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        self::assertSame(['instability', 'ca', 'ce'], $rule->requires());
    }

    public function testGetOptionsClass(): void
    {
        self::assertSame(
            InstabilityOptions::class,
            InstabilityRule::getOptionsClass(),
        );
    }

    public function testGetSupportedLevels(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        self::assertSame([RuleLevel::Class_, RuleLevel::Namespace_], $rule->getSupportedLevels());
    }

    public function testGetCliAliases(): void
    {
        self::assertSame([
            'instability-class-warning' => 'class.max_warning',
            'instability-class-error' => 'class.max_error',
            'instability-ns-warning' => 'namespace.max_warning',
            'instability-ns-error' => 'namespace.max_error',
        ], InstabilityRule::getCliAliases());
    }

    public function testConstructorThrowsForInvalidOptions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected');

        $invalidOptions = $this->createMock(\AiMessDetector\Core\Rule\RuleOptionsInterface::class);
        new InstabilityRule($invalidOptions);
    }

    // Class-level tests

    public function testAnalyzeLevelClassReturnsEmptyWhenDisabled(): void
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

    public function testAnalyzeLevelClassReturnsEmptyWhenNoClasses(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([]);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(RuleLevel::Class_, $context));
    }

    public function testAnalyzeLevelClassSkipsWhenNoInstabilityMetric(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        $metricBag = new MetricBag();

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyzeLevel(RuleLevel::Class_, $context));
    }

    public function testAnalyzeLevelClassGeneratesWarning(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        // 0.85 is above warning (0.8), below error (0.95)
        $metricBag = (new MetricBag())
            ->with('instability', 0.85)
            ->with('ca', 2)
            ->with('ce', 12);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->with($symbolPath)
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

    public function testAnalyzeLevelClassGeneratesError(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        $symbolPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($symbolPath, 'src/Service/UserService.php', 10);

        // 0.97 is above error (0.95)
        $metricBag = (new MetricBag())
            ->with('instability', 0.97)
            ->with('ca', 1)
            ->with('ce', 32);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Class_, $context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame(0.97, $violations[0]->metricValue);
    }

    // Namespace-level tests

    public function testAnalyzeLevelNamespaceReturnsEmptyWhenDisabled(): void
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

    public function testAnalyzeLevelNamespaceGeneratesWarning(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = new SymbolInfo($symbolPath, 'src/Service', null);

        // 0.88 is above warning (0.8), below error (0.95)
        $metricBag = (new MetricBag())
            ->with('instability', 0.88)
            ->with('ca', 3)
            ->with('ce', 22)
            ->with('classCount.sum', 5);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Namespace_)
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Namespace_, $context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertStringContainsString('Instability is 0.88 (Ca=3, Ce=22), exceeds threshold of 0.80. Reduce outgoing dependencies', $violations[0]->message);
        self::assertSame('coupling.instability.namespace', $violations[0]->violationCode);
        self::assertSame(RuleLevel::Namespace_, $violations[0]->level);
    }

    public function testAnalyzeLevelNamespaceGeneratesError(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = new SymbolInfo($symbolPath, 'src/Service', null);

        // 0.98 is above error (0.95)
        $metricBag = (new MetricBag())
            ->with('instability', 0.98)
            ->with('ca', 1)
            ->with('ce', 49)
            ->with('classCount.sum', 5);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Namespace_)
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Namespace_, $context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame(0.98, $violations[0]->metricValue);
    }

    // Namespace minClassCount tests

    public function testAnalyzeLevelNamespaceSkipsWhenBelowMinClassCount(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = new SymbolInfo($symbolPath, 'src/Service', null);

        // classCount.sum = 1, below default minClassCount (3)
        $metricBag = (new MetricBag())
            ->with('instability', 0.98)
            ->with('ca', 1)
            ->with('ce', 49)
            ->with('classCount.sum', 1);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Namespace_)
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Namespace_, $context);

        self::assertCount(0, $violations);
    }

    public function testAnalyzeLevelNamespaceChecksWhenAboveMinClassCount(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = new SymbolInfo($symbolPath, 'src/Service', null);

        // classCount.sum = 5, above default minClassCount (3)
        $metricBag = (new MetricBag())
            ->with('instability', 0.98)
            ->with('ca', 1)
            ->with('ce', 49)
            ->with('classCount.sum', 5);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Namespace_)
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Namespace_, $context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
    }

    // Legacy analyze() tests

    public function testAnalyzeCallsBothLevels(): void
    {
        $rule = new InstabilityRule(new InstabilityOptions());

        $classPath = SymbolPath::forClass('App\Service', 'UserService');
        $classInfo = new SymbolInfo($classPath, 'src/Service/UserService.php', 10);

        $nsPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = new SymbolInfo($nsPath, 'src/Service', null);

        $classBag = (new MetricBag())
            ->with('instability', 0.85)
            ->with('ca', 2)
            ->with('ce', 12);
        $nsBag = (new MetricBag())
            ->with('instability', 0.88)
            ->with('ca', 3)
            ->with('ce', 22)
            ->with('classCount.sum', 5);

        $repository = $this->createMock(MetricRepositoryInterface::class);
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
        $options = ClassInstabilityOptions::fromArray([
            'enabled' => false,
            'max_warning' => 0.7,
            'max_error' => 0.9,
        ]);

        self::assertFalse($options->enabled);
        self::assertSame(0.7, $options->maxWarning);
        self::assertSame(0.9, $options->maxError);
    }

    public function testClassOptionsFromEmptyArray(): void
    {
        $options = ClassInstabilityOptions::fromArray([]);

        self::assertTrue($options->enabled);
        self::assertSame(0.8, $options->maxWarning);
        self::assertSame(0.95, $options->maxError);
    }

    public function testNamespaceOptionsFromArray(): void
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

    public function testInstabilityOptionsFromHierarchicalArray(): void
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

    public function testInstabilityOptionsForLevel(): void
    {
        $options = new InstabilityOptions();

        self::assertSame($options->class, $options->forLevel(RuleLevel::Class_));
        self::assertSame($options->namespace, $options->forLevel(RuleLevel::Namespace_));
    }

    public function testInstabilityOptionsForLevelThrowsForUnsupportedLevel(): void
    {
        $options = new InstabilityOptions();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Level method is not supported by InstabilityRule');

        $options->forLevel(RuleLevel::Method);
    }

    public function testInstabilityOptionsIsLevelEnabled(): void
    {
        $options = new InstabilityOptions(
            class: new ClassInstabilityOptions(enabled: true),
            namespace: new NamespaceInstabilityOptions(enabled: false),
        );

        self::assertTrue($options->isLevelEnabled(RuleLevel::Class_));
        self::assertFalse($options->isLevelEnabled(RuleLevel::Namespace_));
    }

    public function testInstabilityOptionsGetSupportedLevels(): void
    {
        $options = new InstabilityOptions();

        self::assertSame([RuleLevel::Class_, RuleLevel::Namespace_], $options->getSupportedLevels());
    }

    public function testNamespaceOptionsFromArrayIncludesMinClassCount(): void
    {
        $options = NamespaceInstabilityOptions::fromArray([
            'min_class_count' => 5,
        ]);

        self::assertSame(5, $options->minClassCount);
    }

    public function testNamespaceOptionsFromArrayMinClassCountDefaultsToThree(): void
    {
        $options = NamespaceInstabilityOptions::fromArray([
            'enabled' => true,
        ]);

        self::assertSame(3, $options->minClassCount);
    }

    public function testNamespaceOptionsFromArrayMinClassCountCamelCaseAlias(): void
    {
        $options = NamespaceInstabilityOptions::fromArray([
            'minClassCount' => 7,
        ]);

        self::assertSame(7, $options->minClassCount);
    }

    public function testNamespaceOptionsFromArrayParsesExcludeNamespaces(): void
    {
        $options = NamespaceInstabilityOptions::fromArray([
            'exclude_namespaces' => ['App\Core', 'App\Shared'],
        ]);

        self::assertSame(['App\Core', 'App\Shared'], $options->excludeNamespaces);
    }

    public function testNamespaceOptionsFromArrayParsesExcludeNamespacesCamelCase(): void
    {
        $options = NamespaceInstabilityOptions::fromArray([
            'excludeNamespaces' => ['App\Core'],
        ]);

        self::assertSame(['App\Core'], $options->excludeNamespaces);
    }

    public function testNamespaceOptionsExcludeNamespacesDefaultsToEmpty(): void
    {
        $options = NamespaceInstabilityOptions::fromArray([
            'enabled' => true,
        ]);

        self::assertSame([], $options->excludeNamespaces);
    }

    public function testIsNamespaceExcludedExactMatch(): void
    {
        $options = new NamespaceInstabilityOptions(excludeNamespaces: ['App\Core']);

        self::assertTrue($options->isNamespaceExcluded('App\Core'));
        self::assertFalse($options->isNamespaceExcluded('App\Service'));
    }

    public function testIsNamespaceExcludedPrefixMatch(): void
    {
        $options = new NamespaceInstabilityOptions(excludeNamespaces: ['App\Core']);

        self::assertTrue($options->isNamespaceExcluded('App\Core\Exception'));
        self::assertTrue($options->isNamespaceExcluded('App\Core\Symbol\Deep'));
        self::assertFalse($options->isNamespaceExcluded('App\CoreExtra'));
    }

    public function testIsNamespaceExcludedTrailingBackslash(): void
    {
        $options = new NamespaceInstabilityOptions(excludeNamespaces: ['App\Core\\']);

        self::assertTrue($options->isNamespaceExcluded('App\Core'));
        self::assertTrue($options->isNamespaceExcluded('App\Core\Sub'));
    }

    public function testAnalyzeNamespaceLevelSkipsExcludedNamespace(): void
    {
        $rule = new InstabilityRule(
            new InstabilityOptions(
                namespace: new NamespaceInstabilityOptions(
                    excludeNamespaces: ['App\Core'],
                ),
            ),
        );

        $includedPath = SymbolPath::forNamespace('App\Service');
        $excludedPath = SymbolPath::forNamespace('App\Core\Symbol');

        $includedInfo = new SymbolInfo($includedPath, 'src/Service', null);
        $excludedInfo = new SymbolInfo($excludedPath, 'src/Core/Symbol', null);

        $violatingBag = (new MetricBag())
            ->with('instability', 0.98)
            ->with('ca', 1)
            ->with('ce', 49)
            ->with('classCount.sum', 5);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Namespace_)
            ->willReturn([$includedInfo, $excludedInfo]);
        $repository->method('get')
            ->willReturn($violatingBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyzeLevel(RuleLevel::Namespace_, $context);

        self::assertCount(1, $violations);
        self::assertSame('App\Service', $violations[0]->symbolPath->namespace);
    }

    #[DataProvider('instabilityThresholdDataProvider')]
    public function testInstabilityThresholdBoundaries(
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
        $classInfo = new SymbolInfo($symbolPath, 'test.php', 1);

        $metricBag = (new MetricBag())
            ->with('instability', $instability)
            ->with('ca', 5)
            ->with('ce', 10);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Class_)
            ->willReturn([$classInfo]);
        $repository->method('get')
            ->with($symbolPath)
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
}
