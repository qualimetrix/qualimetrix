<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit\Contract\Selection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ComputedMetricDefinition;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Contract\Definition\ResolvedComputedMetricDefinitions;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\Selection\RuleNameJudge;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Infrastructure\Rule\ChannelUniverse;

#[CoversClass(RuleNameJudge::class)]
final class RuleNameJudgeTest extends TestCase
{
    #[Test]
    public function itDistinguishesTheComputedProducerFromItsDeclaredDescendants(): void
    {
        $empty = self::universe();
        $judge = new RuleNameJudge($empty->ruleNames());
        self::assertNull($judge->judge('computed'));
        self::assertNull($judge->judge('health.cohesion'));
        self::assertNull($judge->selector('computed', $empty));
        self::assertSame(
            'Rule selector "computed.*" does not match any registered producer or channel. Write "computed" to select the producer; "computed.*" selects only declared descendant channels.',
            $judge->selector('computed.*', $empty)?->summary,
        );
        $configured = self::universe(true);
        self::assertNull($judge->selector('computed.*', $configured));
        self::assertNull($judge->selector('computed.delivery-risk:namespace', $configured));
        self::assertNotNull($judge->judge('computed.delivery-risk'));
    }

    #[Test]
    public function itRefusesBareGroupsAndDifferentlyNamedProducerLevelAliases(): void
    {
        $channels = self::universe();
        $judge = new RuleNameJudge($channels->ruleNames());
        self::assertSame(
            'Rule selector "coupling" does not match any registered producer or channel. Write "coupling.*" to select its descendants.',
            $judge->selector('coupling', $channels)?->summary,
        );
        self::assertNull($judge->selector('coupling.ranking', $channels));
        self::assertNotNull($judge->selector('coupling.ranking:class', $channels));
        self::assertNull($judge->selector('coupling.class-rank:class', $channels));
        self::assertSame(
            'Rule selector "design.lcom" does not match any registered producer or channel. Write "cohesion.lcom" instead.',
            $judge->selector('design.lcom', $channels)?->summary,
        );
    }

    #[Test]
    public function itRequiresANamespaceWitnessInTheInvocationDefinitions(): void
    {
        $channels = self::universe(true);
        $judge = new RuleNameJudge($channels->ruleNames());
        self::assertNull($judge->namespaceChannel('computed', 'computed.delivery-risk', $channels));
        self::assertNull($judge->namespaceChannel('computed', 'computed.*:namespace', $channels));
        self::assertNotNull($judge->namespaceChannel('health.cohesion', 'computed.delivery-risk', $channels));
        self::assertNotNull($judge->namespaceChannel('coupling.ranking', 'coupling.*', $channels));
        self::assertNotNull($judge->namespaceChannel('health.cohesion', 'health.cohesion', self::universe()));
    }

    private static function universe(bool $configured = false): ChannelUniverseInterface
    {
        return new ChannelUniverse(
            ['coupling.class-rank' => ChannelDeclaration::magnitude(WorseDirection::Higher, SymbolLevel::Class_)],
            ['coupling.ranking' => ['coupling.class-rank'], 'computed' => [], 'health.cohesion' => []],
            ['coupling.ranking' => true, 'computed' => false, 'health.cohesion' => false],
            new ResolvedComputedMetricDefinitions($configured ? [new ComputedMetricDefinition('computed.delivery-risk', ['namespace' => '1'], 'Delivery risk', [SymbolLevel::Namespace_])] : []),
        );
    }
}
