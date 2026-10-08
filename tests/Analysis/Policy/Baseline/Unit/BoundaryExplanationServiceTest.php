<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Unit;

use Closure;
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
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
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
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryMode;
use Qualimetrix\Analysis\Policy\Baseline\BaselineIdentity;
use Qualimetrix\Analysis\Policy\Baseline\BoundaryExplanation;
use Qualimetrix\Analysis\Policy\Baseline\BoundaryExplanationService;
use Qualimetrix\Analysis\Policy\Baseline\BoundaryExplanationStatus;
use Qualimetrix\Analysis\Policy\Baseline\BoundaryRunFacts;
use Qualimetrix\Analysis\Policy\Baseline\BoundaryThresholdSources;
use Qualimetrix\Analysis\Policy\Baseline\Contract\CurrentMeasurement;
use Qualimetrix\Analysis\Policy\Baseline\Contract\RunCoverage;
use Qualimetrix\Analysis\Policy\Baseline\ExplainedSubject;
use Qualimetrix\Analysis\Policy\Baseline\InertBaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\InertEntryReason;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolInfo;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Finding\Support\StubChannelDeclarationRegistry;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\StubRuleCoverage;

#[CoversClass(BoundaryExplanationService::class)]
#[CoversClass(ExplainedSubject::class)]
final class BoundaryExplanationServiceTest extends TestCase
{
    private const string SYMBOL_KEY = 'declaration:callable:App\\Foo::bar@src/Foo.php';

    private BoundaryExplanationService $service;

    protected function setUp(): void
    {
        $this->service = new BoundaryExplanationService(self::producerEdge(), StubRuleCoverage::everyRuleRan(), StubChannelDeclarationRegistry::withDefaults());
    }

    /**
     * A channel-to-producer edge for the fixtures below: the channel's own name,
     * minus a trailing level segment where it carries one. That is exactly the
     * relation the retired left half of a channel key encoded, so a fixture
     * naming `complexity.ccn` still resolves to the rule a
     * `@qmx-threshold complexity.ccn` addresses.
     */
    private static function producerEdge(): ChannelIdentityInterface
    {
        $identity = self::createStub(ChannelIdentityInterface::class);
        $identity->method('producerOf')->willReturnCallback(static function (string $code): string {
            $lastDot = strrpos($code, '.');

            return $lastDot !== false && SymbolLevel::tryFrom(substr($code, $lastDot + 1)) !== null
                ? substr($code, 0, $lastDot)
                : $code;
        });

        return $identity;
    }

    #[Test]
    public function itClassifiesCurrentBaselineOnlyAndUnknownSymbolsExplicitly(): void
    {
        $channel = new FindingChannel('complexity.ccn');
        $baseline = $this->baselineWithEntry($channel, magnitudes: [25], count: 1);
        $currentRepository = $this->repositoryWithCallableSubject(
            SymbolPath::forMethod('App', 'Foo', 'bar'),
            'src/Foo.php',
            14,
        );

        $current = $this->explain(
            self::SYMBOL_KEY,
            $channel,
            $baseline,
            [],
            [],
            [],
            $currentRepository,
        );
        $baselineOnly = $this->explain(self::SYMBOL_KEY, $channel, $baseline, [], [], []);
        $inertBaselineOnly = $this->explain(
            self::SYMBOL_KEY,
            $channel,
            new Baseline(
                generated: new DateTimeImmutable(),
                scope: ['src'],
                entries: [],
                inertEntries: [InertBaselineEntry::forRaw(
                    self::SYMBOL_KEY,
                    $channel->code,
                    InertEntryReason::Malformed,
                    'test fixture',
                    'garbage',
                )],
                exclusions: self::fixtureExclusions(),
            ),
            [],
            [],
            [],
        );
        $unknown = $this->explain('callable:App\Missing::method', $channel, $baseline, [], [], [], $currentRepository);

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
        $channel = new FindingChannel('complexity.ccn');
        $baseline = $this->baselineWithEntry($channel, magnitudes: [25], count: 1);
        $currentFinding = $this->finding($channel, metricValue: 31);

        $explanation = $this->explain(
            subjectKey: self::SYMBOL_KEY,
            channelFilter: null,
            baseline: $baseline,
            measuredFindings: [$currentFinding],
            thresholdOverridesByFile: [
                'src/Foo.php' => [$this->thresholdOverride(1)],
            ],
            configuredThresholds: [$channel->code => [SymbolLevel::Callable->value => 20]],
        );

        self::assertCount(1, $explanation->boundaries);
        $boundary = $explanation->boundaries[0];

        self::assertNotNull($boundary->baseline);
        self::assertSame([25.0], $boundary->baseline->accepted?->magnitudes);
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
        $channel = new FindingChannel('complexity.ccn');
        $baseline = $this->baselineWithEntry($channel, magnitudes: [25], count: 1);
        $currentFinding = $this->finding($channel, metricValue: 31);

        $explanation = $this->explain(
            subjectKey: self::SYMBOL_KEY,
            channelFilter: $channel,
            baseline: $baseline,
            measuredFindings: [$currentFinding],
            thresholdOverridesByFile: [],
            configuredThresholds: [],
        );

        $source = $explanation->boundaries[0]->baseline;

        self::assertNotNull($source);
        self::assertNotNull($source->accepted);
        self::assertSame([25.0], $source->accepted->magnitudes);
        $now = $explanation->boundaries[0]->now;
        self::assertSame([31.0], $now->magnitudes);
        self::assertNotSame($source->accepted->magnitudes, $now->magnitudes);
        self::assertSame(1, $now->count);
    }

