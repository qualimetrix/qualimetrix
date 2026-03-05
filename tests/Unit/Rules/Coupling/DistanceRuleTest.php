<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Rules\Coupling;

use AiMessDetector\Core\Metric\MetricBag;
use AiMessDetector\Core\Metric\MetricRepositoryInterface;
use AiMessDetector\Core\Rule\AnalysisContext;
use AiMessDetector\Core\Rule\RuleCategory;
use AiMessDetector\Core\Symbol\SymbolInfo;
use AiMessDetector\Core\Symbol\SymbolType;
use AiMessDetector\Core\Violation\Severity;
use AiMessDetector\Core\Violation\SymbolPath;
use AiMessDetector\Rules\Coupling\DistanceOptions;
use AiMessDetector\Rules\Coupling\DistanceRule;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(DistanceRule::class)]
#[CoversClass(DistanceOptions::class)]
final class DistanceRuleTest extends TestCase
{
    public function testGetName(): void
    {
        $rule = new DistanceRule(new DistanceOptions(includeNamespaces: ['App'], minClassCount: 0));

        self::assertSame('coupling.distance', $rule->getName());
    }

    public function testGetDescription(): void
    {
        $rule = new DistanceRule(new DistanceOptions(includeNamespaces: ['App'], minClassCount: 0));

        self::assertSame(
            'Checks distance from main sequence at namespace level',
            $rule->getDescription(),
        );
    }

    public function testGetCategory(): void
    {
        $rule = new DistanceRule(new DistanceOptions(includeNamespaces: ['App'], minClassCount: 0));

        self::assertSame(RuleCategory::Coupling, $rule->getCategory());
    }

    public function testRequires(): void
    {
        $rule = new DistanceRule(new DistanceOptions(includeNamespaces: ['App'], minClassCount: 0));

        self::assertSame(['distance', 'abstractness', 'instability'], $rule->requires());
    }

    public function testGetOptionsClass(): void
    {
        self::assertSame(
            DistanceOptions::class,
            DistanceRule::getOptionsClass(),
        );
    }

    public function testGetCliAliases(): void
    {
        self::assertSame([
            'distance-warning' => 'max_distance_warning',
            'distance-error' => 'max_distance_error',
        ], DistanceRule::getCliAliases());
    }

    public function testAnalyzeReturnsEmptyWhenDisabled(): void
    {
        $rule = new DistanceRule(
            new DistanceOptions(enabled: false),
        );

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    public function testAnalyzeReturnsEmptyWhenNoNamespaces(): void
    {
        $rule = new DistanceRule(new DistanceOptions(includeNamespaces: ['App'], minClassCount: 0));

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Namespace_)
            ->willReturn([]);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    public function testAnalyzeSkipsWhenNoDistanceMetric(): void
    {
        $rule = new DistanceRule(new DistanceOptions(includeNamespaces: ['App'], minClassCount: 0));

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = new SymbolInfo($symbolPath, 'src/Service', null);

        $metricBag = new MetricBag();

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Namespace_)
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    public function testAnalyzeGeneratesWarning(): void
    {
        $rule = new DistanceRule(new DistanceOptions(includeNamespaces: ['App'], minClassCount: 0));

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = new SymbolInfo($symbolPath, 'src/Service', null);

        // 0.35 is above warning (0.3), below error (0.5)
        $metricBag = (new MetricBag())
            ->with('distance', 0.35)
            ->with('abstractness', 0.2)
            ->with('instability', 0.45);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Namespace_)
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertSame(
            'Distance from main sequence is 0.35 (A=0.20, I=0.45), exceeds threshold of 0.30. Balance abstractness and stability',
            $violations[0]->message,
        );
        self::assertSame(0.35, $violations[0]->metricValue);
        self::assertSame('coupling.distance', $violations[0]->ruleName);
    }

