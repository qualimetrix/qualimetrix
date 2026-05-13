<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Architecture\Layer;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Architecture\Layer\LayerDefinition;
use Qualimetrix\Core\Architecture\Layer\LayerMatch;
use Qualimetrix\Core\Architecture\Layer\LayerRegistry;
use Qualimetrix\Core\Symbol\SymbolPath;
use ReflectionProperty;

#[CoversClass(LayerRegistry::class)]
#[CoversClass(LayerMatch::class)]
final class LayerRegistryTest extends TestCase
{
    #[Test]
    public function resolveLayer_singleLayerSingleMatch_returnsName(): void
    {
        $registry = new LayerRegistry([
            new LayerDefinition('controller', ['App\\Controller']),
        ]);

        self::assertSame(
            'controller',
            $registry->resolveLayer(SymbolPath::forClass('App\\Controller', 'UserController')),
        );
    }

    #[Test]
    public function resolveLayer_singleLayerNoMatch_returnsNull(): void
    {
        $registry = new LayerRegistry([
            new LayerDefinition('controller', ['App\\Controller']),
        ]);

        self::assertNull(
            $registry->resolveLayer(SymbolPath::forClass('App\\Service', 'UserService')),
        );
    }

    #[Test]
    public function resolveLayer_emptyRegistry_returnsNull(): void
    {
        $registry = new LayerRegistry([]);

        self::assertNull(
            $registry->resolveLayer(SymbolPath::forClass('App\\Service', 'UserService')),
        );
    }

    #[Test]
    public function resolveLayer_declarationOrderFirstMatchWins_narrowBeforeBroad(): void
    {
        // The narrower layer is declared first → it wins for classes inside its scope.
        $registry = new LayerRegistry([
            new LayerDefinition('service', ['App\\Service\\**']),
            new LayerDefinition('any', ['App\\**']),
        ]);

        self::assertSame(
            'service',
            $registry->resolveLayer(SymbolPath::forClass('App\\Service', 'UserService')),
        );
    }

    #[Test]
    public function resolveLayer_declarationOrderFirstMatchWins_broadBeforeNarrow(): void
    {
        // Reversed order: the broad layer wins, shadowing the narrower one.
        $registry = new LayerRegistry([
            new LayerDefinition('any', ['App\\**']),
            new LayerDefinition('service', ['App\\Service\\**']),
        ]);

        self::assertSame(
            'any',
            $registry->resolveLayer(SymbolPath::forClass('App\\Service', 'UserService')),
        );
    }

    #[Test]
    public function resolveLayer_catchAllAsFinalLayerCapturesResidual(): void
    {
        $registry = new LayerRegistry([
            new LayerDefinition('service', ['App\\Service\\**']),
            new LayerDefinition('catchall', ['**']),
        ]);

        // App\Service goes to service.
        self::assertSame('service', $registry->resolveLayer(SymbolPath::forClass('App\\Service', 'Foo')));
        // Everything else goes to catchall.
        self::assertSame('catchall', $registry->resolveLayer(SymbolPath::forClass('Other\\Bar', 'Baz')));
    }

    #[Test]
    public function resolveLayer_isCachedAcrossInvocations(): void
    {
        $registry = new LayerRegistry([
            new LayerDefinition('service', ['App\\Service']),
            new LayerDefinition('controller', ['App\\Controller']),
        ]);

        $symbol = SymbolPath::forClass('App\\Service', 'UserService');

        $first = $registry->resolveLayer($symbol);
        $second = $registry->resolveLayer($symbol);
        $third = $registry->resolveLayer($symbol);

        self::assertSame('service', $first);
        self::assertSame($first, $second);
        self::assertSame($first, $third);
    }

    #[Test]
    public function resolveLayer_populatesSharedMatchCache(): void
    {
        $registry = new LayerRegistry([
            new LayerDefinition('service', ['App\\Service']),
        ]);

        $reflection = new ReflectionProperty(LayerRegistry::class, 'matchCache');
        self::assertSame([], $reflection->getValue($registry), 'Cache starts empty.');

        $symbol = SymbolPath::forClass('App\\Service', 'UserService');
        $registry->resolveLayer($symbol);

        $cache = $reflection->getValue($registry);
        self::assertIsArray($cache);
        self::assertArrayHasKey($symbol->toCanonical(), $cache);

        $matches = $cache[$symbol->toCanonical()];
        self::assertCount(1, $matches);
        self::assertSame('service', $matches[0]->layerName);

        $registry->resolveLayer(SymbolPath::forClass('Other\\Place', 'Foo'));
        $cache = $reflection->getValue($registry);
        self::assertCount(2, $cache, 'Negative result (empty match list) is cached separately.');
    }

    #[Test]
    public function resolveLayer_cacheReturnsSameNegativeResult(): void
    {
        $registry = new LayerRegistry([
            new LayerDefinition('service', ['App\\Service']),
        ]);

        $symbol = SymbolPath::forClass('Other\\Place', 'Foo');

        // Both lookups for out-of-layer class should consistently return null.
        self::assertNull($registry->resolveLayer($symbol));
        self::assertNull($registry->resolveLayer($symbol));
    }

    #[Test]
    public function resolveLayer_emptyNamespaceAndEmptyTypeReturnsNull(): void
    {
        $registry = new LayerRegistry([
            new LayerDefinition('global', ['Foo']),
        ]);

        // A SymbolPath with both namespace and type empty (a namespace-only path with empty namespace).
        $symbol = SymbolPath::forNamespace('');

        self::assertNull($registry->resolveLayer($symbol));
    }

