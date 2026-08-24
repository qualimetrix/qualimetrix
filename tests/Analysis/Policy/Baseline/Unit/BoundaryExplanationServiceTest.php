<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Unit;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Analysis\Finding\Contract\Control\ControlScope;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Baseline\Baseline;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEdge;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\BaselineIdentity;
use Qualimetrix\Analysis\Policy\Baseline\BoundaryExplanationService;
use Qualimetrix\Analysis\Policy\Baseline\BoundaryExplanationStatus;
use Qualimetrix\Analysis\Policy\Baseline\InertBaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\InertEntryReason;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Symbol\SymbolType;
use ReflectionMethod;

#[CoversClass(BoundaryExplanationService::class)]
final class BoundaryExplanationServiceTest extends TestCase
{
    private const string SYMBOL_KEY = 'declaration:callable:App\\Foo::bar@src/Foo.php';

    private BoundaryExplanationService $service;

    protected function setUp(): void
    {
        $this->service = new BoundaryExplanationService();
    }

    #[Test]
    public function itClassifiesCurrentBaselineOnlyAndUnknownSymbolsExplicitly(): void
    {
        $channel = new FindingChannel('complexity.cyclomatic', 'complexity.cyclomatic.callable');
        $baseline = $this->baselineWithEntry($channel, magnitudes: [25], count: 1);
        $currentRepository = $this->repositoryWithCallableSubject(
            SymbolPath::forMethod('App', 'Foo', 'bar'),
            'src/Foo.php',
            14,
        );

        $current = $this->service->explain(
            self::SYMBOL_KEY,
            $channel,
            $baseline,
            [],
            [],
            [],
            $currentRepository,
        );
        $baselineOnly = $this->service->explain(self::SYMBOL_KEY, $channel, $baseline, [], [], []);
        $inertBaselineOnly = $this->service->explain(
            self::SYMBOL_KEY,
            $channel,
            new Baseline(
                generated: new DateTimeImmutable(),
                scope: ['src'],
                entries: [],
                inertEntries: [InertBaselineEntry::forRaw(
                    self::SYMBOL_KEY,
                    $channel->toKey(),
                    InertEntryReason::Malformed,
                    'test fixture',
                    'garbage',
                )],
            ),
            [],
            [],
            [],
        );
        $unknown = $this->service->explain('callable:App\Missing::method', $channel, $baseline, [], [], [], $currentRepository);

        self::assertSame(BoundaryExplanationStatus::Current, $current->status);
        self::assertSame(BoundaryExplanationStatus::BaselineOnly, $baselineOnly->status);
        self::assertSame(BoundaryExplanationStatus::BaselineOnly, $inertBaselineOnly->status);
        self::assertSame(BoundaryExplanationStatus::Unknown, $unknown->status);
    }

    /**
     * All three sources present at once, each holding a different number —
     * the shape ADR 0017 illustration describes: "`ccn` ≤ 25 from baseline;
     * `qmx.yaml` says 10; annotation raises it to 40".
     */
    #[Test]
    public function itCollectsAllThreeSourcesWhenAllThreeApply(): void
    {
        $channel = new FindingChannel('complexity.cyclomatic', 'complexity.cyclomatic.callable');
        $baseline = $this->baselineWithEntry($channel, magnitudes: [25], count: 1);
        $currentFinding = $this->finding($channel, metricValue: 31);

        $explanation = $this->service->explain(
            subjectKey: self::SYMBOL_KEY,
            channelFilter: null,
            baseline: $baseline,
            measuredFindings: [$currentFinding],
            thresholdOverridesByFile: [
                'src/Foo.php' => [$this->thresholdOverride(1)],
            ],
            configuredThresholds: [$channel->toKey() => 20],
        );

        self::assertCount(1, $explanation->boundaries);
        $boundary = $explanation->boundaries[0];

        self::assertNotNull($boundary->baseline);
        self::assertSame([25.0], $boundary->baseline->accepted->magnitudes);
        self::assertSame(20, $boundary->configuredThreshold);
        self::assertNotNull($boundary->annotation);
        self::assertSame(15, $boundary->annotation->warning);
        self::assertSame(40, $boundary->annotation->error);
    }

