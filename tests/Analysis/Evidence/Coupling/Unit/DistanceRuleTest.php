<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Coupling\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Qualimetrix\Analysis\Evidence\Coupling\DistanceOptions;
use Qualimetrix\Analysis\Evidence\Coupling\DistanceRule;
use Qualimetrix\Analysis\Evidence\Measurement\Aggregation\AggregationHelper;
use Qualimetrix\Analysis\Evidence\Measurement\Aggregation\MetricAggregator;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\ProjectNamespaceResolverInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Evidence\Size\ClassCountCollector;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\CliAliasReader;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Profiler\Contract\ProfilerInterface;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(DistanceRule::class)]
#[CoversClass(DistanceOptions::class)]
final class DistanceRuleTest extends TestCase
{
    #[Test]
    public function itReturnsCorrectName(): void
    {
        $rule = new DistanceRule(new DistanceOptions(includeNamespaces: ['App'], minClassCount: 0));

        self::assertSame('coupling.distance', $rule->getName());
    }

    #[Test]
    public function itReturnsCorrectDescription(): void
    {
        $rule = new DistanceRule(new DistanceOptions(includeNamespaces: ['App'], minClassCount: 0));

        self::assertSame(
            'Checks distance from main sequence at namespace level',
            $rule->getDescription(),
        );
    }

    #[Test]
    public function itReturnsCouplingCategory(): void
    {
        $rule = new DistanceRule(new DistanceOptions(includeNamespaces: ['App'], minClassCount: 0));

        self::assertSame(RuleCategory::Coupling, $rule->getCategory());
    }

    #[Test]
    public function itRequiresDistanceMetrics(): void
    {
        $rule = new DistanceRule(new DistanceOptions(includeNamespaces: ['App'], minClassCount: 0));

        self::assertSame(['distance', 'abstractness', 'instability'], $rule->requires());
    }

    #[Test]
    public function itReturnsCorrectOptionsClass(): void
    {
        self::assertSame(
            DistanceOptions::class,
            DistanceRule::getOptionsClass(),
        );
    }

    #[Test]
    public function itDeclaresCorrectCliAliases(): void
    {
        self::assertSame([
            'distance-warning' => 'max_distance_warning',
            'distance-error' => 'max_distance_error',
        ], CliAliasReader::read(DistanceRule::class));
    }

    #[Test]
    public function itReturnsEmptyWhenDisabled(): void
    {
        $rule = new DistanceRule(
            new DistanceOptions(enabled: false),
        );

        $repository = $this->createMock(MetricRepositoryInterface::class);
        $repository->expects(self::never())->method('all');

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itReturnsEmptyWhenNoNamespaces(): void
    {
        $rule = new DistanceRule(new DistanceOptions(includeNamespaces: ['App'], minClassCount: 0));

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([]);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itSkipsNamespacesWithoutDistanceMetric(): void
    {
        $rule = new DistanceRule(new DistanceOptions(includeNamespaces: ['App'], minClassCount: 0));

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service'), null);

        $metricBag = new MetricBag();

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);

        self::assertSame([], $rule->analyze($context));
    }

    #[Test]
    public function itGeneratesWarningWhenDistanceExceedsThreshold(): void
    {
        $rule = new DistanceRule(new DistanceOptions(includeNamespaces: ['App'], minClassCount: 0));

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service'), null);

        // 0.35 is above warning (0.3), below error (0.5)
        $metricBag = (new MetricBag())
            ->with('distance', 0.35)
            ->with('abstractness', 0.2)
            ->with('instability', 0.45);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$nsInfo]);
        $repository->method('get')
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

    #[Test]
    public function itGeneratesErrorWhenDistanceExceedsErrorThreshold(): void
    {
        $rule = new DistanceRule(new DistanceOptions(includeNamespaces: ['App'], minClassCount: 0));

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service'), null);

        // 0.6 is above error (0.5)
        $metricBag = (new MetricBag())
            ->with('distance', 0.6)
            ->with('abstractness', 0.1)
            ->with('instability', 0.3);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
        self::assertSame(0.6, $violations[0]->metricValue);
    }

