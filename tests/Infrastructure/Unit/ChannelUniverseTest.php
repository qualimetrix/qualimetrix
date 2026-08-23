<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\ComputedMetricRule;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinitionCatalogInterface;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\NameSelector;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Symbol\SymbolType;
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
        $channel = new ViolationChannel('complexity.cyclomatic', 'complexity.cyclomatic.callable');
        $declaration = ChannelDeclaration::magnitude(WorseDirection::Higher, SymbolLevel::Class_);

        $universe = $this->universe(declarations: [$channel->toKey() => $declaration]);

        self::assertSame($declaration, $universe->declarationFor($channel));
    }

    #[Test]
    public function itReturnsNullForAnUndeclaredChannel(): void
    {
        $result = $this->universe()->declarationFor(new ViolationChannel('code-smell.eval', 'code-smell.eval'));

        self::assertNull($result, 'An undeclared channel is not baselineable — that is observable, not an exception.');
    }

    #[Test]
    public function itExposesExactlyTheStaticDeclarationsItWasGiven(): void
    {
        $channel = new ViolationChannel('maintainability.index', 'maintainability.index');
        $declaration = ChannelDeclaration::magnitude(WorseDirection::Lower, SymbolLevel::Class_);

        $universe = $this->universe(declarations: [$channel->toKey() => $declaration]);

        self::assertSame([$channel->toKey() => $declaration], $universe->staticDeclarations());
    }

    #[Test]
    public function itResolvesABuiltInHealthChannelAtRunTimeFromTheDefinitionsInvertedFlag(): void
    {
        $this->definitions = [$this->definition('health.complexity', inverted: true)];

        $declaration = $this->universe()->declarationFor(
            new ViolationChannel(ComputedMetricRule::NAME, 'health.complexity'),
        );

        self::assertNotNull($declaration);
        self::assertSame(ChannelShape::Magnitude, $declaration->shape);
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
            new ViolationChannel(ComputedMetricRule::NAME, 'computed.risk_score'),
        );

        self::assertNotNull($declaration);
        self::assertSame(ChannelShape::Magnitude, $declaration->shape);
        self::assertSame(WorseDirection::Higher, $declaration->direction);
    }

    #[Test]
    public function itReturnsNullForAComputedMetricChannelWithNoMatchingDefinition(): void
    {
        $this->definitions = [$this->definition('health.overall', inverted: true)];

        // A stale entry for a definition the user has since removed from config.
        $channel = new ViolationChannel(ComputedMetricRule::NAME, 'computed.removed_metric');

        self::assertNull($this->universe()->declarationFor($channel));
    }

    #[Test]
    public function itPreservesTheProducerOfStaticChannelsWithDifferentRuleNames(): void
    {
        $universe = $this->universe(channelsByProducer: [
            'architecture.layer-violation' => [
                'architecture.layer-violation#architecture.layer-violation',
                'architecture.coverage#architecture.coverage',
            ],
        ]);

        self::assertSame(
            ['architecture.layer-violation#architecture.layer-violation', 'architecture.coverage#architecture.coverage'],
            array_map(
                static fn(ViolationChannel $channel): string => $channel->toKey(),
                $universe->channelsProducedBy('architecture.layer-violation'),
            ),
        );
    }

    #[Test]
    public function itAddsRuntimeComputedMetricChannelsToTheirProducer(): void
    {
        $this->definitions = [$this->definition('health.complexity', inverted: true)];

        $channels = $this->universe()->channelsProducedBy(ComputedMetricRule::NAME);

        self::assertSame(
            ['computed.health#health.complexity'],
            array_map(static fn(ViolationChannel $channel): string => $channel->toKey(), $channels),
        );
    }

    #[Test]
    public function itAnswersTheReverseLookupWithTheProducingRuleNotTheChannelsOwnRuleName(): void
    {
        $universe = $this->universe(channelsByProducer: [
            'architecture.layer-violation' => ['architecture.coverage#architecture.coverage'],
            'coupling.cbo' => ['coupling.cbo#coupling.cbo.class'],
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

        self::assertSame(ComputedMetricRule::NAME, $universe->producerOf('health.cohesion'));
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
            'coupling.cbo' => ['coupling.cbo#coupling.cbo.class', 'coupling.cbo#coupling.cbo.namespace'],
            'code-smell.eval' => ['code-smell.eval#code-smell.eval'],
        ]);

        $selector = NameSelector::tryParse('coupling.cbo.*');
        self::assertNotNull($selector);

        self::assertSame(
            ['coupling.cbo#coupling.cbo.class', 'coupling.cbo#coupling.cbo.namespace'],
            array_map(static fn(ViolationChannel $c): string => $c->toKey(), $universe->expand($selector)),
        );
    }

    #[Test]
    public function itExpandsAnEqualitySelectorIntoTheOneChannelItNames(): void
    {
        $universe = $this->universe(channelsByProducer: [
            'coupling.cbo' => ['coupling.cbo#coupling.cbo.class'],
        ]);

        $exact = NameSelector::tryParse('coupling.cbo.class');
        $parent = NameSelector::tryParse('coupling.cbo');
        self::assertNotNull($exact);
        self::assertNotNull($parent);

        self::assertSame(
            ['coupling.cbo#coupling.cbo.class'],
            array_map(static fn(ViolationChannel $c): string => $c->toKey(), $universe->expand($exact)),
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
            ['computed.health#health.cohesion', 'computed.health#health.coupling'],
            array_map(static fn(ViolationChannel $c): string => $c->toKey(), $this->universe()->expand($selector)),
        );
    }

    #[Test]
    public function itBuildsAPreflightUniverseOverExplicitDefinitionsWithoutTouchingTheLiveCatalog(): void
    {
        $this->definitions = [$this->definition('health.live', inverted: true)];
        $universe = $this->universe(channelsByProducer: [
            'coupling.cbo' => ['coupling.cbo#coupling.cbo.class'],
        ], thresholdSupport: ['coupling.cbo' => true]);

        $candidate = $universe->snapshot(new ResolvedComputedMetricDefinitions([
            $this->definition('health.candidate', inverted: true),
        ]));

        self::assertSame(
            ['computed.health#health.candidate'],
            array_map(
                static fn(ViolationChannel $c): string => $c->toKey(),
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
            ComputedMetricRule::NAME,
            $this->catalog(),
        );
    }

    private function definition(string $name, bool $inverted): ComputedMetricDefinition
    {
        return new ComputedMetricDefinition(
            name: $name,
            formulas: ['class' => 'ccn__avg'],
            description: 'Fixture definition',
            levels: [SymbolType::Class_],
            inverted: $inverted,
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