    /**
     * The ceiling declines to compare a magnitude group with a member that
     * reports no finite number, so the explanation counts such members
     * rather than silently leaving them out of the reading.
     */
    #[Test]
    public function itCountsMembersWithoutAFiniteMagnitudeInsteadOfDroppingThem(): void
    {
        $channel = new FindingChannel('complexity.ccn');
        $baseline = $this->baselineWithEntry($channel, magnitudes: [25, 25, 25], count: 3);

        $explanation = $this->explain(
            subjectKey: self::SYMBOL_KEY,
            channelFilter: $channel,
            baseline: $baseline,
            measuredFindings: [
                $this->finding($channel, metricValue: 20),
                $this->finding($channel, metricValue: \NAN),
                $this->finding($channel, metricValue: \INF),
            ],
            thresholdOverridesByFile: [],
            configuredThresholds: [],
        );

        $source = $explanation->boundaries[0]->baseline;

        self::assertNotNull($source);
        $now = $explanation->boundaries[0]->now;
        self::assertNull($now->magnitudes);
        self::assertSame(2, $now->membersWithoutMagnitude);
        self::assertSame(3, $now->count);
        self::assertSame(CurrentMeasurement::NOT_COMPARED, $now->state);
        self::assertSame([25.0, 25.0, 25.0], $source->accepted?->magnitudes);
    }

    /**
     * An entry the loader demoted but whose identity it read is a boundary
     * source in its own right, carrying why it cannot be applied; a line
     * whose identity it could not read is listed beside the boundaries.
     */
    #[Test]
    public function itExplainsInertEntriesRatherThanDenyingThem(): void
    {
        $channel = new FindingChannel('complexity.renamed-ccn');
        $identity = new BaselineIdentity(self::SYMBOL_KEY, $channel);
        $inert = InertBaselineEntry::forIdentity($identity, InertEntryReason::UndeclaredChannel, 'renamed', ['channel' => $channel->code]);
        $unreadable = InertBaselineEntry::forRaw(self::SYMBOL_KEY, null, InertEntryReason::Malformed, 'no channel', ['channel' => 5]);
        $elsewhere = InertBaselineEntry::forRaw('file:src/Other.php', null, InertEntryReason::Malformed, 'no channel', ['channel' => 6]);

        $explanation = $this->explain(
            subjectKey: self::SYMBOL_KEY,
            channelFilter: null,
            baseline: new Baseline(
                generated: new DateTimeImmutable('2026-08-05T12:00:00+03:00'),
                scope: ['src'],
                entries: [],
                inertEntries: [$inert, $unreadable, $elsewhere],
                exclusions: self::fixtureExclusions(),
            ),
            measuredFindings: [],
            thresholdOverridesByFile: [],
            configuredThresholds: [],
        );

        self::assertCount(1, $explanation->boundaries);
        $source = $explanation->boundaries[0]->baseline;
        self::assertNotNull($source);
        self::assertSame($inert, $source->inert);
        self::assertNull($source->accepted);
        self::assertSame([$unreadable], $explanation->unidentifiedEntries);
    }

