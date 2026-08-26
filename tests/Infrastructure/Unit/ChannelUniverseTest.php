<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricRule;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Finding\ComputedMetricChannelFamily;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Rule\NameSelector;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Infrastructure\Rule\ChannelUniverse;

/**
 * Unit surface of the merged registry: what one instance answers, and how the
 * two halves that used to be separate objects now share one resolution rule.
 *
 * The end-to-end properties — every declared channel has a producer, the
 * reverse lookup covers the mechanisms a file-name scan cannot see — are
 * checked against the real container in
 * {@see \Qualimetrix\Tests\Analysis\Finding\Integration\ChannelUniverseCoverageTest}.
 */
#[CoversClass(ChannelUniverse::class)]
final class ChannelUniverseTest extends TestCase
{
    /** @var list<ComputedMetricDefinition> */
    private array $definitions = [];

    #[Test]
    public function itReturnsTheDeclarationForAStaticallyDeclaredChannel(): void
    {
        $channel = new FindingChannel('complexity.cyclomatic.callable');
        $declaration = ChannelDeclaration::magnitude(WorseDirection::Higher, SymbolLevel::Class_);

        $universe = $this->universe(declarations: [$channel->code => $declaration]);

        self::assertSame($declaration, $universe->declarationFor($channel));
    }

    #[Test]
    public function itReturnsNullForAnUndeclaredChannel(): void
    {
        $result = $this->universe()->declarationFor(new FindingChannel('code-smell.eval'));

        self::assertNull($result, 'An undeclared channel is not baselineable — that is observable, not an exception.');
    }

    #[Test]
    public function itExposesExactlyTheStaticDeclarationsItWasGiven(): void
    {
        $channel = new FindingChannel('maintainability.index');
        $declaration = ChannelDeclaration::magnitude(WorseDirection::Lower, SymbolLevel::Class_);

        $universe = $this->universe(declarations: [$channel->code => $declaration]);

        self::assertSame([$channel->code => $declaration], $universe->staticDeclarations());
    }

    #[Test]
    public function itResolvesABuiltInHealthChannelAtRunTimeFromTheDefinitionsInvertedFlag(): void
    {
        $this->definitions = [$this->definition('health.complexity', inverted: true)];

        $declaration = $this->universe()->declarationFor(
            new FindingChannel('health.complexity'),
        );

        self::assertNotNull($declaration);
        self::assertSame(ChannelShape::Magnitude, ComputedMetricRule::shape());
        self::assertSame(
            WorseDirection::Lower,
            $declaration->direction,
            'inverted=true means higher is better, i.e. lower is worse',
        );
    }

    #[Test]
    public function itResolvesAUserDefinedComputedMetricAtRunTimeAsHigherIsWorseByDefault(): void
    {
        $this->definitions = [$this->definition('computed.risk_score', inverted: false)];

        $declaration = $this->universe()->declarationFor(
            new FindingChannel('computed.risk_score'),
        );

        self::assertNotNull($declaration);
        self::assertSame(ChannelShape::Magnitude, ComputedMetricRule::shape());
        self::assertSame(WorseDirection::Higher, $declaration->direction);
    }

    #[Test]
    public function itReturnsNullForAComputedMetricChannelWithNoMatchingDefinition(): void
    {
        $this->definitions = [$this->definition('health.overall', inverted: true)];

        // A stale entry for a definition the user has since removed from config.
        $channel = new FindingChannel('computed.removed_metric');

        self::assertNull($this->universe()->declarationFor($channel));
    }

    #[Test]
    public function itPreservesTheProducerOfStaticChannelsWithDifferentRuleNames(): void
    {
        $universe = $this->universe(channelsByProducer: [
            'architecture.layer-violation' => [
                'architecture.layer-violation',
                'architecture.coverage',
            ],
        ]);

        self::assertSame(
            ['architecture.layer-violation', 'architecture.coverage'],
            array_map(
                static fn(FindingChannel $channel): string => $channel->code,
                $universe->channelsProducedBy('architecture.layer-violation'),
            ),
        );
    }

