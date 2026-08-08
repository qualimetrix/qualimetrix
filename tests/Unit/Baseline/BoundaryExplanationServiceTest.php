<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Baseline;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Repository\InMemoryMetricRepository;
use Qualimetrix\Baseline\Baseline;
use Qualimetrix\Baseline\BaselineEntry;
use Qualimetrix\Baseline\BaselineIdentity;
use Qualimetrix\Baseline\BoundaryExplanationService;
use Qualimetrix\Baseline\BoundaryExplanationStatus;
use Qualimetrix\Baseline\InertBaselineEntry;
use Qualimetrix\Baseline\InertEntryReason;
use Qualimetrix\Core\Metric\MetricBag;
use Qualimetrix\Core\Metric\MetricRepositoryInterface;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Suppression\ThresholdOverride;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Core\Violation\ViolationChannel;

#[CoversClass(BoundaryExplanationService::class)]
final class BoundaryExplanationServiceTest extends TestCase
{
    private const string SYMBOL_KEY = 'method:App\Foo::bar';

    private BoundaryExplanationService $service;

    protected function setUp(): void
    {
        $this->service = new BoundaryExplanationService();
    }

    #[Test]
    public function itClassifiesCurrentBaselineOnlyAndUnknownSymbolsExplicitly(): void
    {
        $channel = new ViolationChannel('complexity.cyclomatic', 'complexity.cyclomatic.method');
        $baseline = $this->baselineWithEntry($channel, magnitudes: [25], count: 1);
        $currentRepository = $this->repositoryLocating(
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
        $unknown = $this->service->explain('method:App\Missing::method', $channel, $baseline, [], [], [], $currentRepository);

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
        $channel = new ViolationChannel('complexity.cyclomatic', 'complexity.cyclomatic.method');
        $baseline = $this->baselineWithEntry($channel, magnitudes: [25], count: 1);
        $currentViolation = $this->violation($channel, metricValue: 31);

        $explanation = $this->service->explain(
            symbolKey: self::SYMBOL_KEY,
            channelFilter: null,
            baseline: $baseline,
            measuredViolations: [$currentViolation],
            thresholdOverridesByFile: [
                'src/Foo.php' => [new ThresholdOverride('complexity.cyclomatic', 15, 40, 1, 50)],
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
        $channel = new ViolationChannel('complexity.cyclomatic', 'complexity.cyclomatic.method');
        $baseline = $this->baselineWithEntry($channel, magnitudes: [25], count: 1);
        $currentViolation = $this->violation($channel, metricValue: 31);

        $explanation = $this->service->explain(
            symbolKey: self::SYMBOL_KEY,
            channelFilter: $channel,
            baseline: $baseline,
            measuredViolations: [$currentViolation],
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
        $channel = new ViolationChannel('coupling.cbo', 'coupling.cbo.class');

        $explanation = $this->service->explain(
            symbolKey: self::SYMBOL_KEY,
            channelFilter: $channel,
            baseline: $this->baselineWithEntry(
                new ViolationChannel('complexity.cyclomatic', 'complexity.cyclomatic.method'),
                magnitudes: [25],
                count: 1,
            ),
            measuredViolations: [],
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
        $channel = new ViolationChannel('code-smell.goto', 'code-smell.goto');

        $withZero = $this->service->explain(
            symbolKey: self::SYMBOL_KEY,
            channelFilter: $channel,
            baseline: null,
            measuredViolations: [],
            thresholdOverridesByFile: [],
            configuredThresholds: [$channel->toKey() => 0],
        );

        $withoutEntry = $this->service->explain(
            symbolKey: self::SYMBOL_KEY,
            channelFilter: $channel,
            baseline: null,
            measuredViolations: [],
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
        $baselinedChannel = new ViolationChannel('complexity.cyclomatic', 'complexity.cyclomatic.method');
        $firingOnlyChannel = new ViolationChannel('coupling.cbo', 'coupling.cbo.class');

        $baseline = $this->baselineWithEntry($baselinedChannel, magnitudes: [25], count: 1);
        $firingOnly = $this->violation($firingOnlyChannel, metricValue: 12);

        $explanation = $this->service->explain(
            symbolKey: self::SYMBOL_KEY,
            channelFilter: null,
            baseline: $baseline,
            measuredViolations: [$firingOnly],
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
     * The annotation source needs a file and line to scope the match. With
     * no finding to read them off *and* no measured symbol to fall back on,
     * there is nowhere to get them, and the annotation is reported absent
     * rather than guessed.
     */
    #[Test]
    public function itReportsNoAnnotationWhenNoSourceLocatesTheSymbol(): void
    {
        $channel = new ViolationChannel('complexity.cyclomatic', 'complexity.cyclomatic.method');

        $explanation = $this->service->explain(
            symbolKey: self::SYMBOL_KEY,
            channelFilter: $channel,
            baseline: null,
            measuredViolations: [],
            thresholdOverridesByFile: [
                'src/Foo.php' => [new ThresholdOverride('complexity.cyclomatic', 15, 40, 1, 50)],
            ],
            configuredThresholds: [],
        );

        self::assertNull($explanation->boundaries[0]->annotation);
    }

    /**
     * **ADR 0017's example, which used to be the case that did not work.**
     * "`qmx.yaml` says 10; annotation raises it to 40" describes a symbol
     * that is *not* violating anything — the raised threshold is normally
     * why the rule stopped firing. Reading the symbol's location only off
     * its findings therefore went silent exactly where the annotation
     * mattered most; the run's measured symbols answer where it is declared
     * whether or not it violates.
     */
    #[Test]
    public function itFindsTheAnnotationForASymbolThatViolatesNothing(): void
    {
        $channel = new ViolationChannel('complexity.cyclomatic', 'complexity.cyclomatic.method');

        $explanation = $this->service->explain(
            symbolKey: self::SYMBOL_KEY,
            channelFilter: $channel,
            baseline: null,
            measuredViolations: [],
            thresholdOverridesByFile: [
                'src/Foo.php' => [new ThresholdOverride('complexity.cyclomatic', 15, 40, 12, 50)],
            ],
            configuredThresholds: [$channel->toKey() => 10],
            symbolLocations: $this->repositoryLocating(SymbolPath::forMethod('App', 'Foo', 'bar'), 'src/Foo.php', 14),
        );

        $boundary = $explanation->boundaries[0];

        self::assertNotNull($boundary->annotation, 'the annotation is what raised the threshold; it must be printed');
        self::assertSame(40, $boundary->annotation->error);
        self::assertSame(10, $boundary->configuredThreshold);
        self::assertNull($boundary->baseline);
    }

    /**
     * A symbol the run never measured has no declaration site anywhere, so
     * the fallback does not invent one.
     */
    #[Test]
    public function itReportsNoAnnotationForASymbolTheRunNeverMeasured(): void
    {
        $channel = new ViolationChannel('complexity.cyclomatic', 'complexity.cyclomatic.method');

        $explanation = $this->service->explain(
            symbolKey: self::SYMBOL_KEY,
            channelFilter: $channel,
            baseline: null,
            measuredViolations: [],
            thresholdOverridesByFile: [
                'src/Foo.php' => [new ThresholdOverride('complexity.cyclomatic', 15, 40, 1, 50)],
            ],
            configuredThresholds: [],
            symbolLocations: $this->repositoryLocating(SymbolPath::forMethod('App', 'Other', 'baz'), 'src/Other.php', 3),
        );

        self::assertNull($explanation->boundaries[0]->annotation);
    }

    /**
     * A repository that knows where exactly one symbol is declared — the
     * shape a run leaves behind for every symbol it measured, violating or
     * not.
     */
    private function repositoryLocating(SymbolPath $symbol, string $file, int $line): MetricRepositoryInterface
    {
        $repository = new InMemoryMetricRepository();
        $repository->add($symbol, new MetricBag(), RelativePath::fromString($file), $line);

        return $repository;
    }

    /**
     * @param ?list<int|float> $magnitudes
     */
    private function baselineWithEntry(ViolationChannel $channel, ?array $magnitudes, int $count): Baseline
    {
        $identity = new BaselineIdentity(self::SYMBOL_KEY, $channel);

        return new Baseline(
            generated: new DateTimeImmutable('2026-08-05T12:00:00+03:00'),
            scope: ['src'],
            entries: [new BaselineEntry($identity, $magnitudes, $count)],
        );
    }

    private function violation(ViolationChannel $channel, int|float $metricValue): Violation
    {
        return new Violation(
            location: new Location(RelativePath::fromString('src/Foo.php'), 10),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: $channel->ruleName,
            violationCode: $channel->violationCode,
            message: 'test finding',
            severity: Severity::Warning,
            metricValue: $metricValue,
        );
    }
}
