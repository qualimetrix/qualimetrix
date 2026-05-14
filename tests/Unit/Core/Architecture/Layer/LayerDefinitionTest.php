<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Architecture\Layer;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Architecture\Layer\ClassContext;
use Qualimetrix\Core\Architecture\Layer\InvalidLayerDefinitionException;
use Qualimetrix\Core\Architecture\Layer\LayerDefinition;
use Qualimetrix\Core\Architecture\Layer\MatchMode;
use Qualimetrix\Core\Architecture\Layer\MembershipResult;
use Qualimetrix\Core\Architecture\Layer\MembershipSpec;
use stdClass;

#[CoversClass(LayerDefinition::class)]
#[CoversClass(MembershipSpec::class)]
#[CoversClass(MembershipResult::class)]
#[CoversClass(ClassContext::class)]
#[CoversClass(MatchMode::class)]
#[CoversClass(InvalidLayerDefinitionException::class)]
final class LayerDefinitionTest extends TestCase
{
    #[Test]
    public function name_returnsConfiguredName(): void
    {
        $definition = self::layer('controller', ['App\\Controller']);

        self::assertSame('controller', $definition->name());
    }

    #[Test]
    public function patterns_returnsOriginalPatterns(): void
    {
        $definition = self::layer('controller', ['App\\Controller', 'App\\Web\\**']);

        self::assertSame(['App\\Controller', 'App\\Web\\**'], $definition->patterns());
    }

    #[Test]
    public function membership_returnsSpec(): void
    {
        $spec = new MembershipSpec(['App\\Foo']);
        $definition = new LayerDefinition('foo', $spec);

        self::assertSame($spec, $definition->membership());
        self::assertSame(MatchMode::Any, $definition->membership()->mode);
    }

    #[Test]
    public function matches_returnsNoMatchForEmptyFqn(): void
    {
        $definition = self::layer('any', ['App\\Foo']);

        $result = $definition->matches(self::context(''));

        self::assertFalse($result->matched);
        self::assertNull($result->matchedPattern);
    }

    #[Test]
    public function matches_pureLiteralMatchesExactNamespace(): void
    {
        $definition = self::layer('service', ['App\\Service']);

        self::assertTrue($definition->matches(self::context('App\\Service'))->matched);
    }

    #[Test]
    public function matches_pureLiteralMatchesChildNamespace(): void
    {
        $definition = self::layer('service', ['App\\Service']);

        self::assertTrue($definition->matches(self::context('App\\Service\\Foo'))->matched);
    }

    #[Test]
    public function matches_pureLiteralMatchesDeeplyNestedNamespace(): void
    {
        $definition = self::layer('service', ['App\\Service']);

        self::assertTrue($definition->matches(self::context('App\\Service\\Deep\\Sub'))->matched);
    }

    #[Test]
    public function matches_pureLiteralRespectsBoundary(): void
    {
        $definition = self::layer('service', ['App\\Service']);

        self::assertFalse(
            $definition->matches(self::context('App\\ServiceManager\\Foo'))->matched,
            'App\\Service must not match App\\ServiceManager — namespace boundaries are respected.',
        );
    }

    #[Test]
    public function matches_globWithDoubleStar(): void
    {
        $definition = self::layer('repository', ['App\\**\\Repository']);

        self::assertTrue($definition->matches(self::context('App\\X\\Repository'))->matched);
    }

    #[Test]
    public function matches_globWithTrailingDoubleStar(): void
    {
        $definition = self::layer('service', ['App\\Service\\**']);

        self::assertTrue($definition->matches(self::context('App\\Service\\Foo'))->matched);
    }

    #[Test]
    public function matches_anyOfMultiplePatternsSatisfies(): void
    {
        $definition = self::layer('mixed', ['App\\**', 'App\\Service\\Special']);

        self::assertTrue($definition->matches(self::context('App\\Service\\Special\\Foo'))->matched);
        self::assertTrue($definition->matches(self::context('App\\Other'))->matched);
    }