    public function testAnalyzeGeneratesError(): void
    {
        $rule = new DistanceRule(new DistanceOptions(includeNamespaces: ['App'], minClassCount: 0));

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = new SymbolInfo($symbolPath, 'src/Service', null);

        // 0.6 is above error (0.5)
        $metricBag = (new MetricBag())
            ->with('distance', 0.6)
            ->with('abstractness', 0.1)
            ->with('instability', 0.3);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Namespace_)
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame(0.6, $violations[0]->metricValue);
    }

    public function testAnalyzeNoViolationWhenOnMainSequence(): void
    {
        $rule = new DistanceRule(new DistanceOptions(includeNamespaces: ['App'], minClassCount: 0));

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = new SymbolInfo($symbolPath, 'src/Service', null);

        // Distance close to 0 = on main sequence
        $metricBag = (new MetricBag())
            ->with('distance', 0.1)
            ->with('abstractness', 0.5)
            ->with('instability', 0.5);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Namespace_)
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(0, $violations);
    }

    public function testAnalyzeMultipleNamespaces(): void
    {
        $rule = new DistanceRule(new DistanceOptions(includeNamespaces: ['App'], minClassCount: 0));

        $nsPath1 = SymbolPath::forNamespace('App\Service');
        $nsInfo1 = new SymbolInfo($nsPath1, 'src/Service', null);

        $nsPath2 = SymbolPath::forNamespace('App\Controller');
        $nsInfo2 = new SymbolInfo($nsPath2, 'src/Controller', null);

        $nsBag1 = (new MetricBag())
            ->with('distance', 0.4) // Warning
            ->with('abstractness', 0.1)
            ->with('instability', 0.5);
        $nsBag2 = (new MetricBag())
            ->with('distance', 0.55) // Error
            ->with('abstractness', 0.0)
            ->with('instability', 0.45);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Namespace_)
            ->willReturn([$nsInfo1, $nsInfo2]);
        $repository->method('get')
            ->willReturnCallback(fn(SymbolPath $path) => match ($path) {
                $nsPath1 => $nsBag1,
                $nsPath2 => $nsBag2,
                default => new MetricBag(),
            });

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(2, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertSame(Severity::Error, $violations[1]->severity);
    }

    // Options tests

    public function testOptionsFromArray(): void
    {
        $options = DistanceOptions::fromArray([
            'enabled' => false,
            'max_distance_warning' => 0.25,
            'max_distance_error' => 0.4,
        ]);

        self::assertFalse($options->enabled);
        self::assertSame(0.25, $options->maxDistanceWarning);
        self::assertSame(0.4, $options->maxDistanceError);
    }

    public function testOptionsFromArrayWithLegacyKeys(): void
    {
        $options = DistanceOptions::fromArray([
            'maxDistanceWarning' => 0.25,
            'maxDistanceError' => 0.4,
        ]);

        self::assertTrue($options->enabled);
        self::assertSame(0.25, $options->maxDistanceWarning);
        self::assertSame(0.4, $options->maxDistanceError);
    }

    public function testOptionsFromEmptyArray(): void
    {
        $options = DistanceOptions::fromArray([]);

        self::assertTrue($options->enabled);
        self::assertSame(0.3, $options->maxDistanceWarning);
        self::assertSame(0.5, $options->maxDistanceError);
    }

    public function testOptionsGetSeverity(): void
    {
        $options = new DistanceOptions(maxDistanceWarning: 0.3, maxDistanceError: 0.5);

        self::assertNull($options->getSeverity(0.29));
        self::assertSame(Severity::Warning, $options->getSeverity(0.3));
        self::assertSame(Severity::Warning, $options->getSeverity(0.4));
        self::assertSame(Severity::Error, $options->getSeverity(0.5));
        self::assertSame(Severity::Error, $options->getSeverity(1.0));
    }

    #[DataProvider('distanceThresholdDataProvider')]
    public function testDistanceThresholdBoundaries(
        float $distance,
        float $warning,
        float $error,
        ?Severity $expectedSeverity,
    ): void {
        $rule = new DistanceRule(
            new DistanceOptions(maxDistanceWarning: $warning, maxDistanceError: $error, includeNamespaces: ['App'], minClassCount: 0),
        );

        $symbolPath = SymbolPath::forNamespace('App');
        $nsInfo = new SymbolInfo($symbolPath, 'src', null);

        $metricBag = (new MetricBag())
            ->with('distance', $distance)
            ->with('abstractness', 0.0)
            ->with('instability', 0.0);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Namespace_)
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

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
    public static function distanceThresholdDataProvider(): iterable
    {
        yield 'below warning threshold' => [0.29, 0.3, 0.5, null];
        yield 'at warning threshold' => [0.3, 0.3, 0.5, Severity::Warning];
        yield 'above warning, below error' => [0.4, 0.3, 0.5, Severity::Warning];
        yield 'at error threshold' => [0.5, 0.3, 0.5, Severity::Error];
        yield 'above error threshold' => [0.8, 0.3, 0.5, Severity::Error];
        yield 'maximum distance' => [1.0, 0.3, 0.5, Severity::Error];
    }

    public function testAnalyzeSkipsNamespaceWithTooFewClasses(): void
    {
        $rule = new DistanceRule(
            new DistanceOptions(includeNamespaces: ['App'], minClassCount: 3),
        );

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = new SymbolInfo($symbolPath, 'src/Service', null);

        // classCount.sum=2 is below minClassCount=3, so no violation despite high distance
        $metricBag = (new MetricBag())
            ->with('distance', 0.6)
            ->with('abstractness', 0.1)
            ->with('instability', 0.3)
            ->with('classCount.sum', 2);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Namespace_)
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(0, $violations);
    }

    public function testAnalyzeReportsViolationWhenClassCountMeetsMinimum(): void
    {
        $rule = new DistanceRule(
            new DistanceOptions(includeNamespaces: ['App'], minClassCount: 3),
        );

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = new SymbolInfo($symbolPath, 'src/Service', null);

        // classCount.sum=3 meets minClassCount=3, so violation is reported
        $metricBag = (new MetricBag())
            ->with('distance', 0.6)
            ->with('abstractness', 0.1)
            ->with('instability', 0.3)
            ->with('classCount.sum', 3);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Namespace_)
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
    }

    public function testAnalyzeWithMinClassCountZeroAnalyzesAll(): void
    {
        $rule = new DistanceRule(
            new DistanceOptions(includeNamespaces: ['App'], minClassCount: 0),
        );

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = new SymbolInfo($symbolPath, 'src/Service', null);

        // No classCount.sum metric at all, but minClassCount=0 so it should still be analyzed
        $metricBag = (new MetricBag())
            ->with('distance', 0.6)
            ->with('abstractness', 0.1)
            ->with('instability', 0.3);

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->method('all')
            ->with(SymbolType::Namespace_)
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->with($symbolPath)
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
    }

    public function testOptionsFromArrayParsesMinClassCount(): void
    {
        $options = DistanceOptions::fromArray([
            'min_class_count' => 5,
        ]);

        self::assertSame(5, $options->minClassCount);
    }

    public function testOptionsFromArrayParsesMinClassCountCamelCase(): void
    {
        $options = DistanceOptions::fromArray([
            'minClassCount' => 7,
        ]);

        self::assertSame(7, $options->minClassCount);
    }

    public function testOptionsFromArrayDefaultsMinClassCount(): void
    {
        $options = DistanceOptions::fromArray([]);

        self::assertSame(3, $options->minClassCount);
    }

    public function testConstructorThrowsForInvalidOptions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected');

        $invalidOptions = $this->createMock(\AiMessDetector\Core\Rule\RuleOptionsInterface::class);
        new DistanceRule($invalidOptions);
    }
}