    /**
     * A built-in dimension is its own producer; a user-defined metric belongs
     * to the open one. Both directions in one case, so it cannot pass by
     * routing everything to a single name.
     */
    #[Test]
    public function itAddsRuntimeComputedMetricChannelsToTheirOwnProducer(): void
    {
        $this->definitions = [
            $this->definition('health.complexity', inverted: true),
            $this->definition('computed.branch_load', inverted: false),
        ];

        $universe = $this->universe();

        self::assertSame(
            ['health.complexity'],
            array_map(
                static fn(FindingChannel $channel): string => $channel->code,
                $universe->channelsProducedBy('health.complexity'),
            ),
        );
        self::assertSame(
            ['computed.branch_load'],
            array_map(
                static fn(FindingChannel $channel): string => $channel->code,
                $universe->channelsProducedBy(ComputedMetricRule::NAME),
            ),
        );
    }

    #[Test]
    public function itAnswersTheReverseLookupWithTheProducingRuleNotTheChannelsOwnRuleName(): void
    {
        $universe = $this->universe(channelsByProducer: [
            'architecture.layer-violation' => ['architecture.coverage'],
            'coupling.cbo' => ['coupling.cbo.class'],
        ]);

        self::assertSame('coupling.cbo', $universe->producerOf('coupling.cbo.class'));
        self::assertSame(
            'architecture.layer-violation',
            $universe->producerOf('architecture.coverage'),
            'Stripping a dotted suffix would answer "architecture" — the producer is not derivable from the name.',
        );
        self::assertNull($universe->producerOf('coupling.cbo'));
    }

    #[Test]
    public function itAnswersTheReverseLookupForAConfiguredComputedMetric(): void
    {
        $this->definitions = [$this->definition('health.cohesion', inverted: true)];

        $universe = $this->universe();

        self::assertSame('health.cohesion', $universe->producerOf('health.cohesion'));
        self::assertTrue($universe->hasChannel('health.cohesion'));
        self::assertFalse($universe->hasChannel('health.removed'));
    }

    #[Test]
    public function itKnowsEveryRegisteredRuleIncludingOnesDeclaringNoChannel(): void
    {
        $universe = $this->universe(thresholdSupport: [
            'coupling.cbo' => true,
            'code-smell.eval' => false,
            ComputedMetricRule::NAME => false,
        ]);

        self::assertSame(['coupling.cbo', 'code-smell.eval', ComputedMetricRule::NAME], $universe->ruleNames());
        self::assertTrue($universe->hasRule('code-smell.eval'));
        self::assertFalse($universe->hasRule('coupling.cbo.class'), 'A violation code is not a rule name.');
    }

    #[Test]
    public function itReportsThresholdOverrideSupportAsDeclaredAndDefaultsToNoSupport(): void
    {
        $universe = $this->universe(thresholdSupport: ['coupling.cbo' => true, 'code-smell.eval' => false]);

        self::assertTrue($universe->supportsThresholdOverride('coupling.cbo'));
        self::assertFalse($universe->supportsThresholdOverride('code-smell.eval'));
        self::assertFalse($universe->supportsThresholdOverride('nothing.at-all'));
    }

    #[Test]
    public function itExpandsAGroupSelectorIntoStrictDescendantsOnly(): void
    {
        $universe = $this->universe(channelsByProducer: [
            'coupling.cbo' => ['coupling.cbo.class', 'coupling.cbo.namespace'],
            'code-smell.eval' => ['code-smell.eval'],
        ]);

        $selector = NameSelector::tryParse('coupling.cbo.*');
        self::assertNotNull($selector);

        self::assertSame(
            ['coupling.cbo.class', 'coupling.cbo.namespace'],
            array_map(static fn(FindingChannel $c): string => $c->code, $universe->expand($selector)),
        );
    }

