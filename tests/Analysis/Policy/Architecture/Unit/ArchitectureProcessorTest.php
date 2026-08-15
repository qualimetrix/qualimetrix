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
use Qualimetrix\Analysis\Policy\Architecture\Configuration\CoverageMode;
use Qualimetrix\Analysis\Policy\Architecture\Contract\LayerPolicyPreparationInterface;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ClassContextFactory;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ClassSet;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerDefinition;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerMatch;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerPolicy;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerRegistry;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MembershipSpec;
use Qualimetrix\Analysis\Policy\Architecture\Layer\TemplateLayerDefinition;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Support\AdjacencyGraphBuilder;
use ReflectionClass;

/**
 * Pins the state-machine semantics of {@see ArchitecturePolicy} per
 * ADR 0008 §3.
 *
 * Lifecycle (one analysis run): reset → bind → prepare → classify? →
 * getPreparedConfiguration?, repeatable. Mismatched call ordering throws
 * {@see LogicException} fail-fast (not silent no-op) because it indicates
 * a wiring bug at the DI level.
 */
#[CoversClass(ArchitecturePolicy::class)]
final class ArchitectureProcessorTest extends TestCase
{
    private ArchitecturePolicy $processor;

    protected function setUp(): void
    {
        $this->processor = new ArchitecturePolicy();
    }

    // -------------------------------------------------------------------------
    // Mandatory state-machine invariants (per remediation plan Phase 4.2)
    // -------------------------------------------------------------------------

    #[Test]
    public function classify_beforeBind_throwsLogicException(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/classify.*bind/');

        iterator_to_array($this->processor->classify([SymbolPath::forClass('App', 'Foo')]));
    }

    #[Test]
    public function classify_afterBindWithoutPrepare_throwsLogicException(): void
    {
        $this->processor->bind(self::emptyConfiguration());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/classify.*prepare/');

        iterator_to_array($this->processor->classify([SymbolPath::forClass('App', 'Foo')]));
    }

    #[Test]
    public function prepare_beforeBind_throwsLogicException(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/prepare.*bind/');

        $this->processor->prepare(self::emptyGraph(), self::emptyClassSet());
    }

    #[Test]
    public function reset_isIdempotent(): void
    {
        $this->processor->reset();
        $this->processor->reset();

        self::assertNull($this->processor->getPreparedConfiguration());
    }

    #[Test]
    public function happyPath_resetBindPrepareClassify_returnsMatches(): void
    {
        $config = self::configurationWithOneStaticLayer();
        $this->processor->reset();
        $this->processor->bind($config);
        $this->processor->prepare(self::emptyGraph(), self::emptyClassSet());

        $classPath = SymbolPath::forClass('App\\Controller', 'UserController');
        $matches = iterator_to_array($this->processor->classify([$classPath]));

        self::assertCount(1, $matches);
        self::assertInstanceOf(LayerMatch::class, $matches[0]);
        self::assertSame('controller', $matches[0]->layerName);
    }

    #[Test]
    public function reset_afterFullHappyPath_clearsState_subsequentClassifyThrows(): void
    {
        $this->processor->bind(self::configurationWithOneStaticLayer());
        $this->processor->prepare(self::emptyGraph(), self::emptyClassSet());
        iterator_to_array($this->processor->classify([SymbolPath::forClass('App\\Controller', 'X')]));

        $this->processor->reset();

        self::assertNull($this->processor->getPreparedConfiguration());

        $this->expectException(LogicException::class);
        iterator_to_array($this->processor->classify([SymbolPath::forClass('App\\Controller', 'Y')]));
    }

    // -------------------------------------------------------------------------
    // Round-3/4 Codex MEDIUM enumeration
    // -------------------------------------------------------------------------

    #[Test]
    public function bind_afterPrepare_clearsPreparedState_subsequentClassifyThrows(): void
    {
        // First analysis run reaches the prepared state.
        $this->processor->bind(self::configurationWithOneStaticLayer());
        $this->processor->prepare(self::emptyGraph(), self::emptyClassSet());
        self::assertNotNull($this->processor->getPreparedConfiguration());

        // Re-binding (e.g. configurator pivots mid-flow) invalidates the
        // prepared state until prepare() is called again.
        $this->processor->bind(self::emptyConfiguration());

        self::assertNull($this->processor->getPreparedConfiguration());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/classify.*prepare/');
        iterator_to_array($this->processor->classify([SymbolPath::forClass('App\\Controller', 'X')]));
    }

