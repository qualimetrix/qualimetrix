<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Evidence\Duplication\CodeDuplicationOptions;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Configuration\FindingConfiguration;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Rule\ChannelLevelSelector;
use Qualimetrix\Analysis\Finding\Contract\RuleEnablement;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Finding\Contract\Selection\RuleEnablementResolver;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Infrastructure\Rule\ChannelUniverse;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;

#[CoversClass(RuleEnablementResolver::class)]
final class RuleSelectorTest extends TestCase
{
    private ChannelUniverse $channels;

    protected function setUp(): void
    {
        $this->channels = self::selectorWithLevels(
            [
                'computed.health' => ['health.complexity', 'health.cohesion'],
                'architecture.layer-violation' => ['architecture.layer-violation', 'architecture.coverage-gap'],
            ],
            [
                'health.complexity' => [SymbolLevel::Class_, SymbolLevel::Namespace_, SymbolLevel::Callable],
                'health.cohesion' => [SymbolLevel::Class_],
                'architecture.layer-violation' => [SymbolLevel::Class_],
                'architecture.coverage-gap' => [SymbolLevel::Class_],
            ],
        );
    }

    #[Test]
    public function itSelectsEveryChannelThroughTheProducerName(): void
    {
        self::assertTrue(self::enablement($this->channels, ['computed.health'], [])->runs('computed.health'));
        self::assertTrue(self::enablement($this->channels, ['computed.health'], [])->publishes(new FindingChannel('health.complexity'), SymbolLevel::Class_));
    }

    #[Test]
    public function itSelectsTheProducerAndOnlyTheAddressedCode(): void
    {
        self::assertTrue(self::enablement($this->channels, ['health.complexity'], [])->runs('computed.health'));
        self::assertTrue(self::enablement($this->channels, ['health.complexity'], [])->publishes(new FindingChannel('health.complexity'), SymbolLevel::Class_));
        self::assertFalse(self::enablement($this->channels, ['health.complexity'], [])->publishes(new FindingChannel('health.cohesion'), SymbolLevel::Class_));
    }

    #[Test]
    public function itSelectsAChannelWhoseRuleNameDiffersFromItsProducer(): void
    {
        self::assertTrue(self::enablement($this->channels, ['architecture.coverage-gap'], [])->runs('architecture.layer-violation'));
        self::assertTrue(self::enablement($this->channels, ['architecture.coverage-gap'], [])->publishes(new FindingChannel('architecture.coverage-gap'), SymbolLevel::Class_));
    }

    #[Test]
    public function itSupportsAnExplicitFullChannelSelector(): void
    {
        $fullSelector = 'health.complexity';

        self::assertTrue(self::enablement($this->channels, [$fullSelector], [])->runs('computed.health'));
        self::assertTrue(self::enablement($this->channels, [$fullSelector], [])->publishes(new FindingChannel('health.complexity'), SymbolLevel::Class_));
        self::assertFalse(self::enablement($this->channels, [$fullSelector], [])->publishes(new FindingChannel('health.cohesion'), SymbolLevel::Class_));
    }

    /**
     * A level narrows a channel selector without touching the channel's other
     * levels, and it never reaches the producer: disabling one level of a
     * channel must leave the rule running, or the other level would go with
     * it.
     */
    #[Test]
    public function itNarrowsAChannelSelectorToOneLevel(): void
    {
        $pair = 'health.complexity' . ChannelLevelSelector::LEVEL_SEPARATOR . SymbolLevel::Class_->value;

        self::assertTrue(self::enablement($this->channels, [], [$pair])->runs('computed.health'));
        self::assertFalse(self::enablement($this->channels, [], [$pair])->publishes(new FindingChannel('health.complexity'), SymbolLevel::Class_));
        self::assertTrue(self::enablement($this->channels, [], [$pair])->publishes(new FindingChannel('health.complexity'), SymbolLevel::Namespace_));
        self::assertTrue(self::enablement($this->channels, [], [$pair])->publishes(new FindingChannel('health.cohesion'), SymbolLevel::Class_));
    }