    /**
     * ADR 0017: a channel's magnitude can change scale without the channel
     * changing, so the stored acceptance and the current comparison must
     * both be printed — and here they must disagree, since that is the
     * whole point of showing both.
     */
    #[Test]
    public function itPrintsBothTheStoredMagnitudeAndTheCurrentlyComparedOne(): void
    {
        $channel = new FindingChannel('complexity.cyclomatic', 'complexity.cyclomatic.callable');
        $baseline = $this->baselineWithEntry($channel, magnitudes: [25], count: 1);
        $currentFinding = $this->finding($channel, metricValue: 31);

        $explanation = $this->service->explain(
            subjectKey: self::SYMBOL_KEY,
            channelFilter: $channel,
            baseline: $baseline,
            measuredFindings: [$currentFinding],
            thresholdOverridesByFile: [],
            configuredThresholds: [],
        );

        $source = $explanation->boundaries[0]->baseline;

        self::assertNotNull($source);
        self::assertSame([25.0], $source->accepted->magnitudes);
        self::assertSame([31.0], $source->currentMagnitudes);
        self::assertNotSame($source->accepted->magnitudes, $source->currentMagnitudes);
        self::assertSame(1, $source->currentCount);
    }

    /**
     * A channel with no baseline entry and no matching annotation still
     * reports its `qmx.yaml` boundary — the other two sources are absent,
     * not zero.
     */
    #[Test]
    public function itReportsAbsentSourcesAsNullRatherThanAsZero(): void
    {
        $channel = new FindingChannel('coupling.cbo', 'coupling.cbo.class');

        $explanation = $this->service->explain(
            subjectKey: self::SYMBOL_KEY,
            channelFilter: $channel,
            baseline: $this->baselineWithEntry(
                new FindingChannel('complexity.cyclomatic', 'complexity.cyclomatic.callable'),
                magnitudes: [25],
                count: 1,
            ),
            measuredFindings: [],
            thresholdOverridesByFile: [],
            configuredThresholds: [$channel->toKey() => 10],
        );

        self::assertCount(1, $explanation->boundaries);
        $boundary = $explanation->boundaries[0];

        self::assertNull($boundary->baseline);
        self::assertNull($boundary->annotation);
        self::assertSame(10, $boundary->configuredThreshold);
    }

    /**
     * A configured threshold of `0` is a real, meaningful boundary and must
     * not read the same as "no boundary configured at all".
     */
    #[Test]
    public function itKeepsAZeroConfiguredThresholdDistinctFromAnAbsentOne(): void
    {
        $channel = new FindingChannel('code-smell.goto', 'code-smell.goto');

        $withZero = $this->service->explain(
            subjectKey: self::SYMBOL_KEY,
            channelFilter: $channel,
            baseline: null,
            measuredFindings: [],
            thresholdOverridesByFile: [],
            configuredThresholds: [$channel->toKey() => 0],
        );

        $withoutEntry = $this->service->explain(
            subjectKey: self::SYMBOL_KEY,
            channelFilter: $channel,
            baseline: null,
            measuredFindings: [],
            thresholdOverridesByFile: [],
            configuredThresholds: [],
        );

        self::assertSame(0, $withZero->boundaries[0]->configuredThreshold);
        self::assertNull($withoutEntry->boundaries[0]->configuredThreshold);
    }

    /**
     * Without a `--channel` filter, every channel bearing on the symbol is
     * reported — both what the baseline knows about and what is currently
     * firing, even when the two do not overlap.
     */
    #[Test]
    public function itDiscoversEveryApplicableChannelWhenNoneIsRequested(): void
    {
        $baselinedChannel = new FindingChannel('complexity.cyclomatic', 'complexity.cyclomatic.callable');
        $firingOnlyChannel = new FindingChannel('coupling.cbo', 'coupling.cbo.class');

        $baseline = $this->baselineWithEntry($baselinedChannel, magnitudes: [25], count: 1);
        $firingOnly = $this->finding($firingOnlyChannel, metricValue: 12);

        $explanation = $this->service->explain(
            subjectKey: self::SYMBOL_KEY,
            channelFilter: null,
            baseline: $baseline,
            measuredFindings: [$firingOnly],
            thresholdOverridesByFile: [],
            configuredThresholds: [],
        );

        $channelKeys = array_map(
            static fn($boundary): string => $boundary->identity->channel->toKey(),
            $explanation->boundaries,
        );

        self::assertCount(2, $explanation->boundaries);
        self::assertContains($baselinedChannel->toKey(), $channelKeys);
        self::assertContains($firingOnlyChannel->toKey(), $channelKeys);
    }