    #[Test]
    public function itExpandsAnEqualitySelectorIntoTheOneChannelItNames(): void
    {
        $universe = $this->universe(channelsByProducer: [
            'coupling.cbo' => ['coupling.cbo.class'],
        ]);

        $exact = NameSelector::tryParse('coupling.cbo.class');
        $parent = NameSelector::tryParse('coupling.cbo');
        self::assertNotNull($exact);
        self::assertNotNull($parent);

        self::assertSame(
            ['coupling.cbo.class'],
            array_map(static fn(FindingChannel $c): string => $c->code, $universe->expand($exact)),
        );
        self::assertSame(
            [],
            $universe->expand($parent),
            'The rule name of a multi-channel rule addresses no channel — that is what forces full qualification.',
        );
    }

    #[Test]
    public function itExpandsOverConfiguredComputedMetricsTheSameWayAsStaticChannels(): void
    {
        $this->definitions = [
            $this->definition('health.cohesion', inverted: true),
            $this->definition('health.coupling', inverted: true),
        ];

        $selector = NameSelector::tryParse('health.*');
        self::assertNotNull($selector);

        self::assertSame(
            ['health.cohesion', 'health.coupling'],
            array_map(static fn(FindingChannel $c): string => $c->code, $this->universe()->expand($selector)),
        );
    }

    #[Test]
    public function itBuildsAPreflightUniverseOverExplicitDefinitionsWithoutTouchingTheLiveCatalog(): void
    {
        $this->definitions = [$this->definition('health.live', inverted: true)];
        $universe = $this->universe(channelsByProducer: [
            'coupling.cbo' => ['coupling.cbo.class'],
        ], thresholdSupport: ['coupling.cbo' => true]);

        $candidate = $universe->snapshot(new ResolvedComputedMetricDefinitions([
            $this->definition('health.candidate', inverted: true),
        ]));

        self::assertSame(
            ['health.candidate'],
            array_map(
                static fn(FindingChannel $c): string => $c->code,
                $candidate->channelsProducedBy(ComputedMetricRule::NAME),
            ),
        );
        self::assertNull($candidate->producerOf('health.live'), 'The candidate universe answers from its own catalog.');
        self::assertSame('coupling.cbo', $candidate->producerOf('coupling.cbo.class'), 'The static half is carried over.');
        self::assertTrue($candidate->supportsThresholdOverride('coupling.cbo'));
        self::assertSame(
            ComputedMetricRule::NAME,
            $universe->producerOf('health.live'),
            'Building a candidate must not disturb the committed universe.',
        );
    }

    #[Test]
    public function itProjectsEveryDeclaredLevelOfAComputedMetricOntoTheChannel(): void
    {
        $this->definitions = [$this->definitionWithLevels(
            'health.overall',
            [SymbolLevel::Class_, SymbolLevel::Namespace_, SymbolLevel::Project],
        )];

        $declaration = $this->universe()->declarationFor(
            new FindingChannel('health.overall'),
        );

        self::assertNotNull($declaration);
        self::assertSame(
            [SymbolLevel::Class_, SymbolLevel::Namespace_, SymbolLevel::Project],
            $declaration->levels,
        );
    }

    /**
     * `levels: []` is accepted by the resolver and makes the metric emit
     * nothing, so it has no channel to declare — the same answer an unknown
     * name gets, and the one documented behaviour change of the level work.
     */
    #[Test]
    public function itDeclaresNothingForAComputedMetricThatReportsAtNoLevel(): void
    {
        $this->definitions = [$this->definitionWithLevels('computed.silent', [])];

        self::assertNull($this->universe()->declarationFor(
            new FindingChannel('computed.silent'),
        ));
    }

    /**
     * A repeated level cannot reach this lookup at all: it is refused when
     * the {@see ComputedMetricDefinition} carrying it is built, which is
     * while configuration resolves. Pinned here because the alternative —
     * refusing it in the channel declaration — would have thrown from a
     * lookup that every finding makes.
     */
    #[Test]
    public function aRepeatedLevelCannotReachTheChannelDeclaration(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('declares the same level more than once');

        $this->definitionWithLevels('computed.repeated', [SymbolLevel::Class_, SymbolLevel::Class_]);
    }