    /**
     * `--only-rule health.complexity:class` has to keep its producer running:
     * a producer filtered out never emits the level that was asked for.
     */
    #[Test]
    public function itKeepsAProducerRunningWhenALevelPairInOnlySelectorsTargetsItsChannel(): void
    {
        $pair = 'health.complexity' . ChannelLevelSelector::LEVEL_SEPARATOR . SymbolLevel::Class_->value;

        self::assertTrue(self::enablement($this->channels, [$pair], [])->runs('computed.health'));
        self::assertTrue(self::enablement($this->channels, [$pair], [])->publishes(new FindingChannel('health.complexity'), SymbolLevel::Class_));
        self::assertFalse(self::enablement($this->channels, [$pair], [])->publishes(new FindingChannel('health.complexity'), SymbolLevel::Callable));
    }

    /**
     * A single-level channel disabled at that level leaves its producer nothing
     * to report, so the producer stops instead of running and having its whole
     * output filtered — which is what the documented skip of the two expensive
     * detection phases hangs on.
     */
    #[Test]
    public function itStopsAProducerWhoseEveryDeclaredLevelIsDisabled(): void
    {
        $selector = self::selectorWithLevels(
            ['duplication.clone' => ['duplication.clone']],
            ['duplication.clone' => [SymbolLevel::Project]],
        );

        self::assertFalse(self::enablement($selector, [], ['duplication.clone' . ChannelLevelSelector::LEVEL_SEPARATOR . SymbolLevel::Project->value])->runs('duplication.clone'));
    }

    /**
     * The stop condition is quantified over the producer: one level of a
     * two-level channel is not the whole channel, and the union of both levels
     * is.
     */
    #[Test]
    public function itStopsAProducerOnlyWhenTheSelectorsTogetherCoverEveryLevel(): void
    {
        $selector = self::selectorWithLevels(
            ['coupling.cbo' => ['coupling.cbo']],
            ['coupling.cbo' => [SymbolLevel::Class_, SymbolLevel::Namespace_]],
        );
        $class = 'coupling.cbo' . ChannelLevelSelector::LEVEL_SEPARATOR . SymbolLevel::Class_->value;
        $namespace = 'coupling.cbo' . ChannelLevelSelector::LEVEL_SEPARATOR . SymbolLevel::Namespace_->value;

        self::assertTrue(self::enablement($selector, [], [$class])->runs('coupling.cbo'));
        self::assertFalse(self::enablement($selector, [], [$class, $namespace])->runs('coupling.cbo'));
    }

    /**
     * One measurement of the computed-metric family is one channel of a
     * producer that emits all of them, so silencing it silences nothing else.
     */
    #[Test]
    public function itKeepsAProducerRunningWhenOnlyOneOfItsChannelsIsFullyCovered(): void
    {
        $selector = self::selectorWithLevels(
            ['computed.health' => ['health.complexity', 'health.cohesion']],
            ['health.complexity' => [SymbolLevel::Class_], 'health.cohesion' => [SymbolLevel::Class_]],
        );

        self::assertTrue(self::enablement($selector, [], ['health.complexity' . ChannelLevelSelector::LEVEL_SEPARATOR . SymbolLevel::Class_->value])->runs('computed.health'));
    }

    /**
     * A pair must address a level in the resolved snapshot: absence is not
     * evidence that disabling that pair can silence the channel.
     */
    #[Test]
    public function itRefusesALevelSelectorForAChannelThatDeclaresNoLevel(): void
    {
        $selector = self::selectorWithLevels(
            ['computed.health' => ['health.complexity']],
            ['health.complexity' => []],
        );

        try {
            self::enablement($selector, [], ['health.complexity' . ChannelLevelSelector::LEVEL_SEPARATOR . SymbolLevel::Class_->value]);
            self::fail('A level pair with no declared level must be refused.');
        } catch (ConfigurationRefusal $exception) {
            self::assertSame('Rule selector "health.complexity:class" addresses "health.complexity", and it does not report at level "class" — it declares no level at all. The pair can never match anything.', $exception->getMessage());
        }
    }

    #[Test]
    public function itRefusesAnOnlyFilterWhoseOnlyProducerIsDisabled(): void
    {
        try {
            self::enablement($this->channels, ['computed.health'], ['computed.health']);
            self::fail('An only filter cannot enable its disabled producer.');
        } catch (ConfigurationRefusal $exception) {
            self::assertSame('Rule selection is empty: "computed.health": disabled_rules[0]: computed.health (configuration file "/project/qmx.yaml"); only_rules / --only-rule narrows and does not enable.', $exception->getMessage());
        }
    }