    /**
     * Without a current exact subject or an exact repository fallback, the
     * annotation is reported absent rather than guessed from a projection.
     */
    #[Test]
    public function itReportsNoAnnotationWhenNoSourceLocatesTheSymbol(): void
    {
        $channel = new FindingChannel('complexity.cyclomatic', 'complexity.cyclomatic.callable');

        $explanation = $this->service->explain(
            subjectKey: self::SYMBOL_KEY,
            channelFilter: $channel,
            baseline: null,
            measuredFindings: [],
            thresholdOverridesByFile: [
                'src/Foo.php' => [$this->thresholdOverride(1)],
            ],
            configuredThresholds: [],
        );

        self::assertNull($explanation->boundaries[0]->annotation);
    }

    /**
     * **ADR 0017's example, which used to be the case that did not work.**
     * "`qmx.yaml` says 10; annotation raises it to 40" describes a symbol
     * that is *not* violating anything — the raised threshold is normally
     * why the rule stopped firing. The repository retains its exact typed
     * subject whether or not a finding currently exists.
     */
    #[Test]
    public function itFindsTheAnnotationForASymbolThatViolatesNothing(): void
    {
        $channel = new FindingChannel('complexity.cyclomatic', 'complexity.cyclomatic.callable');

        $explanation = $this->service->explain(
            subjectKey: self::SYMBOL_KEY,
            channelFilter: $channel,
            baseline: null,
            measuredFindings: [],
            thresholdOverridesByFile: [
                'src/Foo.php' => [$this->thresholdOverride(12)],
            ],
            configuredThresholds: [$channel->toKey() => 10],
            symbolLocations: $this->repositoryWithCallableSubject(SymbolPath::forMethod('App', 'Foo', 'bar'), 'src/Foo.php', 14),
        );

        $boundary = $explanation->boundaries[0];

        self::assertNotNull($boundary->annotation, 'the annotation is what raised the threshold; it must be printed');
        self::assertSame(40, $boundary->annotation->error);
        self::assertSame(10, $boundary->configuredThreshold);
        self::assertNull($boundary->baseline);
    }

    #[Test]
    public function itUsesTheFirstExactMeasuredSubjectAcrossDifferentOccurrenceIdentities(): void
    {
        $channel = new FindingChannel('complexity.cyclomatic', 'complexity.cyclomatic.callable');
        $baselineIdentity = new BaselineIdentity(self::SYMBOL_KEY, $channel, 'stored-occurrence');
        $baseline = new Baseline(
            new DateTimeImmutable('2026-08-05T12:00:00+03:00'),
            ['src'],
            [new BaselineEntry($baselineIdentity, [25], 1)],
        );
        $measured = $this->findingWithIdentityParts(
            $channel,
            OccurrenceKey::semantic('measured', ['slot' => 2]),
        );

        $explanation = $this->service->explain(
            self::SYMBOL_KEY,
            null,
            $baseline,
            [$measured],
            ['src/Foo.php' => [$this->thresholdOverride(1)]],
            [],
        );

        self::assertSame($baselineIdentity->key(), $explanation->boundaries[0]->identity->key());
        self::assertNotNull($explanation->boundaries[0]->annotation);
        self::assertSame(40, $explanation->boundaries[0]->annotation->error);
        self::assertSame(0, $explanation->boundaries[0]->baseline?->currentCount);
    }