    /**
     * The name space is one, so a computed metric may not take a name the
     * static half already owns.
     *
     * The static half used to win in silence: `producerOf()` answered from its
     * own map and never asked the catalog, so a colliding definition looked
     * like a working configuration while addressing nothing. The input is
     * measured rather than invented — `computed_metrics: { computed.health: … }`
     * is accepted by the resolver (its name carries the required `computed.`
     * prefix) and names the rule every computed channel is produced under.
     */
    #[Test]
    public function itRefusesAComputedMetricNamedAfterARegisteredRule(): void
    {
        $universe = $this->universe(thresholdSupport: ['computed.taken' => false]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Computed metric "computed.taken" is named after a registered rule');

        $universe->snapshot(new ResolvedComputedMetricDefinitions([
            $this->definition('computed.taken', false),
        ]));
    }

    /**
     * The six built-in dimensions are addressable rule names AND the names of
     * the definitions they publish, so a membership test alone would refuse
     * every run before it read a file. Only a name whose producer is somebody
     * else is a collision.
     */
    #[Test]
    public function itAcceptsABuiltInDimensionThatIsAlsoItsOwnProducersName(): void
    {
        $universe = $this->universe(
            thresholdSupport: array_fill_keys(ComputedMetricChannelFamily::PRODUCER_RULE_NAMES, false),
        );

        $snapshot = $universe->snapshot(new ResolvedComputedMetricDefinitions(array_map(
            fn(string $name): ComputedMetricDefinition => $this->definition($name, false),
            ComputedMetricChannelFamily::HEALTH_PRODUCER_RULE_NAMES,
        )));

        self::assertSame('health.cohesion', $snapshot->producerOf('health.cohesion'));
    }

    #[Test]
    public function itRefusesAComputedMetricNamedAfterAStaticallyDeclaredChannel(): void
    {
        $universe = $this->universe(
            declarations: ['computed.taken' => ChannelDeclaration::occurrence(SymbolLevel::Class_)],
            channelsByProducer: ['size.class-count' => ['computed.taken']],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('named after a channel declared by rule "size.class-count"');

        $universe->snapshot(new ResolvedComputedMetricDefinitions([
            $this->definition('computed.taken', false),
        ]));
    }

    /** The refusal is a refusal of a collision, not of every snapshot. */
    #[Test]
    public function itAcceptsASnapshotWhoseComputedNamesAreFree(): void
    {
        $universe = $this->universe(thresholdSupport: [ComputedMetricRule::NAME => false]);

        $snapshot = $universe->snapshot(new ResolvedComputedMetricDefinitions([
            $this->definition('computed.branch_load', false),
        ]));

        self::assertSame(ComputedMetricRule::NAME, $snapshot->producerOf('computed.branch_load'));
    }

    /**
     * @param array<string, ChannelDeclaration> $declarations
     * @param array<string, list<string>> $channelsByProducer
     * @param array<string, bool> $thresholdSupport
     */
    private function universe(
        array $declarations = [],
        array $channelsByProducer = [],
        array $thresholdSupport = [],
    ): ChannelUniverse {
        return new ChannelUniverse(
            $declarations,
            $channelsByProducer,
            $thresholdSupport,
            $this->catalog(),
        );
    }

    private function definition(string $name, bool $inverted): ComputedMetricDefinition
    {
        return new ComputedMetricDefinition(
            name: $name,
            formulas: ['class' => 'ccn__avg'],
            description: 'Fixture definition',
            levels: [SymbolLevel::Class_],
            inverted: $inverted,
        );
    }

    /**
     * @param list<SymbolLevel> $levels
     */
    private function definitionWithLevels(string $name, array $levels): ComputedMetricDefinition
    {
        return new ComputedMetricDefinition(
            name: $name,
            formulas: ['class' => 'ccn__avg'],
            description: 'Fixture definition',
            levels: $levels,
        );
    }

    private function catalog(): ComputedMetricDefinitionCatalogInterface
    {
        $catalog = self::createStub(ComputedMetricDefinitionCatalogInterface::class);
        $catalog->method('all')->willReturnCallback(fn(): array => $this->definitions);
        $catalog->method('find')->willReturnCallback(function (string $name): ?ComputedMetricDefinition {
            foreach ($this->definitions as $definition) {
                if ($definition->name === $name) {
                    return $definition;
                }
            }

            return null;
        });

        return $catalog;
    }
}