    /**
     * The legitimate neighbour: an applicable entry whose members all
     * measure carries its mode and no unmeasured member.
     */
    #[Test]
    public function itCarriesTheModeAndNoStateForAnOrdinaryEntry(): void
    {
        $channel = new FindingChannel('complexity.ccn');
        $identity = new BaselineIdentity(self::SYMBOL_KEY, $channel);
        $baseline = new Baseline(
            generated: new DateTimeImmutable('2026-08-05T12:00:00+03:00'),
            scope: ['src'],
            entries: [new BaselineEntry($identity, [24], 1, BaselineEntryMode::Suppress)],
            exclusions: self::fixtureExclusions(),
        );

        $explanation = $this->explain(
            subjectKey: self::SYMBOL_KEY,
            channelFilter: $channel,
            baseline: $baseline,
            measuredFindings: [$this->finding($channel, metricValue: 32)],
            thresholdOverridesByFile: [],
            configuredThresholds: [],
        );

        $source = $explanation->boundaries[0]->baseline;
        self::assertNotNull($source);
        self::assertSame(BaselineEntryMode::Suppress, $source->mode);
        self::assertSame(0, $explanation->boundaries[0]->now->membersWithoutMagnitude);
        self::assertNull($source->inert);
        self::assertSame([], $explanation->unidentifiedEntries);
    }