    #[Test]
    public function matches_returnsNoMatchWhenNoPatternMatches(): void
    {
        $definition = self::layer('controller', ['App\\Controller', 'App\\Http\\**']);

        $result = $definition->matches(self::context('App\\Service\\Foo'));

        self::assertFalse($result->matched);
        self::assertNull($result->matchedPattern);
    }

    #[Test]
    public function matches_questionMarkWildcard(): void
    {
        $definition = self::layer('q', ['App\\?oo']);

        self::assertTrue($definition->matches(self::context('App\\Foo'))->matched);
    }

    #[Test]
    public function matches_charClassWildcard(): void
    {
        $definition = self::layer('c', ['App\\[ABC]oo']);

        self::assertTrue($definition->matches(self::context('App\\Aoo'))->matched);
    }

    #[Test]
    public function matches_normalizesTrailingBackslashInPattern(): void
    {
        $definition = self::layer('svc', ['App\\Service\\']);

        // Trailing backslash stripped before matching.
        self::assertTrue($definition->matches(self::context('App\\Service\\Foo'))->matched);
        self::assertTrue($definition->matches(self::context('App\\Service'))->matched);
    }

    #[Test]
    public function matches_returnsFirstMatchingPatternOnMatch(): void
    {
        $definition = self::layer('mixed', ['App\\Other', 'App\\**', 'App\\Service\\Special']);

        // App\Service\Special\Foo is matched by patterns at index 1 and 2 —
        // the first in declaration order wins.
        self::assertSame(
            'App\\**',
            $definition->matches(self::context('App\\Service\\Special\\Foo'))->matchedPattern,
        );
    }

    #[Test]
    public function matches_returnsOriginalPatternStringEvenWithTrailingBackslash(): void
    {
        $definition = self::layer('svc', ['App\\Service\\']);

        // The membership spec preserves the trailing backslash in the
        // original list for diagnostics. matchedPattern echoes the source verbatim.
        self::assertSame(
            'App\\Service\\',
            $definition->matches(self::context('App\\Service\\Foo'))->matchedPattern,
        );
    }

    /**
     * Pins delegation to {@see \Qualimetrix\Core\Util\NamespaceMatcher::matchesSingle()}:
     * if the underlying primitive's semantics ever drift from what
     * {@see LayerDefinition} expects, this test surfaces the mismatch.
     */
    #[Test]
    public function matches_agreesWithNamespaceMatcherForGlobAndPrefixCases(): void
    {
        $cases = [
            // [patterns, fqn, expected]
            [['App\\Service'], 'App\\Service', true],
            [['App\\Service'], 'App\\Service\\Foo', true],
            [['App\\Service'], 'App\\ServiceManager', false],
            [['App\\Service'], 'App\\Other', false],
            [['App\\**\\Repository'], 'App\\Domain\\Repository', true],
            [['App\\**\\Repository'], 'App\\Domain\\Service', false],
            [['App\\?oo'], 'App\\Foo', true],
            [['App\\?oo'], 'App\\Bar', false],
            [['App\\[ABC]oo'], 'App\\Aoo', true],
            [['App\\[ABC]oo'], 'App\\Doo', false],
            [['App\\Service\\'], 'App\\Service\\Foo', true],
            [['App\\Service\\'], 'App\\Service', true],
        ];

        foreach ($cases as [$patterns, $fqn, $expected]) {
            $definition = self::layer('layer', $patterns);
            self::assertSame(
                $expected,
                $definition->matches(self::context($fqn))->matched,
                \sprintf('matches([%s], %s) expected %s', implode(',', $patterns), $fqn, $expected ? 'true' : 'false'),
            );
        }
    }

    #[Test]
    public function construct_throwsOnEmptyName(): void
    {
        $this->expectException(InvalidLayerDefinitionException::class);
        new LayerDefinition('', new MembershipSpec(['App\\Foo']));
    }

