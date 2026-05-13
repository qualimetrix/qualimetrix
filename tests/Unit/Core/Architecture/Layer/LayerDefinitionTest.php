<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Architecture\Layer;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Architecture\Layer\InvalidLayerDefinitionException;
use Qualimetrix\Core\Architecture\Layer\LayerDefinition;
use stdClass;

#[CoversClass(LayerDefinition::class)]
#[CoversClass(InvalidLayerDefinitionException::class)]
final class LayerDefinitionTest extends TestCase
{
    #[Test]
    public function name_returnsConfiguredName(): void
    {
        $definition = new LayerDefinition('controller', ['App\\Controller']);

        self::assertSame('controller', $definition->name());
    }

    #[Test]
    public function patterns_returnsOriginalPatterns(): void
    {
        $definition = new LayerDefinition('controller', ['App\\Controller', 'App\\Web\\**']);

        self::assertSame(['App\\Controller', 'App\\Web\\**'], $definition->patterns());
    }

    #[Test]
    public function matches_returnsFalseForEmptyFqn(): void
    {
        $definition = new LayerDefinition('any', ['App\\Foo']);

        self::assertFalse($definition->matches(''));
    }

    #[Test]
    public function matches_pureLiteralMatchesExactNamespace(): void
    {
        $definition = new LayerDefinition('service', ['App\\Service']);

        self::assertTrue($definition->matches('App\\Service'));
    }

    #[Test]
    public function matches_pureLiteralMatchesChildNamespace(): void
    {
        $definition = new LayerDefinition('service', ['App\\Service']);

        self::assertTrue($definition->matches('App\\Service\\Foo'));
    }

    #[Test]
    public function matches_pureLiteralMatchesDeeplyNestedNamespace(): void
    {
        $definition = new LayerDefinition('service', ['App\\Service']);

        self::assertTrue($definition->matches('App\\Service\\Deep\\Sub'));
    }

    #[Test]
    public function matches_pureLiteralRespectsBoundary(): void
    {
        $definition = new LayerDefinition('service', ['App\\Service']);

        self::assertFalse(
            $definition->matches('App\\ServiceManager\\Foo'),
            'App\\Service must not match App\\ServiceManager — namespace boundaries are respected.',
        );
    }

    #[Test]
    public function matches_globWithDoubleStar(): void
    {
        $definition = new LayerDefinition('repository', ['App\\**\\Repository']);

        self::assertTrue($definition->matches('App\\X\\Repository'));
    }

    #[Test]
    public function matches_globWithTrailingDoubleStar(): void
    {
        $definition = new LayerDefinition('service', ['App\\Service\\**']);

        self::assertTrue($definition->matches('App\\Service\\Foo'));
    }

    #[Test]
    public function matches_anyOfMultiplePatternsSatisfies(): void
    {
        $definition = new LayerDefinition('mixed', ['App\\**', 'App\\Service\\Special']);

        self::assertTrue($definition->matches('App\\Service\\Special\\Foo'));
        self::assertTrue($definition->matches('App\\Other'));
    }

    #[Test]
    public function matches_returnsFalseWhenNoPatternMatches(): void
    {
        $definition = new LayerDefinition('controller', ['App\\Controller', 'App\\Http\\**']);

        self::assertFalse($definition->matches('App\\Service\\Foo'));
    }

    #[Test]
    public function matches_questionMarkWildcard(): void
    {
        $definition = new LayerDefinition('q', ['App\\?oo']);

        self::assertTrue($definition->matches('App\\Foo'));
    }

    #[Test]
    public function matches_charClassWildcard(): void
    {
        $definition = new LayerDefinition('c', ['App\\[ABC]oo']);

        self::assertTrue($definition->matches('App\\Aoo'));
    }

    #[Test]
    public function matches_normalizesTrailingBackslashInPattern(): void
    {
        $definition = new LayerDefinition('svc', ['App\\Service\\']);

        // Trailing backslash stripped before matching.
        self::assertTrue($definition->matches('App\\Service\\Foo'));
        self::assertTrue($definition->matches('App\\Service'));
    }

    #[Test]
    public function firstMatchingPattern_returnsTheFirstMatch(): void
    {
        $definition = new LayerDefinition('mixed', ['App\\Other', 'App\\**', 'App\\Service\\Special']);

        // App\Service\Special\Foo is matched by patterns at index 1 and 2 —
        // the first in declaration order wins.
        self::assertSame('App\\**', $definition->firstMatchingPattern('App\\Service\\Special\\Foo'));
    }