    /**
     * A channel with no baseline entry and no matching annotation still
     * reports its `qmx.yaml` boundary — the other two sources are absent,
     * not zero.
     */
    #[Test]
    public function itReportsAbsentSourcesAsNullRatherThanAsZero(): void
    {
        $channel = new FindingChannel('coupling.cbo');

        $explanation = $this->explain(
            subjectKey: self::SYMBOL_KEY,
            channelFilter: $channel,
            baseline: $this->baselineWithEntry(
                new FindingChannel('complexity.ccn'),
                magnitudes: [25],
                count: 1,
            ),
            measuredFindings: [],
            thresholdOverridesByFile: [],
            configuredThresholds: [$channel->code => [SymbolLevel::Callable->value => 10]],
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
        $channel = new FindingChannel('code-smell.goto');

        $withZero = $this->explain(
            subjectKey: self::SYMBOL_KEY,
            channelFilter: $channel,
            baseline: null,
            measuredFindings: [],
            thresholdOverridesByFile: [],
            configuredThresholds: [$channel->code => [SymbolLevel::Callable->value => 0]],
        );

        $withoutEntry = $this->explain(
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
        $baselinedChannel = new FindingChannel('complexity.ccn');
        $firingOnlyChannel = new FindingChannel('coupling.cbo');

        $baseline = $this->baselineWithEntry($baselinedChannel, magnitudes: [25], count: 1);
        $firingOnly = $this->finding($firingOnlyChannel, metricValue: 12);

        $explanation = $this->explain(
            subjectKey: self::SYMBOL_KEY,
            channelFilter: null,
            baseline: $baseline,
            measuredFindings: [$firingOnly],
            thresholdOverridesByFile: [],
            configuredThresholds: [],
        );

        $channelKeys = array_map(
            static fn($boundary): string => $boundary->identity->channel->code,
            $explanation->boundaries,
        );

        self::assertCount(2, $explanation->boundaries);
        self::assertContains($baselinedChannel->code, $channelKeys);
        self::assertContains($firingOnlyChannel->code, $channelKeys);
    }

    /**
     * Without a current exact subject or an exact repository fallback, the
     * annotation is reported absent rather than guessed from a projection.
     */
    #[Test]
    public function itReportsNoAnnotationWhenNoSourceLocatesTheSymbol(): void
    {
        $channel = new FindingChannel('complexity.ccn');

        $explanation = $this->explain(
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
        $channel = new FindingChannel('complexity.ccn');

        $explanation = $this->explain(
            subjectKey: self::SYMBOL_KEY,
            channelFilter: $channel,
            baseline: null,
            measuredFindings: [],
            thresholdOverridesByFile: [
                'src/Foo.php' => [$this->thresholdOverride(12)],
            ],
            configuredThresholds: [$channel->code => [SymbolLevel::Callable->value => 10]],
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
        $channel = new FindingChannel('complexity.ccn');
        $baselineIdentity = new BaselineIdentity(self::SYMBOL_KEY, $channel, 'stored-occurrence');
        $baseline = new Baseline(
            new DateTimeImmutable('2026-08-05T12:00:00+03:00'),
            ['src'],
            [new BaselineEntry($baselineIdentity, [25], 1)],
            exclusions: self::fixtureExclusions(),
        );
        $measured = $this->findingWithIdentityParts(
            $channel,
            OccurrenceKey::semantic('measured', ['slot' => 2]),
        );

        $explanation = $this->explain(
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
        self::assertSame(0, $explanation->boundaries[0]->now->count);
    }

    #[Test]
    public function itUsesTheFirstExactMeasuredSubjectAcrossDifferentEdgeIdentities(): void
    {
        $channel = new FindingChannel('complexity.ccn');
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
            exclusions: self::fixtureExclusions(),
        );
        $measured = $this->findingWithIdentityParts($channel, dependencyTarget: $target);

        $explanation = $this->explain(
            self::SYMBOL_KEY,
            null,
            $baseline,
            [$measured],
            ['src/Foo.php' => [$this->thresholdOverride(1)]],
            [],
        );

        self::assertSame(DependencyType::New_, $explanation->boundaries[0]->identity->edge?->type);
        self::assertNotNull($explanation->boundaries[0]->annotation);
        self::assertSame(0, $explanation->boundaries[0]->now->count);
    }

    /**
     * A symbol the run never measured has no exact typed evidence, so the
     * fallback does not invent one from another subject.
     */
    #[Test]
    public function itReportsNoAnnotationForASymbolTheRunNeverMeasured(): void
    {
        $channel = new FindingChannel('complexity.ccn');

        $explanation = $this->explain(
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
        $channel = new FindingChannel('complexity.ccn');
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

        $explanation = $this->explain(
            self::SYMBOL_KEY,
            $channel,
            null,
            [],
            ['src/Foo.php' => [
                $override('*', 1, ControlScope::Class_, 1, 2),
                $override('complexity', 2, ControlScope::Callable, 1, 100),
                $override('complexity.ccn', 3, ControlScope::Callable, 10, 20),
                $override('complexity.ccn', 4, ControlScope::Callable, 10, 20),
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
                SymbolLevel::Namespace_->value => [new SymbolInfo($namespace, null, null)],
            ],
        );

        $index = ExplainedSubject::index($repository);

        self::assertIsArray($index);
        // The index content, not the number of repository calls, is what the
        // service promises: one aggregation level fewer than there are
        // declaration kinds must still reach every row.
        self::assertSame(
            [
                $first->toCanonical(),
                $second->toCanonical(),
                $callable->toCanonical(),
                $logical->toCanonical(),
                $namespace->toCanonical(),
            ],
            array_keys($index),
        );
        self::assertSame($first, $index[$first->toCanonical()]['subject']);
        $location = $index[$first->toCanonical()]['location'];
        self::assertNotNull($location);
        self::assertSame('src/First.php', $location[0]->value());
        self::assertNull($index[$namespace->toCanonical()]['subject']);
        self::assertSame(1, $repository->calls['allDeclarations']);
        self::assertSame(1, $repository->calls['allLogicalClasses']);
        self::assertSame(2, $repository->iterations['allDeclarations']);
        self::assertSame(1, $repository->iterations['allLogicalClasses']);
        // `all(Callable)` is the same enumeration as `allCallables()`, so the
        // double delegates and the service's two callable reads land on one
        // counter; a level counter of its own would mean the double had two
        // sources for one enumeration, which the real repository does not.
        self::assertSame(2, $repository->calls['allCallables']);
        self::assertSame(4, $repository->iterations['allCallables']);
        self::assertArrayNotHasKey('all:' . SymbolLevel::Callable->value, $repository->calls);
        self::assertArrayNotHasKey('all:' . SymbolLevel::Class_->value, $repository->calls);
        foreach (SymbolLevel::cases() as $level) {
            if ($level === SymbolLevel::Callable || $level === SymbolLevel::Class_) {
                continue;
            }

            self::assertSame(1, $repository->calls['all:' . $level->value]);
        }
        self::assertSame(1, $repository->iterations['all:' . SymbolLevel::Namespace_->value]);
    }

    #[Test]
    public function itEnumeratesOnlyExactClassDeclarationsWithoutReadingOtherSources(): void
    {
        $file = RelativePath::fromString('src/Multiple.php');
        $class = SymbolPath::forClass('App', 'Multiple');
        $classSubject = MetricSubject::declaration(DeclarationPath::of($class, $file, DeclarationOrdinal::fromRank(0)));
        $methodSubject = MetricSubject::declaration(DeclarationPath::of(
            SymbolPath::forMethod('App', 'Multiple', 'run'),
            $file,
            DeclarationOrdinal::fromRank(0),
        ));
        $repository = new CountingBoundaryRepository(
            declarations: [new SymbolInfo($classSubject, $file, 1), new SymbolInfo($methodSubject, $file, 2)],
            logicalClasses: [new SymbolInfo(MetricSubject::logicalClass(new LogicalClassPath($class)), null, null)],
        );

        $classes = iterator_to_array($repository->allClassDeclarations(), false);

        self::assertCount(1, $classes);
        self::assertSame($classSubject, $classes[0]->subject);
        self::assertSame(1, $repository->calls['allClassDeclarations']);
        self::assertSame(1, $repository->iterations['allClassDeclarations']);
        self::assertArrayNotHasKey('allDeclarations', $repository->calls);
        self::assertArrayNotHasKey('allLogicalClasses', $repository->calls);
    }

    #[Test]
    public function itFailsFastWhenAnExactRepositorySourceDropsItsTypedSubject(): void
    {
        $repository = new CountingBoundaryRepository(
            declarations: [new SymbolInfo(SymbolPath::forClass('App', 'Untyped'), null, null)],
        );
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Exact repository rows must retain their typed subject.');

        ExplainedSubject::index($repository);
    }

    #[Test]
    public function itMarksARetiredProjectCopyUnmeasuredWithoutMarkingADeclaredFileCopy(): void
    {
        $channel = new FindingChannel('duplication.clone');
        $project = new BaselineEntry(new BaselineIdentity('project:', $channel), [40], 1);
        $file = new BaselineEntry(new BaselineIdentity('file:src/Foo.php', $channel), [40], 1);
        $baseline = new Baseline(new DateTimeImmutable(), ['src'], [$project, $file], exclusions: self::fixtureExclusions());
        $service = new BoundaryExplanationService(self::producerEdge(), StubRuleCoverage::everyRuleRan(), StubChannelDeclarationRegistry::withDefaults());

        $declarations = StubChannelDeclarationRegistry::withDefaults();
        $coverage = StubRuleCoverage::completeFor($baseline);
        $old = $service->explain('project:', $channel, $baseline, new BoundaryThresholdSources([], []), new BoundaryRunFacts([], $coverage, null));
        $current = $service->explain('file:src/Foo.php', $channel, $baseline, new BoundaryThresholdSources([], []), new BoundaryRunFacts([], $coverage, null));

        self::assertSame(CurrentMeasurement::LEVEL_NOT_REPORTED, $old->boundaries[0]->now->state);
        self::assertSame(['file'], $old->boundaries[0]->now->declaredLevels);
        self::assertSame(CurrentMeasurement::NOTHING_REPORTED, $current->boundaries[0]->now->state);
    }

    #[Test]
    public function itReportsNowIndependentlyOfAbsentAndInertBaselineSources(): void
    {
        $channel = new FindingChannel('complexity.ccn');
        $inert = InertBaselineEntry::forIdentity(
            new BaselineIdentity(self::SYMBOL_KEY, $channel),
            InertEntryReason::DuplicateIdentity,
            'duplicated',
            null,
        );
        $baseline = new Baseline(new DateTimeImmutable(), ['elsewhere'], [], self::fixtureExclusions(), [$inert]);
        foreach ([null, $baseline] as $source) {
            $explanation = $this->explain(self::SYMBOL_KEY, $channel, $source, [$this->finding($channel, 16)], [], []);
            self::assertSame(CurrentMeasurement::REPORTED, $explanation->boundaries[0]->now->state);
            self::assertSame([16.0], $explanation->boundaries[0]->now->magnitudes);
            self::assertSame($source === null ? null : $inert, $explanation->boundaries[0]->baseline?->inert);
        }
        $clean = $this->explain(self::SYMBOL_KEY, $channel, null, [], [], []);
        self::assertSame(CurrentMeasurement::NOTHING_REPORTED, $clean->boundaries[0]->now->state);
    }

    #[Test]
    public function itDoesNotInferCurrentAbsenceForAnUnrecordedFileOutsideTheCapturedUniverse(): void
    {
        $channel = new FindingChannel('duplication.clone');
        $explanation = $this->explain(
            'file:src/Gone.php',
            $channel,
            null,
            [],
            [],
            [],
            coverage: $this->currentRun([], [], captured: false),
        );

        self::assertSame(CurrentMeasurement::OUTSIDE_COVERAGE, $explanation->boundaries[0]->now->state);
    }

    #[Test]
    public function itReportsCurrentAbsenceForAnUnrecordedFileInsideAKnownSelectedRoot(): void
    {
        $channel = new FindingChannel('duplication.clone');
        $explanation = $this->explain('file:src/Gone.php', $channel, null, [], [], [], coverage: $this->currentRun([], []));

        self::assertSame(CurrentMeasurement::NOTHING_REPORTED, $explanation->boundaries[0]->now->state);
    }

    #[Test]
    public function itReadsKnownEntryNowFromTheCeilingOutcome(): void
    {
        $channel = new FindingChannel('complexity.ccn');
        $baseline = $this->baselineWithEntry($channel, [25], 1);
        $queries = 0;
        $coverage = $this->currentRun([], [], presence: static function () use (&$queries): \Qualimetrix\Analysis\Run\Contract\Discovery\ProjectEntryPresence {
            return ++$queries === 1
                ? \Qualimetrix\Analysis\Run\Contract\Discovery\ProjectEntryPresence::Absent
                : \Qualimetrix\Analysis\Run\Contract\Discovery\ProjectEntryPresence::Present;
        });
        $removed = $this->explain(self::SYMBOL_KEY, $channel, $baseline, [], [], [], coverage: $coverage);
        self::assertSame('stale', $removed->boundaries[0]->baseline?->verdict);
        self::assertSame(CurrentMeasurement::NOTHING_REPORTED, $removed->boundaries[0]->now->state);
        self::assertSame(1, $queries);

        $outside = $this->explain(self::SYMBOL_KEY, $channel, $baseline, [], [], [], coverage: $this->currentRun([], ['src/Foo.php']));
        self::assertSame(CurrentMeasurement::OUTSIDE_COVERAGE, $outside->boundaries[0]->now->state);
        $this->service = new BoundaryExplanationService(self::producerEdge(), StubRuleCoverage::withSkipped(notSelected: ['complexity.ccn']), StubChannelDeclarationRegistry::withDefaults());
        $disabled = $this->explain(self::SYMBOL_KEY, $channel, $baseline, [], [], []);
        self::assertSame(CurrentMeasurement::NOT_MEASURED, $disabled->boundaries[0]->now->state);
        self::assertSame('producer-not-measured', $disabled->boundaries[0]->now->reason);
        $this->service = new BoundaryExplanationService(self::producerEdge(), StubRuleCoverage::everyRuleRan(), StubChannelDeclarationRegistry::withDefaults());

        $aggregateChannel = new FindingChannel('size.class-count');
        $declarations = StubChannelDeclarationRegistry::withDefaults();
        $declarations->declare($aggregateChannel->code, ChannelDeclaration::magnitude(\Qualimetrix\Core\Observation\WorseDirection::Higher, SymbolLevel::Namespace_));
        $finding = new Finding(
            subject: MetricSubject::aggregate(SymbolPath::forNamespace('App')),
            location: new Location(RelativePath::fromString('src/Foo.php'), 1),
            symbolPath: SymbolPath::forNamespace('App'),
            ruleName: 'size.class-count',
            code: 'size.class-count',
            message: 'class count',
            severity: Severity::Warning,
            metricValue: 3,
        );
        foreach ([
            'paths-differ' => [['src/Sub'], self::fixtureExclusions()],
            'exclusions-differ' => [['src'], new \Qualimetrix\Analysis\Policy\Baseline\Contract\RecordedExclusions(['exact:src/Bar.php'], \Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy::Exclude)],
        ] as $reason => [$recordedScope, $currentExclusions]) {
            $aggregate = new Baseline(new DateTimeImmutable(), $recordedScope, [new BaselineEntry(BaselineIdentity::forFinding($finding), [5], 1)], self::fixtureExclusions());
            $explanation = $this->explain(
                'ns:App',
                $aggregateChannel,
                $aggregate,
                [$finding],
                [],
                [],
                declarations: $declarations,
                coverage: $this->currentRun(['src/Foo.php'], ['src/Foo.php', 'src/Bar.php'], exclusions: $currentExclusions),
            );
            self::assertSame(CurrentMeasurement::NOT_COMPARED, $explanation->boundaries[0]->now->state, $reason);
            self::assertSame($reason, $explanation->boundaries[0]->now->reason);
            self::assertSame([3.0], $explanation->boundaries[0]->now->magnitudes);
            self::assertSame([5.0], $explanation->boundaries[0]->baseline?->accepted?->magnitudes);
        }
        $unknown = $this->explain(self::SYMBOL_KEY, $channel, $baseline, [], [], [], coverage: $this->currentRun(
            [],
            [],
            presence: static fn() => \Qualimetrix\Analysis\Run\Contract\Discovery\ProjectEntryPresence::Unknown,
        ));
        self::assertSame(CurrentMeasurement::OUTSIDE_COVERAGE, $unknown->boundaries[0]->now->state);
        self::assertSame('metadata-unknown', $unknown->boundaries[0]->now->reason);
    }

    #[Test]
    public function itNamesAnUndeclaredSubjectLevelBeforeClassifyingCoverage(): void
    {
        $this->service = new BoundaryExplanationService(self::producerEdge(), StubRuleCoverage::withSkipped(notSelected: ['complexity.ccn', 'duplication.clone']), StubChannelDeclarationRegistry::withDefaults());
        foreach ([['ns:App', 'complexity.ccn', ['callable', 'class']], ['project:', 'duplication.clone', ['file']]] as [$subject, $code, $levels]) {
            $explanation = $this->explain($subject, new FindingChannel($code), null, [], [], []);
            self::assertSame(CurrentMeasurement::LEVEL_NOT_REPORTED, $explanation->boundaries[0]->now->state);
            self::assertSame($levels, $explanation->boundaries[0]->now->declaredLevels);
        }
    }

    #[Test]
    public function itUsesTheDeclaredChannelShapeForNowEvenWhenItsEntryIsInert(): void
    {
        $declarations = StubChannelDeclarationRegistry::withDefaults();
        $declarations->declare('code-smell.eval', ChannelDeclaration::occurrence(SymbolLevel::Callable));
        foreach (['code-smell.eval' => 'occurrence', 'code-smell.renamed-eval' => null] as $code => $shape) {
            $channel = new FindingChannel($code);
            $inert = InertBaselineEntry::forIdentity(new BaselineIdentity(self::SYMBOL_KEY, $channel), InertEntryReason::ShapeMismatch, 'magnitude payload', ['magnitudes' => [25]]);
            $baseline = new Baseline(new DateTimeImmutable(), ['src'], [], self::fixtureExclusions(), [$inert]);
            $explanation = $this->explain(
                self::SYMBOL_KEY,
                $channel,
                $baseline,
                [$this->finding($channel, \NAN), $this->finding($channel, 9)],
                [],
                [],
                declarations: $declarations,
            );
            self::assertSame(CurrentMeasurement::REPORTED, $explanation->boundaries[0]->now->state);
            self::assertSame($shape, $explanation->boundaries[0]->now->shape);
            self::assertSame(2, $explanation->boundaries[0]->now->count);
            self::assertSame(0, $explanation->boundaries[0]->now->membersWithoutMagnitude);
            self::assertNull($explanation->boundaries[0]->now->magnitudes);
        }
    }

    /**
     * @param list<string> $analyzed
     * @param list<string> $present
     * @param ?Closure(): \Qualimetrix\Analysis\Run\Contract\Discovery\ProjectEntryPresence $presence
     */
    private function currentRun(array $analyzed, array $present, ?\Qualimetrix\Analysis\Policy\Baseline\Contract\RecordedExclusions $exclusions = null, ?Closure $presence = null, bool $captured = true): RunCoverage
    {
        $root = \Qualimetrix\Core\Path\AbsolutePath::fromString('/tmp/qmx-explain-fixture');
        $files = array_map(RelativePath::fromString(...), $present);
        $tree = new class ($files, $presence) implements \Qualimetrix\Analysis\Run\Contract\Discovery\ProjectTreeQueryInterface {
            /**
             * @param list<RelativePath> $files
             * @param ?Closure(): \Qualimetrix\Analysis\Run\Contract\Discovery\ProjectEntryPresence $presence
             */
            public function __construct(private array $files, private ?Closure $presence) {}
            public function hasDirectory(\Qualimetrix\Core\Path\AbsolutePath $directory): \Qualimetrix\Analysis\Run\Contract\Discovery\ProjectEntryPresence
            {
                return \Qualimetrix\Analysis\Run\Contract\Discovery\ProjectEntryPresence::Present;
            }
            public function hasFile(\Qualimetrix\Core\Path\AbsolutePath $root, RelativePath $file): \Qualimetrix\Analysis\Run\Contract\Discovery\ProjectEntryPresence
            {
                if ($this->presence !== null) {
                    return ($this->presence)();
                }
                return array_any($this->files, static fn(RelativePath $present): bool => $present->equals($file))
                    ? \Qualimetrix\Analysis\Run\Contract\Discovery\ProjectEntryPresence::Present
                    : \Qualimetrix\Analysis\Run\Contract\Discovery\ProjectEntryPresence::Absent;
            }
            public function snapshot(\Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse $universe): \Qualimetrix\Analysis\Run\Contract\Discovery\ProjectTreeSnapshot
            {
                return new \Qualimetrix\Analysis\Run\Contract\Discovery\ProjectTreeSnapshot($this->files, [], true);
            }
        };
        return new RunCoverage(
            \Qualimetrix\Analysis\Policy\Baseline\RunScope::fromRecorded(['src']),
            new \Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisCoverage(array_map(RelativePath::fromString(...), $analyzed), [], []),
            $exclusions ?? self::fixtureExclusions(),
            new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse($root, true, $captured ? [['target' => 'src', 'path' => $root->joinRelative(RelativePath::fromString('src'))]] : [], [], [], true, []),
            ['App\\' => ['src/']],
            $tree,
            \Qualimetrix\Analysis\Finding\Contract\ProjectScope\SubjectCoverageFacts::fromMeasured(new \Qualimetrix\Analysis\Finding\Contract\ProjectScope\ProjectScopeJudgement(), array_map(RelativePath::fromString(...), $analyzed), []),
        );
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
            exclusions: self::fixtureExclusions(),
        );
    }

    private function finding(FindingChannel $channel, int|float $metricValue): Finding
    {
        return new Finding(
            subject: MetricSubject::declaration(DeclarationPath::of(SymbolPath::forMethod('App', 'Foo', 'bar'), RelativePath::fromString('src/Foo.php'), DeclarationOrdinal::fromRank(0))),
            location: new Location(RelativePath::fromString('src/Foo.php'), 10),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: $channel->code,
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
            ruleName: $channel->code,
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
            'complexity.ccn',
            15,
            40,
            $line,
            MetricSubject::declaration(DeclarationPath::of(SymbolPath::forMethod('App', 'Foo', 'bar'), RelativePath::fromString('src/Foo.php'), DeclarationOrdinal::fromRank(0))),
            ControlScope::Class_,
            50,
        );
    }

    /**
     * @param list<Finding> $measuredFindings
     * @param array<string, list<ThresholdOverride>> $thresholdOverridesByFile
     * @param array<string, array<string, int|float>> $configuredThresholds
     */
    private function explain(
        string $subjectKey,
        ?FindingChannel $channelFilter,
        ?Baseline $baseline,
        array $measuredFindings,
        array $thresholdOverridesByFile,
        array $configuredThresholds,
        ?MetricRepositoryInterface $symbolLocations = null,
        ?ChannelDeclarationRegistryInterface $declarations = null,
        ?RunCoverage $coverage = null,
    ): BoundaryExplanation {
        $fixture = $baseline ?? new Baseline(new DateTimeImmutable(), ['src'], [], self::fixtureExclusions());

        $service = $declarations === null
            ? $this->service
            : new BoundaryExplanationService(self::producerEdge(), StubRuleCoverage::everyRuleRan(), $declarations);

        return $service->explain(
            $subjectKey,
            $channelFilter,
            $baseline,
            new BoundaryThresholdSources($thresholdOverridesByFile, $configuredThresholds),
            new BoundaryRunFacts($measuredFindings, $coverage ?? StubRuleCoverage::completeFor($fixture), $symbolLocations),
        );
    }

    private static function fixtureExclusions(): \Qualimetrix\Analysis\Policy\Baseline\Contract\RecordedExclusions
    {
        return new \Qualimetrix\Analysis\Policy\Baseline\Contract\RecordedExclusions(
            [],
            \Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy::Exclude,
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

    public function all(SymbolLevel $level): iterable
    {
        // The contract makes `all(Callable)` and `allCallables()` one
        // enumeration; a double that answered them from different maps would
        // let a consumer pass here and fail against the real repository.
        if ($level === SymbolLevel::Callable) {
            return $this->allCallables();
        }

        $source = 'all:' . $level->value;
        $this->count($this->calls, $source);

        return $this->iterate($source, $this->aggregates[$level->value] ?? []);
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

    public function addSubjectScalar(MetricSubject $subject, string $key, int|float $value): void {}

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

    public function allClassDeclarations(): iterable
    {
        $this->count($this->calls, __FUNCTION__);

        foreach ($this->declarations as $info) {
            if ($info->symbolPath->getType() !== \Qualimetrix\Core\Symbol\SymbolType::Class_) {
                continue;
            }

            $this->count($this->iterations, __FUNCTION__);
            yield $info;
        }
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

    public function mixedSpellings(): array
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
