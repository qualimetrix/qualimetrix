<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Unit\Processing;

use Generator;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Policy\Architecture\ArchitecturePolicy;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureConfiguration;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureFactoryResult;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\CoverageMode;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerAssignment;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ClassContext\ClassContextFactory;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerDefinition;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerPolicy;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerRegistry;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MembershipSpec;
use Qualimetrix\Analysis\Policy\Architecture\Layer\TemplateLayerDefinition;
use Qualimetrix\Analysis\Policy\Architecture\LayerAssignment\LayerAssignmentProjection;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Support\AdjacencyGraphBuilder;
use ReflectionClass;

/** Pins the replace, prepare, inspect and reset lifecycle of ArchitecturePolicy. */
#[CoversClass(ArchitecturePolicy::class)]
#[CoversClass(LayerAssignmentProjection::class)]
final class ArchitectureProcessorTest extends TestCase
{
    private ArchitecturePolicy $processor;

    protected function setUp(): void
    {
        $this->processor = new ArchitecturePolicy();
    }

    #[Test]
    public function itThrowsWhenInspectIsCalledBeforeReplace(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/prepare.*replace/');

        $this->processor->inspect(self::emptyGraph(), [], SymbolPath::forClass('App', 'Foo'), false);
    }

    #[Test]
    public function itThrowsWhenPrepareIsCalledBeforeReplace(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/prepare.*replace/');

        $this->processor->prepare(self::emptyGraph(), []);
    }

    #[Test]
    public function itIsIdempotentWhenResetIsCalledRepeatedly(): void
    {
        $this->processor->reset();
        $this->processor->reset();

        self::assertNull($this->processor->getPreparedConfiguration());
    }

    #[Test]
    public function itReturnsMatchesAfterResetReplaceAndInspect(): void
    {
        $this->processor->reset();
        $this->replace(self::configurationWithOneStaticLayer());

        $assignment = $this->processor->inspect(
            self::emptyGraph(),
            [SymbolPath::forClass('App\\Controller', 'UserController')],
            SymbolPath::forClass('App\\Controller', 'UserController'),
            false,
        );

        self::assertCount(1, $assignment->matches);
        self::assertSame('controller', $assignment->matches[0]->layerName);
    }

    #[Test]
    public function itRebuildsPreparedStateWhenInspectFollowsReset(): void
    {
        $this->replace(self::configurationWithOneStaticLayer());
        $subject = SymbolPath::forClass('App\\Controller', 'X');
        $this->processor->inspect(self::emptyGraph(), [$subject], $subject, false);

        $this->processor->reset();
        self::assertNull($this->processor->getPreparedConfiguration());

        $assignment = $this->processor->inspect(self::emptyGraph(), [$subject], $subject, false);
        self::assertSame('controller', $assignment->matches[0]->layerName);
    }

    #[Test]
    public function itClearsThePreparedStateOnReplace(): void
    {
        $this->replace(self::configurationWithOneStaticLayer());
        $this->processor->prepare(self::emptyGraph(), []);
        self::assertNotNull($this->processor->getPreparedConfiguration());

        $this->replace(self::emptyConfiguration());

        self::assertNull($this->processor->getPreparedConfiguration());
    }

    #[Test]
    public function itUsesTheLastPolicyWhenReplaceIsCalledTwice(): void
    {
        $this->replace(self::configurationWithOneStaticLayer());
        $this->replace(self::emptyConfiguration());
        $subject = SymbolPath::forClass('App\\Controller', 'UserController');

        $assignment = $this->processor->inspect(self::emptyGraph(), [$subject], $subject, false);

        self::assertSame([], $assignment->matches);
    }

    #[Test]
    public function itReturnsNoPreparedConfigurationBeforeReplace(): void
    {
        self::assertNull($this->processor->getPreparedConfiguration());
    }

    #[Test]
    public function itReturnsNoPreparedConfigurationAfterReplaceBeforePrepare(): void
    {
        $this->replace(self::emptyConfiguration());

        self::assertNull($this->processor->getPreparedConfiguration());
    }

    #[Test]
    public function itReturnsThePreparedConfigurationAfterPrepare(): void
    {
        $config = self::configurationWithOneStaticLayer();
        $this->replace($config);
        $this->processor->prepare(self::emptyGraph(), []);

        self::assertSame(
            $config,
            $this->processor->getPreparedConfiguration(),
            'Same instance because no templates means no withExpansion() rebuild.',
        );
    }