    #[Test]
    public function itUsesTheFirstExactMeasuredSubjectAcrossDifferentEdgeIdentities(): void
    {
        $channel = new FindingChannel('complexity.cyclomatic', 'complexity.cyclomatic.callable');
        $target = SymbolPath::forClass('App\Dependency', 'Target');
        $baselineIdentity = new BaselineIdentity(
            self::SYMBOL_KEY,
            $channel,
            null,
            new BaselineEdge($target->toCanonical(), DependencyType::New_),
        );
        $baseline = new Baseline(
            new DateTimeImmutable('2026-08-05T12:00:00+03:00'),
            ['src'],
            [new BaselineEntry($baselineIdentity, [25], 1)],
        );
        $measured = $this->findingWithIdentityParts($channel, dependencyTarget: $target);

        $explanation = $this->service->explain(
            self::SYMBOL_KEY,
            null,
            $baseline,
            [$measured],
            ['src/Foo.php' => [$this->thresholdOverride(1)]],
            [],
        );

        self::assertSame(DependencyType::New_, $explanation->boundaries[0]->identity->edge?->type);
        self::assertNotNull($explanation->boundaries[0]->annotation);
        self::assertSame(0, $explanation->boundaries[0]->baseline?->currentCount);
    }

    /**
     * A symbol the run never measured has no exact typed evidence, so the
     * fallback does not invent one from another subject.
     */
    #[Test]
    public function itReportsNoAnnotationForASymbolTheRunNeverMeasured(): void
    {
        $channel = new FindingChannel('complexity.cyclomatic', 'complexity.cyclomatic.callable');

        $explanation = $this->service->explain(
            subjectKey: self::SYMBOL_KEY,
            channelFilter: $channel,
            baseline: null,
            measuredFindings: [],
            thresholdOverridesByFile: [
                'src/Foo.php' => [$this->thresholdOverride(1)],
            ],
            configuredThresholds: [],
            symbolLocations: $this->repositoryWithCallableSubject(SymbolPath::forMethod('App', 'Other', 'baz'), 'src/Other.php', 3),
        );

        self::assertNull($explanation->boundaries[0]->annotation);
    }

    #[Test]
    public function itUsesScopeThenFiniteSpanAndKeepsTheFirstExactTieAcrossRulePatterns(): void
    {
        $channel = new FindingChannel('complexity.cyclomatic', 'complexity.cyclomatic.callable');
        $subject = MetricSubject::declaration(DeclarationPath::of(SymbolPath::forMethod('App', 'Foo', 'bar'), RelativePath::fromString('src/Foo.php'), DeclarationOrdinal::fromRank(0)));
        $override = static fn(string $pattern, int $warning, ControlScope $scope, int $line, ?int $endLine): ThresholdOverride => new ThresholdOverride(
            $pattern,
            $warning,
            null,
            $line,
            $subject,
            $scope,
            $endLine,
        );

        $explanation = $this->service->explain(
            self::SYMBOL_KEY,
            $channel,
            null,
            [],
            ['src/Foo.php' => [
                $override('*', 1, ControlScope::Class_, 1, 2),
                $override('complexity', 2, ControlScope::Callable, 1, 100),
                $override('complexity.cyclomatic', 3, ControlScope::Callable, 10, 20),
                $override('complexity.cyclomatic', 4, ControlScope::Callable, 10, 20),
            ]],
            [],
            $this->repositoryWithCallableSubject(SymbolPath::forMethod('App', 'Foo', 'bar'), 'src/Foo.php', 14),
        );

        self::assertSame(3, $explanation->boundaries[0]->annotation?->warning);
    }