    #[DataProvider('invalidNameProvider')]
    #[Test]
    public function construct_throwsOnInvalidName(string $invalidName): void
    {
        $this->expectException(InvalidLayerDefinitionException::class);
        new LayerDefinition($invalidName, new MembershipSpec(['App\\Foo']));
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
        $definition = self::layer('a1_b-c', ['App\\Foo']);

        self::assertSame('a1_b-c', $definition->name());
    }

    #[Test]
    public function membershipSpec_throwsOnEmptyPatternList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MembershipSpec([]);
    }

    #[Test]
    public function membershipSpec_throwsOnEmptyStringPattern(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MembershipSpec(['App\\Service', '']);
    }

    #[Test]
    public function membershipSpec_throwsOnNonStringPattern(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/pattern at index 0 must be a string, int given/');

        /** @phpstan-ignore-next-line — deliberately violating the type contract to verify runtime guard */
        new MembershipSpec([42]);
    }

    #[Test]
    public function membershipSpec_throwsOnNonStringPatternAtNonZeroIndex(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/pattern at index 1 must be a string, int given/');

        /** @phpstan-ignore-next-line — deliberately violating the type contract to verify runtime guard */
        new MembershipSpec(['App\\Service', 99]);
    }

    #[Test]
    public function membershipSpec_throwsOnNullPattern(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/pattern at index 0 must be a string, null given/');

        /** @phpstan-ignore-next-line — deliberately violating the type contract to verify runtime guard */
        new MembershipSpec([null]);
    }

    #[Test]
    public function membershipSpec_throwsOnArrayPattern(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/pattern at index 0 must be a string, array given/');

        /** @phpstan-ignore-next-line — deliberately violating the type contract to verify runtime guard */
        new MembershipSpec([['App\\Service']]);
    }

    #[Test]
    public function membershipSpec_throwsOnObjectPattern(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/pattern at index 0 must be a string, stdClass given/');

        /** @phpstan-ignore-next-line — deliberately violating the type contract to verify runtime guard */
        new MembershipSpec([new stdClass()]);
    }

    #[Test]
    public function membershipSpec_defaultsToAnyMatchMode(): void
    {
        $spec = new MembershipSpec(['App\\Foo']);

        self::assertSame(MatchMode::Any, $spec->mode);
    }

    #[Test]
    public function membershipSpec_acceptsExplicitAllMode(): void
    {
        $spec = new MembershipSpec(['App\\Foo'], MatchMode::All);

        self::assertSame(MatchMode::All, $spec->mode);
    }

    #[Test]
    public function membershipResult_matchFactoryCarriesPattern(): void
    {
        $result = MembershipResult::match('App\\Service\\**');

        self::assertTrue($result->matched);
        self::assertSame('App\\Service\\**', $result->matchedPattern);
    }

    #[Test]
    public function membershipResult_noMatchFactoryHasNullPattern(): void
    {
        $result = MembershipResult::noMatch();

        self::assertFalse($result->matched);
        self::assertNull($result->matchedPattern);
    }

    #[Test]
    public function classContext_exposesFqnAndShortName(): void
    {
        $context = new ClassContext('App\\Service\\UserService', 'UserService');

        self::assertSame('App\\Service\\UserService', $context->fqn);
        self::assertSame('UserService', $context->shortName);
    }

    #[Test]
    public function classContext_emptyFqnAndShortNameArePermitted(): void
    {
        $context = new ClassContext('', '');

        self::assertSame('', $context->fqn);
        self::assertSame('', $context->shortName);
    }

    #[Test]
    public function matchMode_hasOnlyAnyAndAllCases(): void
    {
        self::assertSame(
            ['any', 'all'],
            array_map(static fn(MatchMode $mode): string => $mode->value, MatchMode::cases()),
        );
    }

    /**
     * @param list<string> $patterns
     */
    private static function layer(string $name, array $patterns): LayerDefinition
    {
        return new LayerDefinition($name, new MembershipSpec($patterns));
    }

    private static function context(string $fqn): ClassContext
    {
        $position = strrpos($fqn, '\\');
        $short = $position === false ? $fqn : substr($fqn, $position + 1);

        return new ClassContext($fqn, $short);
    }
}
