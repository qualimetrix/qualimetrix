<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Infrastructure\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefinition;
use Qualimetrix\Core\ComputedMetric\ComputedMetricDefinitionHolder;
use Qualimetrix\Core\Observation\WorseDirection;
use Qualimetrix\Core\Symbol\SymbolType;
use Qualimetrix\Core\Violation\ChannelDeclaration;
use Qualimetrix\Core\Violation\ChannelShape;
use Qualimetrix\Core\Violation\ViolationChannel;
use Qualimetrix\Infrastructure\Rule\ChannelDeclarationRegistry;
use Qualimetrix\Rules\ComputedMetric\ComputedMetricRule;

#[CoversClass(ChannelDeclarationRegistry::class)]
final class ChannelDeclarationRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        ComputedMetricDefinitionHolder::reset();

        parent::tearDown();
    }

    #[Test]
    public function itReturnsTheDeclarationForAStaticallyDeclaredChannel(): void
    {
        $channel = new ViolationChannel('complexity.cyclomatic', 'complexity.cyclomatic.callable');
        $declaration = ChannelDeclaration::magnitude(WorseDirection::Higher);

        $registry = new ChannelDeclarationRegistry([
            $channel->toKey() => $declaration,
        ], ComputedMetricRule::NAME);

        self::assertSame($declaration, $registry->declarationFor($channel));
    }

    #[Test]
    public function itReturnsNullForAnUndeclaredChannel(): void
    {
        $registry = new ChannelDeclarationRegistry([], ComputedMetricRule::NAME);

        $result = $registry->declarationFor(new ViolationChannel('code-smell.eval', 'code-smell.eval'));

        self::assertNull($result, 'An undeclared channel is not baselineable — that is observable, not an exception.');
    }

    #[Test]
    public function itExposesExactlyTheStaticDeclarationsItWasGiven(): void
    {
        $channel = new ViolationChannel('maintainability.index', 'maintainability.index');
        $declaration = ChannelDeclaration::magnitude(WorseDirection::Lower);

        $registry = new ChannelDeclarationRegistry([
            $channel->toKey() => $declaration,
        ], ComputedMetricRule::NAME);

        self::assertSame([$channel->toKey() => $declaration], $registry->staticDeclarations());
    }

    #[Test]
    public function itResolvesABuiltInHealthChannelAtRunTimeFromTheDefinitionsInvertedFlag(): void
    {
        ComputedMetricDefinitionHolder::setDefinitions([
            new ComputedMetricDefinition(
                name: 'health.complexity',
                formulas: ['class' => 'ccn__avg'],
                description: 'Complexity health score',
                levels: [SymbolType::Class_],
                inverted: true,
            ),
        ]);

        $registry = new ChannelDeclarationRegistry([], ComputedMetricRule::NAME);
        $channel = new ViolationChannel(ComputedMetricRule::NAME, 'health.complexity');

        $declaration = $registry->declarationFor($channel);

        self::assertNotNull($declaration);
        self::assertSame(ChannelShape::Magnitude, $declaration->shape);
        self::assertSame(WorseDirection::Lower, $declaration->direction, 'inverted=true means higher is better, i.e. lower is worse');
    }

    #[Test]
    public function itResolvesAUserDefinedComputedMetricAtRunTimeAsHigherIsWorseByDefault(): void
    {
        ComputedMetricDefinitionHolder::setDefinitions([
            new ComputedMetricDefinition(
                name: 'computed.risk_score',
                formulas: ['namespace' => 'ccn__avg * 2'],
                description: 'Custom risk score',
                levels: [SymbolType::Namespace_],
                inverted: false,
            ),
        ]);

        $registry = new ChannelDeclarationRegistry([], ComputedMetricRule::NAME);
        $channel = new ViolationChannel(ComputedMetricRule::NAME, 'computed.risk_score');

        $declaration = $registry->declarationFor($channel);

        self::assertNotNull($declaration);
        self::assertSame(ChannelShape::Magnitude, $declaration->shape);
        self::assertSame(WorseDirection::Higher, $declaration->direction);
    }

    #[Test]
    public function itReturnsNullForAComputedMetricChannelWithNoMatchingDefinition(): void
    {
        ComputedMetricDefinitionHolder::setDefinitions([
            new ComputedMetricDefinition(
                name: 'health.overall',
                formulas: ['project' => 'health__complexity'],
                description: 'Overall health',
                levels: [SymbolType::Project],
                inverted: true,
            ),
        ]);

        $registry = new ChannelDeclarationRegistry([], ComputedMetricRule::NAME);

        // A stale entry for a definition the user has since removed from config.
        $channel = new ViolationChannel(ComputedMetricRule::NAME, 'computed.removed_metric');

        self::assertNull($registry->declarationFor($channel));
    }
}