    #[Test]
    public function itIndexesEveryTypedRepositorySourceOnceWithoutCollapsingExactDeclarations(): void
    {
        $class = SymbolPath::forClass('App', 'Duplicated');
        $first = MetricSubject::declaration(DeclarationPath::of($class, RelativePath::fromString('src/First.php'), DeclarationOrdinal::fromRank(0)));
        $second = MetricSubject::declaration(DeclarationPath::of($class, RelativePath::fromString('src/Second.php'), DeclarationOrdinal::fromRank(0)));
        $callable = MetricSubject::declaration(DeclarationPath::of(SymbolPath::forMethod('App', 'Duplicated', 'run'), RelativePath::fromString('src/First.php'), DeclarationOrdinal::fromRank(0)));
        $logical = MetricSubject::logicalClass(new LogicalClassPath($class));
        $namespace = SymbolPath::forNamespace('App');
        $repository = new CountingBoundaryRepository(
            declarations: [
                new SymbolInfo($first, RelativePath::fromString('src/First.php'), 11),
                new SymbolInfo($second, RelativePath::fromString('src/Second.php'), 21),
            ],
            callables: [
                new SymbolInfo($callable, RelativePath::fromString('src/First.php'), 31),
                new SymbolInfo($first, RelativePath::fromString('src/Duplicate.php'), 99),
            ],
            logicalClasses: [new SymbolInfo($logical, RelativePath::fromString('src/First.php'), null)],
            aggregates: [
                SymbolType::Namespace_->value => [new SymbolInfo($namespace, null, null)],
            ],
        );

        $method = new ReflectionMethod(BoundaryExplanationService::class, 'repositoryIndex');
        $index = $method->invoke(null, $repository);

        self::assertIsArray($index);
        self::assertArrayHasKey($first->toCanonical(), $index);
        self::assertArrayHasKey($second->toCanonical(), $index);
        self::assertArrayHasKey($callable->toCanonical(), $index);
        self::assertArrayHasKey($logical->toCanonical(), $index);
        self::assertArrayHasKey($namespace->toCanonical(), $index);
        self::assertSame($first, $index[$first->toCanonical()]['subject']);
        self::assertSame('src/First.php', $index[$first->toCanonical()]['location'][0]->value());
        self::assertNull($index[$namespace->toCanonical()]['subject']);
        self::assertSame(1, $repository->calls['allDeclarations']);
        self::assertSame(1, $repository->calls['allCallables']);
        self::assertSame(1, $repository->calls['allLogicalClasses']);
        self::assertSame(2, $repository->iterations['allDeclarations']);
        self::assertSame(2, $repository->iterations['allCallables']);
        self::assertSame(1, $repository->iterations['allLogicalClasses']);
        foreach (SymbolType::cases() as $type) {
            self::assertSame(1, $repository->calls['all:' . $type->value]);
        }
        self::assertSame(1, $repository->iterations['all:' . SymbolType::Namespace_->value]);
    }

    #[Test]
    public function itFailsFastWhenAnExactRepositorySourceDropsItsTypedSubject(): void
    {
        $repository = new CountingBoundaryRepository(
            declarations: [new SymbolInfo(SymbolPath::forClass('App', 'Untyped'), null, null)],
        );
        $method = new ReflectionMethod(BoundaryExplanationService::class, 'repositoryIndex');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Exact repository rows must retain their typed subject.');

        $method->invoke(null, $repository);
    }

    /**
     * A repository retaining exactly one callable declaration subject.
     */
    private function repositoryWithCallableSubject(SymbolPath $symbol, string $file, int $line): MetricRepositoryInterface
    {
        $repository = new InMemoryMetricRepository();
        $repository->addCallable(new CallableWithMetrics(DeclarationPath::of($symbol, RelativePath::fromString($file), DeclarationOrdinal::fromRank(0)), 0, CallableKind::Method, null, null, new LogicalClassPath(SymbolPath::forClass($symbol->namespace ?? '', $symbol->type ?? '')), new MetricBag(), $line));

        return $repository;
    }

    /**
     * @param ?list<int|float> $magnitudes
     */
    private function baselineWithEntry(FindingChannel $channel, ?array $magnitudes, int $count): Baseline
    {
        $identity = new BaselineIdentity(self::SYMBOL_KEY, $channel);

        return new Baseline(
            generated: new DateTimeImmutable('2026-08-05T12:00:00+03:00'),
            scope: ['src'],
            entries: [new BaselineEntry($identity, $magnitudes, $count)],
        );
    }

    private function finding(FindingChannel $channel, int|float $metricValue): Finding
    {
        return new Finding(
            subject: MetricSubject::declaration(DeclarationPath::of(SymbolPath::forMethod('App', 'Foo', 'bar'), RelativePath::fromString('src/Foo.php'), DeclarationOrdinal::fromRank(0))),
            location: new Location(RelativePath::fromString('src/Foo.php'), 10),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: $channel->ruleName,
            code: $channel->code,
            message: 'test finding',
            severity: Severity::Warning,
            metricValue: $metricValue,
        );
    }

