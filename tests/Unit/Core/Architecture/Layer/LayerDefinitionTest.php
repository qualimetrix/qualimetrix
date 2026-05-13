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
    public function match_returnsNullForEmptyFqn(): void
    {
        $definition = new LayerDefinition('any', ['App\\Foo']);

        self::assertNull($definition->match(''));
    }

    #[Test]
    public function match_pureLiteralMatchesExactNamespace(): void
    {
        $definition = new LayerDefinition('service', ['App\\Service']);

        self::assertSame(11, $definition->match('App\\Service'));
    }

    #[Test]
    public function match_pureLiteralMatchesChildNamespace(): void
    {
        $definition = new LayerDefinition('service', ['App\\Service']);

        self::assertSame(11, $definition->match('App\\Service\\Foo'));
    }

    #[Test]
    public function match_pureLiteralMatchesDeeplyNestedNamespace(): void
    {
        $definition = new LayerDefinition('service', ['App\\Service']);

        self::assertSame(11, $definition->match('App\\Service\\Deep\\Sub'));
    }

    #[Test]
    public function match_pureLiteralRespectsBoundary(): void
    {
        $definition = new LayerDefinition('service', ['App\\Service']);

        self::assertNull(
            $definition->match('App\\ServiceManager\\Foo'),
            'App\\Service must not match App\\ServiceManager — namespace boundaries are respected.',
        );
    }

    #[Test]
    public function match_globWithDoubleStarReturnsPrefixSpecificity(): void
    {
        $definition = new LayerDefinition('repository', ['App\\**\\Repository']);

        // Literal prefix before first '*' is "App\\" → length 4.
        self::assertSame(4, $definition->match('App\\X\\Repository'));
    }

    #[Test]
    public function match_globWithTrailingDoubleStarReturnsPrefixSpecificity(): void
    {
        $definition = new LayerDefinition('service', ['App\\Service\\**']);

        // Literal prefix "App\\Service\\" → length 12.
        self::assertSame(12, $definition->match('App\\Service\\Foo'));
    }

    #[Test]
    public function match_pureLiteralAppFooSpecificity(): void
    {
        $definition = new LayerDefinition('foo', ['App\\Foo']);

        self::assertSame(7, $definition->match('App\\Foo'));
        self::assertSame(7, $definition->match('App\\Foo\\Bar'));
    }

    #[Test]
    public function match_returnsMaximumSpecificityAcrossPatterns(): void
    {
        $definition = new LayerDefinition('mixed', ['App\\**', 'App\\Service\\Special']);

        // For App\Service\Special\Foo, the literal prefix "App\\" (specificity 4)
        // and the literal "App\Service\Special" (specificity 19) both match. Max wins.
        self::assertSame(19, $definition->match('App\\Service\\Special\\Foo'));
    }

    #[Test]
    public function match_returnsNullWhenNoPatternMatches(): void
    {
        $definition = new LayerDefinition('controller', ['App\\Controller', 'App\\Http\\**']);

        self::assertNull($definition->match('App\\Service\\Foo'));
    }

    #[Test]
    public function match_questionMarkWildcardComputesSpecificityFromQuestionMark(): void
    {
        $definition = new LayerDefinition('q', ['App\\?oo']);

        // First wildcard at position 4 → specificity 4.
        self::assertSame(4, $definition->match('App\\Foo'));
    }

    #[Test]
    public function match_charClassWildcardComputesSpecificityFromBracket(): void
    {
        $definition = new LayerDefinition('c', ['App\\[ABC]oo']);

        // First wildcard `[` at position 4 → specificity 4.
        self::assertSame(4, $definition->match('App\\Aoo'));
    }

    #[Test]
    public function match_normalizesTrailingBackslashInPattern(): void
    {
        $definition = new LayerDefinition('svc', ['App\\Service\\']);

        // Trailing backslash stripped: pattern length 11.
        self::assertSame(11, $definition->match('App\\Service\\Foo'));
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