    #[Test]
    public function itRunsTemplateExpansionAndReturnsAnExpandedConfigurationInstance(): void
    {
        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(patterns: ['App\\Module\\{module}\\Domain\\**']),
        );
        $config = new ArchitectureConfiguration(
            registry: new LayerRegistry([], new ClassContextFactory()),
            policy: new LayerPolicy([]),
            coverage: CoverageMode::Ignore,
            entries: [$template],
            maxExpandedLayers: 500,
        );
        $classes = [SymbolPath::forClass('App\\Module\\Order\\Domain', 'Customer')];

        $this->replace($config);
        $this->processor->prepare(self::emptyGraph(), $classes);

        $prepared = $this->processor->getPreparedConfiguration();
        self::assertNotNull($prepared);
        self::assertSame(['domain-Order'], $prepared->registry()->layerNames());
        self::assertNotSame($config, $prepared);
    }

    #[Test]
    public function itPreservesConfiguredPolicyAcrossPreparationReset(): void
    {
        $this->replace(self::configurationWithOneStaticLayer());
        $this->processor->prepare(self::emptyGraph(), []);
        $this->processor->reset();

        self::assertNull($this->processor->getPreparedConfiguration());
        $this->processor->prepare(self::emptyGraph(), []);
        self::assertNotNull($this->processor->getPreparedConfiguration());
    }

    #[Test]
    public function itResetsWithoutTraversingTheClassUniverse(): void
    {
        $this->replace(self::configurationWithOneStaticLayer());
        $classUniverse = (static function (): Generator {
            yield throw new LogicException('Reset must not traverse the class universe.');
        })();

        $this->processor->reset();

        self::assertNull($this->processor->getPreparedConfiguration());
        unset($classUniverse);
    }

    #[Test]
    public function itRebuildsObservedSpellingForEveryInspectCall(): void
    {
        $this->replace(new ArchitectureConfiguration(
            new LayerRegistry([new LayerDefinition('all', new MembershipSpec(patterns: ['**']))], new ClassContextFactory()),
            new LayerPolicy([]),
            CoverageMode::Ignore,
        ));
        $first = SymbolPath::forClass('App', 'First');
        $second = SymbolPath::forClass('App', 'Second');

        $firstAssignment = $this->processor->inspect(
            self::emptyGraph(),
            [$first],
            SymbolPath::forClass('app', 'first'),
            false,
        );
        $secondAssignment = $this->processor->inspect(
            self::emptyGraph(),
            [$second],
            SymbolPath::forClass('app', 'second'),
            false,
        );
        $staleAssignment = $this->processor->inspect(
            self::emptyGraph(),
            [$second],
            SymbolPath::forClass('app', 'first'),
            false,
        );

        self::assertSame('App\\First', $firstAssignment->declaredSpelling);
        self::assertSame('App\\Second', $secondAssignment->declaredSpelling);
        self::assertNull($staleAssignment->declaredSpelling);
    }

    #[Test]
    public function itRequiresEveryLayerAssignmentFactAtConstruction(): void
    {
        $constructor = (new ReflectionClass(LayerAssignment::class))->getConstructor();
        self::assertNotNull($constructor);
        self::assertCount(10, $constructor->getParameters());
        foreach ($constructor->getParameters() as $parameter) {
            self::assertFalse($parameter->isDefaultValueAvailable(), $parameter->getName());
        }
    }

    #[Test]
    public function itImplementsTheLayerPolicyPreparationContract(): void
    {
        $reflection = new ReflectionClass(ArchitecturePolicy::class);
        self::assertTrue($reflection->implementsInterface(LayerPolicyPreparationInterface::class));
    }

    private function replace(ArchitectureConfiguration $configuration): void
    {
        $this->processor->replace(new ArchitectureFactoryResult($configuration));
    }

    private static function configurationWithOneStaticLayer(): ArchitectureConfiguration
    {
        $controller = new LayerDefinition(
            'controller',
            new MembershipSpec(patterns: ['App\\Controller\\**']),
        );

        return new ArchitectureConfiguration(
            registry: new LayerRegistry([$controller], new ClassContextFactory()),
            policy: new LayerPolicy([]),
            coverage: CoverageMode::Ignore,
        );
    }

    private static function emptyConfiguration(): ArchitectureConfiguration
    {
        return new ArchitectureConfiguration(
            new LayerRegistry([], new ClassContextFactory()),
            new LayerPolicy([]),
            CoverageMode::Ignore,
        );
    }

    private static function emptyGraph(): DependencyGraphInterface
    {
        return AdjacencyGraphBuilder::empty();
    }
}