    private function findingWithIdentityParts(
        FindingChannel $channel,
        ?OccurrenceKey $occurrenceKey = null,
        ?SymbolPath $dependencyTarget = null,
    ): Finding {
        $symbol = SymbolPath::forMethod('App', 'Foo', 'bar');

        return new Finding(
            subject: MetricSubject::declaration(DeclarationPath::of($symbol, RelativePath::fromString('src/Foo.php'), DeclarationOrdinal::fromRank(0))),
            location: new Location(RelativePath::fromString('src/Foo.php'), 10),
            symbolPath: $symbol,
            ruleName: $channel->ruleName,
            code: $channel->code,
            message: 'same subject, different identity details',
            severity: Severity::Warning,
            metricValue: 31,
            dependencyTarget: $dependencyTarget,
            dependencyType: null,
            occurrenceKey: $occurrenceKey,
        );
    }

    private function thresholdOverride(int $line): ThresholdOverride
    {
        return new ThresholdOverride(
            'complexity.cyclomatic',
            15,
            40,
            $line,
            MetricSubject::declaration(DeclarationPath::of(SymbolPath::forMethod('App', 'Foo', 'bar'), RelativePath::fromString('src/Foo.php'), DeclarationOrdinal::fromRank(0))),
            ControlScope::Class_,
            50,
        );
    }
}

/** @internal Direct repository-index fixture for this test only. */
final class CountingBoundaryRepository implements MetricRepositoryInterface
{
    /** @var array<string, int> */
    public array $calls = [];

    /** @var array<string, int> */
    public array $iterations = [];

    /**
     * @param list<SymbolInfo> $declarations
     * @param list<SymbolInfo> $callables
     * @param list<SymbolInfo> $logicalClasses
     * @param array<string, list<SymbolInfo>> $aggregates
     */
    public function __construct(
        private array $declarations = [],
        private array $callables = [],
        private array $logicalClasses = [],
        private array $aggregates = [],
    ) {}

    public function mergedWith(MetricRepositoryInterface $other): ?MetricRepositoryInterface
    {
        return null;
    }

    public function get(SymbolPath $symbol): MetricBag
    {
        return new MetricBag();
    }

    public function all(SymbolType $type): iterable
    {
        $source = 'all:' . $type->value;
        $this->count($this->calls, $source);

        return $this->iterate($source, $this->aggregates[$type->value] ?? []);
    }

    public function has(SymbolPath $symbol): bool
    {
        return false;
    }

    public function add(SymbolPath $symbol, MetricBag $metrics, ?RelativePath $file, ?int $line): void {}

    public function getSubject(MetricSubject $subject): MetricBag
    {
        return new MetricBag();
    }

    public function hasSubject(MetricSubject $subject): bool
    {
        return false;
    }

    public function addSubject(MetricSubject $subject, MetricBag $metrics, ?RelativePath $file, ?int $line): void {}

    public function addCallable(CallableWithMetrics $callable): void {}

    public function allDeclarations(): iterable
    {
        $this->count($this->calls, __FUNCTION__);

        return $this->iterate(__FUNCTION__, $this->declarations);
    }

    public function allCallables(): iterable
    {
        $this->count($this->calls, __FUNCTION__);

        return $this->iterate(__FUNCTION__, $this->callables);
    }

    public function allLogicalClasses(): iterable
    {
        $this->count($this->calls, __FUNCTION__);

        return $this->iterate(__FUNCTION__, $this->logicalClasses);
    }

    public function addScalar(SymbolPath $symbol, string $key, int|float $value): void {}

    public function getNamespaces(): array
    {
        return [];
    }

    public function forNamespace(string $namespace): array
    {
        return [];
    }

    /**
     * @param list<SymbolInfo> $rows
     *
     * @return iterable<SymbolInfo>
     */
    private function iterate(string $source, array $rows): iterable
    {
        foreach ($rows as $row) {
            $this->count($this->iterations, $source);
            yield $row;
        }
    }

    /** @param array<string, int> $counter */
    private function count(array &$counter, string $key): void
    {
        $counter[$key] = ($counter[$key] ?? 0) + 1;
    }
}