    #[Test]
    public function firstMatchingPattern_returnsNullForNoMatch(): void
    {
        $definition = new LayerDefinition('svc', ['App\\Service']);

        self::assertNull($definition->firstMatchingPattern('Other\\Foo'));
    }

    #[Test]
    public function firstMatchingPattern_returnsNullForEmptyFqn(): void
    {
        $definition = new LayerDefinition('svc', ['App\\Service']);

        self::assertNull($definition->firstMatchingPattern(''));
    }

    #[Test]
    public function firstMatchingPattern_returnsOriginalPatternStringEvenWithTrailingBackslash(): void
    {
        $definition = new LayerDefinition('svc', ['App\\Service\\']);

        // The factory preserves the trailing backslash in the original list
        // for diagnostics. Returned string matches the source verbatim.
        self::assertSame('App\\Service\\', $definition->firstMatchingPattern('App\\Service\\Foo'));
    }

    #[Test]
    public function construct_throwsOnEmptyName(): void
    {
        $this->expectException(InvalidLayerDefinitionException::class);
        new LayerDefinition('', ['App\\Foo']);
    }

    /**
     */
    #[DataProvider('invalidNameProvider')]
    #[Test]
    public function construct_throwsOnInvalidName(string $invalidName): void
    {
        $this->expectException(InvalidLayerDefinitionException::class);
        new LayerDefinition($invalidName, ['App\\Foo']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNameProvider(): iterable
    {
        yield 'uppercase' => ['Controller'];
        yield 'starts with digit' => ['1service'];
        yield 'starts with underscore' => ['_service'];
        yield 'starts with hyphen' => ['-service'];
        yield 'contains dot' => ['app.controller'];
        yield 'contains space' => ['app controller'];
        yield 'contains slash' => ['app/controller'];
        yield 'contains backslash' => ['App\\Controller'];
    }

    #[Test]
    public function construct_acceptsValidNameWithDigitsUnderscoreHyphen(): void
    {
        $definition = new LayerDefinition('a1_b-c', ['App\\Foo']);

        self::assertSame('a1_b-c', $definition->name());
    }

    #[Test]
    public function construct_throwsOnEmptyPatternList(): void
    {
        $this->expectException(InvalidLayerDefinitionException::class);
        new LayerDefinition('service', []);
    }

    #[Test]
    public function construct_throwsOnEmptyStringPattern(): void
    {
        $this->expectException(InvalidLayerDefinitionException::class);
        new LayerDefinition('service', ['App\\Service', '']);
    }

    #[Test]
    public function construct_throwsOnNonStringPattern(): void
    {
        $this->expectException(InvalidLayerDefinitionException::class);
        $this->expectExceptionMessageMatches('/pattern at index 0 must be a string/');

        /** @phpstan-ignore-next-line — deliberately violating the type contract to verify runtime guard */
        new LayerDefinition('service', [42]);
    }

    #[Test]
    public function construct_throwsOnNonStringPatternAtNonZeroIndex(): void
    {
        $this->expectException(InvalidLayerDefinitionException::class);
        $this->expectExceptionMessageMatches('/pattern at index 1 must be a string, int given/');

        /** @phpstan-ignore-next-line — deliberately violating the type contract to verify runtime guard */
        new LayerDefinition('service', ['App\\Service', 99]);
    }

    #[Test]
    public function construct_throwsOnNullPattern(): void
    {
        $this->expectException(InvalidLayerDefinitionException::class);
        $this->expectExceptionMessageMatches('/pattern at index 0 must be a string, null given/');

        /** @phpstan-ignore-next-line — deliberately violating the type contract to verify runtime guard */
        new LayerDefinition('service', [null]);
    }

    #[Test]
    public function construct_throwsOnArrayPattern(): void
    {
        $this->expectException(InvalidLayerDefinitionException::class);
        $this->expectExceptionMessageMatches('/pattern at index 0 must be a string, array given/');

        /** @phpstan-ignore-next-line — deliberately violating the type contract to verify runtime guard */
        new LayerDefinition('service', [['App\\Service']]);
    }

    #[Test]
    public function construct_throwsOnObjectPattern(): void
    {
        $this->expectException(InvalidLayerDefinitionException::class);
        $this->expectExceptionMessageMatches('/pattern at index 0 must be a string, stdClass given/');

        /** @phpstan-ignore-next-line — deliberately violating the type contract to verify runtime guard */
        new LayerDefinition('service', [new stdClass()]);
    }
}