    #[Test]
    public function itEmitsNoViolationWhenOnMainSequence(): void
    {
        $rule = new DistanceRule(new DistanceOptions(includeNamespaces: ['App'], minClassCount: 0));

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service'), null);

        // Distance close to 0 = on main sequence
        $metricBag = (new MetricBag())
            ->with('distance', 0.1)
            ->with('abstractness', 0.5)
            ->with('instability', 0.5);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(0, $violations);
    }

    #[Test]
    public function itAnalyzesMultipleNamespaces(): void
    {
        $rule = new DistanceRule(new DistanceOptions(includeNamespaces: ['App'], minClassCount: 0));

        $nsPath1 = SymbolPath::forNamespace('App\Service');
        $nsInfo1 = self::subjectInfo($nsPath1, RelativePath::fromString('src/Service'), null);

        $nsPath2 = SymbolPath::forNamespace('App\Controller');
        $nsInfo2 = self::subjectInfo($nsPath2, RelativePath::fromString('src/Controller'), null);

        $nsBag1 = (new MetricBag())
            ->with('distance', 0.4) // Warning
            ->with('abstractness', 0.1)
            ->with('instability', 0.5);
        $nsBag2 = (new MetricBag())
            ->with('distance', 0.55) // Error
            ->with('abstractness', 0.0)
            ->with('instability', 0.45);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
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

    #[Test]
    public function itParsesOptionsFromArray(): void
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

    #[Test]
    public function itParsesOptionsFromArrayWithLegacyKeys(): void
    {
        $options = DistanceOptions::fromArray([
            'maxDistanceWarning' => 0.25,
            'maxDistanceError' => 0.4,
        ]);

        self::assertTrue($options->enabled);
        self::assertSame(0.25, $options->maxDistanceWarning);
        self::assertSame(0.4, $options->maxDistanceError);
    }

    #[Test]
    public function itUsesOptionDefaults(): void
    {
        $options = DistanceOptions::fromArray([]);

        self::assertTrue($options->enabled);
        self::assertSame(0.3, $options->maxDistanceWarning);
        self::assertSame(0.5, $options->maxDistanceError);
    }

    #[Test]
    public function itReturnsSeverityForGivenDistance(): void
    {
        $options = new DistanceOptions(maxDistanceWarning: 0.3, maxDistanceError: 0.5);

        self::assertNull($options->getSeverity(0.29));
        self::assertSame(Severity::Warning, $options->getSeverity(0.3));
        self::assertSame(Severity::Warning, $options->getSeverity(0.4));
        self::assertSame(Severity::Error, $options->getSeverity(0.5));
        self::assertSame(Severity::Error, $options->getSeverity(1.0));
    }

    #[Test]
    #[DataProvider('distanceThresholdDataProvider')]
    public function itRespectsDistanceThresholdBoundaries(
        float $distance,
        float $warning,
        float $error,
        ?Severity $expectedSeverity,
    ): void {
        $rule = new DistanceRule(
            new DistanceOptions(maxDistanceWarning: $warning, maxDistanceError: $error, includeNamespaces: ['App'], minClassCount: 0),
        );

        $symbolPath = SymbolPath::forNamespace('App');
        $nsInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src'), null);

        $metricBag = (new MetricBag())
            ->with('distance', $distance)
            ->with('abstractness', 0.0)
            ->with('instability', 0.0);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$nsInfo]);
        $repository->method('get')
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

    #[Test]
    public function itSkipsNamespaceWithTooFewClasses(): void
    {
        $rule = new DistanceRule(
            new DistanceOptions(includeNamespaces: ['App'], minClassCount: 3),
        );

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service'), null);

        // classCount.sum=2 is below minClassCount=3, so no violation despite high distance
        $metricBag = (new MetricBag())
            ->with('distance', 0.6)
            ->with('abstractness', 0.1)
            ->with('instability', 0.3)
            ->with('classCount.sum', 2);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(0, $violations);
    }

    #[Test]
    public function itReportsViolationWhenClassCountMeetsMinimum(): void
    {
        $rule = new DistanceRule(
            new DistanceOptions(includeNamespaces: ['App'], minClassCount: 3),
        );

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service'), null);

        // classCount.sum=3 meets minClassCount=3, so violation is reported
        $metricBag = (new MetricBag())
            ->with('distance', 0.6)
            ->with('abstractness', 0.1)
            ->with('instability', 0.3)
            ->with('classCount.sum', 3);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
    }