    #[Test]
    public function itKeepsAProducerActiveWhenOnlyOneOfItsChannelsIsDisabled(): void
    {
        self::assertTrue(self::enablement($this->channels, [], ['health.complexity'])->runs('computed.health'));
        self::assertFalse(self::enablement($this->channels, [], ['health.complexity'])->publishes(new FindingChannel('health.complexity'), SymbolLevel::Class_));
        self::assertTrue(self::enablement($this->channels, [], ['health.complexity'])->publishes(new FindingChannel('health.cohesion'), SymbolLevel::Class_));
    }

    #[Test]
    public function itRecognizesRegisteredChannelSelectorsWithoutTreatingThemAsRuleOptionNames(): void
    {
        self::assertTrue($this->channels->hasChannel('health.complexity'));
        self::assertTrue($this->channels->hasChannel('architecture.coverage-gap'));
        self::assertTrue($this->channels->hasChannel('health.complexity'));
        self::assertFalse($this->channels->hasRule('health.complexity'));
    }

    #[Test]
    public function itValidatesAgainstExplicitSnapshotsAndClearsTheCommittedRun(): void
    {
        $snapshotA = self::registry(['health.complexity']);
        $snapshotB = self::registry(['health.cohesion']);
        self::assertTrue($snapshotA->hasRule('computed.health'));
        self::assertTrue($snapshotA->hasChannel('health.complexity'));
        self::assertTrue($snapshotA->hasChannel('health.complexity'));
        self::assertFalse($snapshotA->hasChannel('health.unknown'));

        $metadata = [new RuleMetadata('computed.health', CodeDuplicationOptions::class, '', [], false)];
        $registry = new RuleOptionsRegistry();
        $registry->replace(ResolvedOptionsFixture::ready(FindingConfiguration::none(), $metadata, channels: $snapshotA));
        self::assertTrue($registry->channelUniverse()->hasChannel('health.complexity'));
        $registry->replace(ResolvedOptionsFixture::ready(FindingConfiguration::none(), $metadata, channels: $snapshotB));
        self::assertFalse($registry->channelUniverse()->hasChannel('health.complexity'));
        self::assertTrue($registry->channelUniverse()->hasChannel('health.cohesion'));
        $registry->resetRuntimeState();
        self::assertNull($registry->enablement());
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Rule channels are unavailable before analysis preflight.');
        $registry->channelUniverse();
    }

    /**
     * @param array<string, list<string>> $channelsByProducer
     * @param array<string, list<SymbolLevel>> $levelsByChannel the levels each
     *                                                          channel declares; a channel absent from the map
     *                                                          declares none
     */
    private static function selectorWithLevels(array $channelsByProducer, array $levelsByChannel): ChannelUniverse
    {
        $declarations = [];
        foreach ($levelsByChannel as $channel => $levels) {
            if ($levels !== []) {
                $declarations[$channel] = ChannelDeclaration::occurrence(...$levels);
            }
        }
        return new ChannelUniverse($declarations, $channelsByProducer, array_fill_keys(array_keys($channelsByProducer), false), new ResolvedComputedMetricDefinitions([]));
    }

    /** @param list<string> $channelKeys */
    private static function registry(array $channelKeys): ChannelUniverse
    {
        return self::selectorWithLevels(['computed.health' => $channelKeys], array_fill_keys($channelKeys, [SymbolLevel::Class_]));
    }

    /** @param list<string> $only
     * @param list<string> $disabled
     */
    private static function enablement(ChannelUniverse $channels, array $only = [], array $disabled = []): RuleEnablement
    {
        $metadata = array_map(static fn(string $producer): RuleMetadata => new RuleMetadata($producer, CodeDuplicationOptions::class, '', [], false), $channels->ruleNames());
        return ResolvedOptionsFixture::ready(FindingConfiguration::none(), $metadata, channels: $channels, only: $only, disabled: $disabled)->enablement
            ?? throw new LogicException('The fixture must carry final enablement.');
    }
}