    #[Test]
    public function resolveLayer_emptyNamespaceWithType_matchesByBareType(): void
    {
        $registry = new LayerRegistry([
            new LayerDefinition('global', ['GlobalClass']),
        ]);

        $symbol = SymbolPath::forClass('', 'GlobalClass');

        self::assertSame('global', $registry->resolveLayer($symbol));
    }

    #[Test]
    public function resolveLayer_namespaceOnlyPath_isResolvable(): void
    {
        $registry = new LayerRegistry([
            new LayerDefinition('service', ['App\\Service']),
        ]);

        // Namespace-level SymbolPath (no type). FQN = the namespace itself.
        $symbol = SymbolPath::forNamespace('App\\Service');

        self::assertSame('service', $registry->resolveLayer($symbol));
    }

    #[Test]
    public function resolveAll_returnsEveryMatchingLayerInDeclarationOrder(): void
    {
        $registry = new LayerRegistry([
            new LayerDefinition('any', ['App\\**']),
            new LayerDefinition('service', ['App\\Service\\**']),
            new LayerDefinition('special', ['App\\Service\\Special\\**']),
        ]);

        $matches = $registry->resolveAll(SymbolPath::forClass('App\\Service\\Special', 'Foo'));

        self::assertCount(3, $matches);
        self::assertSame('any', $matches[0]->layerName);
        self::assertSame('App\\**', $matches[0]->matchingPattern);
        self::assertSame('service', $matches[1]->layerName);
        self::assertSame('App\\Service\\**', $matches[1]->matchingPattern);
        self::assertSame('special', $matches[2]->layerName);
        self::assertSame('App\\Service\\Special\\**', $matches[2]->matchingPattern);
    }

    #[Test]
    public function resolveAll_returnsEmptyListWhenNoLayerMatches(): void
    {
        $registry = new LayerRegistry([
            new LayerDefinition('service', ['App\\Service']),
        ]);

        self::assertSame([], $registry->resolveAll(SymbolPath::forClass('Other\\Place', 'Foo')));
    }

    #[Test]
    public function resolveAll_isCached(): void
    {
        $registry = new LayerRegistry([
            new LayerDefinition('any', ['App\\**']),
            new LayerDefinition('service', ['App\\Service\\**']),
        ]);

        $symbol = SymbolPath::forClass('App\\Service', 'Foo');

        $first = $registry->resolveAll($symbol);
        $second = $registry->resolveAll($symbol);

        self::assertSame($first, $second, 'resolveAll() result must be cached and returned identically on repeat lookups.');
    }

    #[Test]
    public function resolveAll_andResolveLayer_shareTheSameCache(): void
    {
        $registry = new LayerRegistry([
            new LayerDefinition('any', ['App\\**']),
            new LayerDefinition('service', ['App\\Service\\**']),
        ]);

        $reflection = new ReflectionProperty(LayerRegistry::class, 'matchCache');
        self::assertSame([], $reflection->getValue($registry));

        $symbol = SymbolPath::forClass('App\\Service', 'Foo');

        // Populate the cache via resolveAll, then verify resolveLayer is satisfied
        // from the same cache without re-walking the pattern list.
        $registry->resolveAll($symbol);

        $cache = $reflection->getValue($registry);
        self::assertIsArray($cache);
        self::assertCount(1, $cache, 'resolveAll populates a single cache entry.');
        self::assertArrayHasKey($symbol->toCanonical(), $cache);

        $assigned = $registry->resolveLayer($symbol);
        self::assertSame('any', $assigned, 'resolveLayer reads the first entry off the shared cache.');

        $cache = $reflection->getValue($registry);
        self::assertCount(1, $cache, 'resolveLayer must not populate a separate cache entry — both methods share one cache.');

        // The reverse direction also shares the cache.
        $other = SymbolPath::forClass('App\\Other', 'Bar');
        $registry->resolveLayer($other);
        self::assertCount(2, $reflection->getValue($registry));

        $registry->resolveAll($other);
        self::assertCount(2, $reflection->getValue($registry), 'resolveAll after resolveLayer must hit the existing cache entry.');
    }

    #[Test]
    public function construct_throwsOnDuplicateLayerNames(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Duplicate layer name "service"/');

        new LayerRegistry([
            new LayerDefinition('service', ['App\\Service']),
            new LayerDefinition('service', ['App\\OtherService']),
        ]);
    }

    #[Test]
    public function isEmpty_trueForEmptyList(): void
    {
        self::assertTrue((new LayerRegistry([]))->isEmpty());
    }

    #[Test]
    public function isEmpty_falseWhenLayersPresent(): void
    {
        $registry = new LayerRegistry([
            new LayerDefinition('service', ['App\\Service']),
        ]);

        self::assertFalse($registry->isEmpty());
    }

    #[Test]
    public function layerNames_preservesDeclarationOrder(): void
    {
        $registry = new LayerRegistry([
            new LayerDefinition('zebra', ['App\\Zebra']),
            new LayerDefinition('alpha', ['App\\Alpha']),
            new LayerDefinition('beta', ['App\\Beta']),
        ]);

        // NOT alphabetically sorted — declaration order is preserved.
        self::assertSame(['zebra', 'alpha', 'beta'], $registry->layerNames());
    }

    #[Test]
    public function layerNames_emptyForEmptyRegistry(): void
    {
        self::assertSame([], (new LayerRegistry([]))->layerNames());
    }

    #[Test]
    public function definitions_returnsConfiguredListInOrder(): void
    {
        $definitions = [
            new LayerDefinition('service', ['App\\Service']),
            new LayerDefinition('controller', ['App\\Controller']),
        ];

        $registry = new LayerRegistry($definitions);

        self::assertSame($definitions, $registry->definitions());
    }
}