    #[Test]
    public function itDoesNotLoseOneClassAtTheMinimumAfterNamespaceAggregation(): void
    {
        $repository = new InMemoryMetricRepository();
        $namespace = 'App\\Service';
        $file = RelativePath::fromString('src/Service/One.php');

        $repository->add(SymbolPath::forFile($file), MetricBag::fromArray(['classCount' => 1]), $file, 1);
        $repository->add(SymbolPath::forClass($namespace, 'One'), new MetricBag(), $file, 2);
        $repository->add(
            SymbolPath::forNamespace($namespace),
            MetricBag::fromArray([
                'classCount' => 1,
                'classCount.count' => 6,
            ]),
            $file,
            1,
        );

        (new MetricAggregator(AggregationHelper::collectDefinitions([
            new ClassCountCollector(),
        ]), self::createStub(ProfilerInterface::class)))->aggregate($repository);
        $repository->add(
            SymbolPath::forNamespace($namespace),
            MetricBag::fromArray([
                'distance' => 0.6,
                'abstractness' => 0.1,
                'instability' => 0.3,
            ]),
            $file,
            1,
        );

        $rule = new DistanceRule(new DistanceOptions(includeNamespaces: ['App'], minClassCount: 1));
        $violations = $rule->analyze(new AnalysisContext($repository));

        self::assertSame(1, $repository->get(SymbolPath::forNamespace($namespace))->get('classCount.sum'));
        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
    }

    #[Test]
    public function itAnalyzesAllWhenMinClassCountIsZero(): void
    {
        $rule = new DistanceRule(
            new DistanceOptions(includeNamespaces: ['App'], minClassCount: 0),
        );

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service'), null);

        // No classCount.sum metric at all, but minClassCount=0 so it should still be analyzed
        $metricBag = (new MetricBag())
            ->with('distance', 0.6)
            ->with('abstractness', 0.1)
            ->with('instability', 0.3);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertCount(1, $violations);
        self::assertSame(Severity::Error, $violations[0]->severity);
    }

    #[Test]
    public function itParsesMinClassCountFromArray(): void
    {
        $options = DistanceOptions::fromArray([
            'min_class_count' => 5,
        ]);

        self::assertSame(5, $options->minClassCount);
    }

    #[Test]
    public function itParsesMinClassCountCamelCaseAlias(): void
    {
        $options = DistanceOptions::fromArray([
            'minClassCount' => 7,
        ]);

        self::assertSame(7, $options->minClassCount);
    }

    #[Test]
    public function itDefaultsMinClassCountToThree(): void
    {
        $options = DistanceOptions::fromArray([]);

        self::assertSame(3, $options->minClassCount);
    }

    #[Test]
    public function itThrowsForInvalidOptionsType(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Expected');

        $invalidOptions = self::createStub(\Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface::class);
        new DistanceRule($invalidOptions);
    }

    #[Test]
    public function itLogsWarningWhenNoProjectNamespacesDetected(): void
    {
        $resolver = self::createStub(ProjectNamespaceResolverInterface::class);
        $resolver->method('isProjectNamespace')
            ->willReturn(false);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                self::stringContains('no project namespaces detected'),
                self::equalTo(['total' => 1]),
            );

        $rule = new DistanceRule(
            new DistanceOptions(minClassCount: 0),
            $resolver,
            $logger,
        );

