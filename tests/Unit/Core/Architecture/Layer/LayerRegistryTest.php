<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Architecture\Layer;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Architecture\Layer\LayerCollisionException;
use Qualimetrix\Core\Architecture\Layer\LayerDefinition;
use Qualimetrix\Core\Architecture\Layer\LayerRegistry;
use Qualimetrix\Core\Symbol\SymbolPath;
use ReflectionProperty;

#[CoversClass(LayerRegistry::class)]
#[CoversClass(LayerCollisionException::class)]
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
    public function resolveLayer_longestSpecificityWins(): void
    {
        $registry = new LayerRegistry([
            new LayerDefinition('any', ['App\\**']),
            new LayerDefinition('service', ['App\\Service']),
        ]);

        // `App\Service` (specificity 11) beats `App\**` (specificity 4).
        self::assertSame(
            'service',
            $registry->resolveLayer(SymbolPath::forClass('App\\Service', 'UserService')),
        );
    }

    #[Test]
    public function resolveLayer_longestSpecificityWinsAcrossPatterns(): void
    {
        $registry = new LayerRegistry([
            new LayerDefinition('catchall', ['App\\**']),
            new LayerDefinition('special', ['App\\Service\\Special\\**']),
        ]);

        self::assertSame(
            'special',
            $registry->resolveLayer(SymbolPath::forClass('App\\Service\\Special\\Module', 'Thing')),
        );
    }

    #[Test]
    public function resolveLayer_equalSpecificityCollision_throws(): void
    {
        $registry = new LayerRegistry([
            new LayerDefinition('alpha', ['App\\Shared']),
            new LayerDefinition('beta', ['App\\Shared']),
        ]);

        try {
            $registry->resolveLayer(SymbolPath::forClass('App\\Shared', 'Foo'));
            self::fail('Expected LayerCollisionException to be thrown.');
        } catch (LayerCollisionException $exception) {
            self::assertSame('App\\Shared\\Foo', $exception->getFqn());

            $matches = $exception->getMatches();
            self::assertCount(2, $matches);

            $layerNames = array_map(static fn(array $match): string => $match[0], $matches);
            $patterns = array_map(static fn(array $match): string => $match[1], $matches);

            self::assertContains('alpha', $layerNames);
            self::assertContains('beta', $layerNames);
            self::assertContains('App\\Shared', $patterns);

            // Both candidates surface in the message.
            self::assertStringContainsString('alpha', $exception->getMessage());
            self::assertStringContainsString('beta', $exception->getMessage());
        }
    }

    #[Test]
    public function resolveLayer_collisionAcrossMultiPatternLayers_reportsBestPatternPerLayer(): void
    {
        // Each layer has two patterns; only the more-specific patterns tie at specificity 11.
        $registry = new LayerRegistry([
            new LayerDefinition('alpha', ['App\\**', 'App\\Shared']),
            new LayerDefinition('beta', ['Other\\**', 'App\\Shared']),
        ]);

        try {
            $registry->resolveLayer(SymbolPath::forClass('App\\Shared', 'Foo'));
            self::fail('Expected LayerCollisionException.');
        } catch (LayerCollisionException $exception) {
            $patterns = array_map(static fn(array $match): string => $match[1], $exception->getMatches());

            // Both layers should report `App\Shared` as the colliding pattern.
            self::assertSame(['App\\Shared', 'App\\Shared'], $patterns);
        }
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
    public function resolveLayer_populatesInternalCache(): void
    {
        $registry = new LayerRegistry([
            new LayerDefinition('service', ['App\\Service']),
        ]);

        $reflection = new ReflectionProperty(LayerRegistry::class, 'resolutionCache');
        self::assertSame([], $reflection->getValue($registry), 'Cache starts empty.');

        $symbol = SymbolPath::forClass('App\\Service', 'UserService');
        $registry->resolveLayer($symbol);

        $cache = $reflection->getValue($registry);
        self::assertIsArray($cache);
        self::assertArrayHasKey($symbol->toCanonical(), $cache);
        self::assertSame('service', $cache[$symbol->toCanonical()]);

        $registry->resolveLayer(SymbolPath::forClass('Other\\Place', 'Foo'));
        $cache = $reflection->getValue($registry);
        self::assertCount(2, $cache, 'Negative result is cached separately.');
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
    public function layerNames_returnsSortedNames(): void
    {
        $registry = new LayerRegistry([
            new LayerDefinition('zebra', ['App\\Zebra']),
            new LayerDefinition('alpha', ['App\\Alpha']),
            new LayerDefinition('beta', ['App\\Beta']),
        ]);

        self::assertSame(['alpha', 'beta', 'zebra'], $registry->layerNames());
    }

    #[Test]
    public function layerNames_emptyForEmptyRegistry(): void
    {
        self::assertSame([], (new LayerRegistry([]))->layerNames());
    }

    #[Test]
    public function definitions_returnsConfiguredList(): void
    {
        $definitions = [
            new LayerDefinition('service', ['App\\Service']),
            new LayerDefinition('controller', ['App\\Controller']),
        ];

        $registry = new LayerRegistry($definitions);

        self::assertSame($definitions, $registry->definitions());
    }

    #[Test]
    public function resolveLayer_collisionIsCachedAndRethrown(): void
    {
        $registry = new LayerRegistry([
            new LayerDefinition('alpha', ['App\\Shared']),
            new LayerDefinition('beta', ['App\\Shared']),
        ]);

        $symbol = SymbolPath::forClass('App\\Shared', 'Foo');

        $firstException = null;
        try {
            $registry->resolveLayer($symbol);
            self::fail('Expected LayerCollisionException on first call.');
        } catch (LayerCollisionException $exception) {
            $firstException = $exception;
        }

        $secondException = null;
        try {
            $registry->resolveLayer($symbol);
            self::fail('Expected LayerCollisionException on second call.');
        } catch (LayerCollisionException $exception) {
            $secondException = $exception;
        }

        // Same exception instance is rethrown from the cache — proves the
        // findBestMatches scan was not repeated.
        self::assertSame(
            $firstException,
            $secondException,
            'Collision must be cached and the same exception instance re-thrown on repeat lookups.',
        );
    }

    #[Test]
    public function resolveLayer_collisionPopulatesCacheWithException(): void
    {
        $registry = new LayerRegistry([
            new LayerDefinition('alpha', ['App\\Shared']),
            new LayerDefinition('beta', ['App\\Shared']),
        ]);

        $reflection = new ReflectionProperty(LayerRegistry::class, 'resolutionCache');
        self::assertSame([], $reflection->getValue($registry), 'Cache starts empty.');

        $symbol = SymbolPath::forClass('App\\Shared', 'Foo');

        try {
            $registry->resolveLayer($symbol);
            self::fail('Expected LayerCollisionException.');
        } catch (LayerCollisionException) {
            // expected
        }

        $cache = $reflection->getValue($registry);
        self::assertIsArray($cache);
        self::assertArrayHasKey($symbol->toCanonical(), $cache);
        self::assertInstanceOf(
            LayerCollisionException::class,
            $cache[$symbol->toCanonical()],
            'Collision result must be cached as the exception instance itself.',
        );
    }

    #[Test]
    public function resolveLayer_collisionCacheIsKeyedByCanonicalSymbol(): void
    {
        $registry = new LayerRegistry([
            new LayerDefinition('alpha', ['App\\Shared']),
            new LayerDefinition('beta', ['App\\Shared']),
        ]);

        $symbolA = SymbolPath::forClass('App\\Shared', 'Foo');
        $symbolB = SymbolPath::forClass('App\\Shared', 'Bar');

        $exceptionA = null;
        try {
            $registry->resolveLayer($symbolA);
        } catch (LayerCollisionException $exception) {
            $exceptionA = $exception;
        }

        $exceptionB = null;
        try {
            $registry->resolveLayer($symbolB);
        } catch (LayerCollisionException $exception) {
            $exceptionB = $exception;
        }

        self::assertNotNull($exceptionA);
        self::assertNotNull($exceptionB);

        // Different canonical paths → different fresh exception instances
        // (each must report its own FQN).
        self::assertNotSame($exceptionA, $exceptionB);
        self::assertSame('App\\Shared\\Foo', $exceptionA->getFqn());
        self::assertSame('App\\Shared\\Bar', $exceptionB->getFqn());
    }
}