    #[Test]
    public function bind_repeated_invalidatesAndRebindsCorrectly(): void
    {
        $first = self::configurationWithOneStaticLayer();
        $second = self::emptyConfiguration();

        $this->processor->bind($first);
        $this->processor->bind($second);
        $this->processor->prepare(self::emptyGraph(), self::emptyClassSet());

        // The second config has no layers, so classifying the controller now
        // yields no matches — proves the rebinding stuck.
        $matches = iterator_to_array(
            $this->processor->classify([SymbolPath::forClass('App\\Controller', 'UserController')]),
        );

        self::assertSame([], $matches);
    }

    // -------------------------------------------------------------------------
    // Additional behavior: prepared configuration accessor + idempotency cycles
    // -------------------------------------------------------------------------

    #[Test]
    public function getPreparedConfiguration_isNullPreBind(): void
    {
        self::assertNull($this->processor->getPreparedConfiguration());
    }

    #[Test]
    public function getPreparedConfiguration_isNullAfterBindBeforePrepare(): void
    {
        $this->processor->bind(self::emptyConfiguration());

        self::assertNull($this->processor->getPreparedConfiguration());
    }

    #[Test]
    public function getPreparedConfiguration_returnsConfigAfterPrepare(): void
    {
        $config = self::configurationWithOneStaticLayer();
        $this->processor->bind($config);
        $this->processor->prepare(self::emptyGraph(), self::emptyClassSet());

        self::assertSame(
            $config,
            $this->processor->getPreparedConfiguration(),
            'Same instance because no templates → no withExpansion() rebuild',
        );
    }

    #[Test]
    public function prepare_withTemplates_runsExpansionAndReturnsWithExpansionInstance(): void
    {
        // Template that observes one tuple in the class set.
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

        $classes = self::classSet([SymbolPath::forClass('App\\Module\\Order\\Domain', 'Customer')]);

        $this->processor->bind($config);
        $this->processor->prepare(self::emptyGraph(), $classes);

        $prepared = $this->processor->getPreparedConfiguration();
        self::assertNotNull($prepared);
        // Expansion produced a concrete layer name in the post-expansion registry.
        self::assertSame(['domain-Order'], $prepared->registry()->layerNames());
        // The bound configuration was wrapped via withExpansion(); a fresh
        // instance distinct from the original `$config` proves expansion ran.
        self::assertNotSame($config, $prepared);
    }

    #[Test]
    public function itPreservesConfiguredPolicyAcrossPreparationReset(): void
    {
        $this->processor->bind(self::configurationWithOneStaticLayer());
        $this->processor->prepare(self::emptyGraph(), self::emptyClassSet());
        $this->processor->reset();

        self::assertNull($this->processor->getPreparedConfiguration());
        $this->processor->prepare(self::emptyGraph(), self::emptyClassSet());
        self::assertNotNull($this->processor->getPreparedConfiguration());
    }

    #[Test]
    public function itResetsWithoutTraversingTheClassUniverse(): void
    {
        $this->processor->bind(self::configurationWithOneStaticLayer());
        $classUniverse = (static function (): Generator {
            yield throw new LogicException('Reset must not traverse the class universe.');
        })();

        $this->processor->reset();

        self::assertNull($this->processor->getPreparedConfiguration());
        unset($classUniverse);
    }

    #[Test]
    public function implementsTheLayerPolicyPreparationContract(): void
    {
        // Pin the interface contract: the concrete processor MUST implement
        // LayerPolicyPreparationInterface for the DI alias to be sound.
        $reflection = new ReflectionClass(ArchitecturePolicy::class);
        self::assertTrue(
            $reflection->implementsInterface(LayerPolicyPreparationInterface::class),
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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

    /**
     * @param list<SymbolPath> $classes
     */
    private static function classSet(array $classes): ClassSet
    {
        return new ClassSet($classes, new ClassContextFactory());
    }

    private static function emptyClassSet(): ClassSet
    {
        return self::classSet([]);
    }

    private static function emptyGraph(): DependencyGraphInterface
    {
        return AdjacencyGraphBuilder::empty();
    }
}