        $symbolPath = SymbolPath::forNamespace('Vendor\Package');
        $nsInfo = self::subjectInfo($symbolPath, RelativePath::fromString('vendor/package/src'), null);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$nsInfo]);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertSame([], $violations);
    }

    #[Test]
    public function itDoesNotLogWarningWhenProjectNamespacesExist(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())
            ->method('warning');

        $rule = new DistanceRule(
            new DistanceOptions(includeNamespaces: ['App'], minClassCount: 0),
            null,
            $logger,
        );

        $symbolPath = SymbolPath::forNamespace('App\Service');
        $nsInfo = self::subjectInfo($symbolPath, RelativePath::fromString('src/Service'), null);

        $metricBag = (new MetricBag())
            ->with('distance', 0.1)
            ->with('abstractness', 0.5)
            ->with('instability', 0.5);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$nsInfo]);
        $repository->method('get')
            ->willReturn($metricBag);

        $context = new AnalysisContext($repository);
        $rule->analyze($context);
    }

    #[Test]
    public function itDoesNotLogWarningWhenNoNamespacesAtAll(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())
            ->method('warning');

        $rule = new DistanceRule(
            new DistanceOptions(includeNamespaces: ['App'], minClassCount: 0),
            null,
            $logger,
        );

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([]);

        $context = new AnalysisContext($repository);
        $rule->analyze($context);
    }

    #[Test]
    public function itIncludesRuleOptHintInWarning(): void
    {
        $resolver = self::createStub(ProjectNamespaceResolverInterface::class);
        $resolver->method('isProjectNamespace')
            ->willReturn(false);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                self::stringContains("--rule-opt='coupling.distance:include_namespaces=...'"),
                self::anything(),
            );

        $rule = new DistanceRule(
            new DistanceOptions(minClassCount: 0),
            $resolver,
            $logger,
        );

        $nsPath1 = SymbolPath::forNamespace('Vendor\PackageA');
        $nsInfo1 = self::subjectInfo($nsPath1, RelativePath::fromString('vendor/a/src'), null);

        $nsPath2 = SymbolPath::forNamespace('Vendor\PackageB');
        $nsInfo2 = self::subjectInfo($nsPath2, RelativePath::fromString('vendor/b/src'), null);

        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')
            ->willReturn([$nsInfo1, $nsInfo2]);

        $context = new AnalysisContext($repository);
        $violations = $rule->analyze($context);

        self::assertSame([], $violations);
    }

    #[Test]
    public function itGivesExplicitIncludesPrecedenceWithExactPrefixBoundaries(): void
    {
        $resolver = $this->createMock(ProjectNamespaceResolverInterface::class);
        $resolver->expects(self::never())->method('isProjectNamespace');
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')->willReturn([
            self::subjectInfo(SymbolPath::forNamespace('App'), RelativePath::fromString('src/App'), null),
            self::subjectInfo(SymbolPath::forNamespace('App\\Service'), RelativePath::fromString('src/Service'), null),
            self::subjectInfo(SymbolPath::forNamespace('Application'), RelativePath::fromString('src/Application'), null),
        ]);
        $repository->method('get')->willReturn(
            MetricBag::fromArray([
                MetricName::COUPLING_DISTANCE => 0.4,
                MetricName::COUPLING_ABSTRACTNESS => 0.2,
                MetricName::COUPLING_INSTABILITY => 0.6,
            ]),
        );

        $violations = (new DistanceRule(
            new DistanceOptions(includeNamespaces: ['App\\'], minClassCount: 0),
            $resolver,
        ))->analyze(new AnalysisContext($repository));

        self::assertCount(2, $violations);
        self::assertSame(
            ['ns:App', 'ns:App\\Service'],
            array_map(static fn($violation): string => $violation->subject->toCanonical(), $violations),
        );
    }

    #[Test]
    public function itCountsEveryMatchedNamespaceWhenSmallMissingOrBelowThreshold(): void
    {
        $smallPath = SymbolPath::forNamespace('App\\Small');
        $missingPath = SymbolPath::forNamespace('App\\Missing');
        $belowPath = SymbolPath::forNamespace('App\\Below');
        $repository = self::createStub(MetricRepositoryInterface::class);
        $repository->method('all')->willReturn([
            self::subjectInfo($smallPath, RelativePath::fromString('src/Small'), null),
            self::subjectInfo($missingPath, RelativePath::fromString('src/Missing'), null),
            self::subjectInfo($belowPath, RelativePath::fromString('src/Below'), null),
        ]);
        $bags = [
            $smallPath->toCanonical() => MetricBag::fromArray(['classCount.sum' => 2, 'distance' => 0.8]),
            $missingPath->toCanonical() => MetricBag::fromArray(['classCount.sum' => 3]),
            $belowPath->toCanonical() => MetricBag::fromArray(['classCount.sum' => 3, 'distance' => 0.1]),
        ];
        $repository->method('get')->willReturnCallback(
            static fn(SymbolPath $path): MetricBag => $bags[$path->toCanonical()],
        );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $violations = (new DistanceRule(
            new DistanceOptions(includeNamespaces: ['App'], minClassCount: 3),
            logger: $logger,
        ))->analyze(new AnalysisContext($repository));

        self::assertSame([], $violations);
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
