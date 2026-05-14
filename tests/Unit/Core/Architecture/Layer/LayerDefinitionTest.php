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
use Qualimetrix\Core\Architecture\Layer\MatchedCriterion;
use Qualimetrix\Core\Architecture\Layer\MatchedCriterionKind;
use Qualimetrix\Core\Architecture\Layer\MatchMode;
use Qualimetrix\Core\Architecture\Layer\MembershipResult;
use Qualimetrix\Core\Architecture\Layer\MembershipSpec;
use stdClass;

#[CoversClass(LayerDefinition::class)]
#[CoversClass(MembershipSpec::class)]
#[CoversClass(MembershipResult::class)]
#[CoversClass(MatchedCriterion::class)]
#[CoversClass(MatchedCriterionKind::class)]
#[CoversClass(ClassContext::class)]
#[CoversClass(MatchMode::class)]
#[CoversClass(InvalidLayerDefinitionException::class)]
final class LayerDefinitionTest extends TestCase
{
    #[Test]
    public function name_returnsConfiguredName(): void
    {
        $definition = self::patternLayer('controller', ['App\\Controller']);

        self::assertSame('controller', $definition->name());
    }

    #[Test]
    public function patterns_returnsOriginalPatterns(): void
    {
        $definition = self::patternLayer('controller', ['App\\Controller', 'App\\Web\\**']);

        self::assertSame(['App\\Controller', 'App\\Web\\**'], $definition->patterns());
    }

    #[Test]
    public function membership_returnsSpec(): void
    {
        $spec = new MembershipSpec(patterns: ['App\\Foo']);
        $definition = new LayerDefinition('foo', $spec);

        self::assertSame($spec, $definition->membership());
        self::assertSame(MatchMode::Any, $definition->membership()->mode);
    }

    // -------------------------------------------------------------------------
    // patterns criterion
    // -------------------------------------------------------------------------

    #[Test]
    public function matches_returnsNoMatchForEmptyFqn(): void
    {
        $definition = self::patternLayer('any', ['App\\Foo']);

        $result = $definition->matches(self::context(''));

        self::assertFalse($result->matched);
        self::assertSame([], $result->matchedCriteria);
    }

    #[Test]
    public function matches_pureLiteralMatchesExactNamespace(): void
    {
        $definition = self::patternLayer('service', ['App\\Service']);

        self::assertTrue($definition->matches(self::context('App\\Service'))->matched);
    }

    #[Test]
    public function matches_pureLiteralMatchesChildNamespace(): void
    {
        $definition = self::patternLayer('service', ['App\\Service']);

        self::assertTrue($definition->matches(self::context('App\\Service\\Foo'))->matched);
    }

    #[Test]
    public function matches_pureLiteralMatchesDeeplyNestedNamespace(): void
    {
        $definition = self::patternLayer('service', ['App\\Service']);

        self::assertTrue($definition->matches(self::context('App\\Service\\Deep\\Sub'))->matched);
    }

    #[Test]
    public function matches_pureLiteralRespectsBoundary(): void
    {
        $definition = self::patternLayer('service', ['App\\Service']);

        self::assertFalse(
            $definition->matches(self::context('App\\ServiceManager\\Foo'))->matched,
            'App\\Service must not match App\\ServiceManager — namespace boundaries are respected.',
        );
    }

    #[Test]
    public function matches_globWithDoubleStar(): void
    {
        $definition = self::patternLayer('repository', ['App\\**\\Repository']);

        self::assertTrue($definition->matches(self::context('App\\X\\Repository'))->matched);
    }

    #[Test]
    public function matches_globWithTrailingDoubleStar(): void
    {
        $definition = self::patternLayer('service', ['App\\Service\\**']);

        self::assertTrue($definition->matches(self::context('App\\Service\\Foo'))->matched);
    }

    #[Test]
    public function matches_anyOfMultiplePatternsSatisfies(): void
    {
        $definition = self::patternLayer('mixed', ['App\\**', 'App\\Service\\Special']);

        self::assertTrue($definition->matches(self::context('App\\Service\\Special\\Foo'))->matched);
        self::assertTrue($definition->matches(self::context('App\\Other'))->matched);
    }

    #[Test]
    public function matches_returnsNoMatchWhenNoPatternMatches(): void
    {
        $definition = self::patternLayer('controller', ['App\\Controller', 'App\\Http\\**']);

        $result = $definition->matches(self::context('App\\Service\\Foo'));

        self::assertFalse($result->matched);
        self::assertSame([], $result->matchedCriteria);
    }

    #[Test]
    public function matches_questionMarkWildcard(): void
    {
        $definition = self::patternLayer('q', ['App\\?oo']);

        self::assertTrue($definition->matches(self::context('App\\Foo'))->matched);
    }

    #[Test]
    public function matches_charClassWildcard(): void
    {
        $definition = self::patternLayer('c', ['App\\[ABC]oo']);

        self::assertTrue($definition->matches(self::context('App\\Aoo'))->matched);
    }

    #[Test]
    public function matches_normalizesTrailingBackslashInPattern(): void
    {
        $definition = self::patternLayer('svc', ['App\\Service\\']);

        self::assertTrue($definition->matches(self::context('App\\Service\\Foo'))->matched);
        self::assertTrue($definition->matches(self::context('App\\Service'))->matched);
    }

    #[Test]
    public function matches_recordsFirstMatchingPatternDescriptor(): void
    {
        $definition = self::patternLayer('mixed', ['App\\Other', 'App\\**', 'App\\Service\\Special']);

        // App\Service\Special\Foo is matched by patterns at index 1 and 2 —
        // the first in declaration order wins.
        $result = $definition->matches(self::context('App\\Service\\Special\\Foo'));

        self::assertTrue($result->matched);
        self::assertCount(1, $result->matchedCriteria);
        self::assertSame(MatchedCriterionKind::Pattern, $result->matchedCriteria[0]->kind);
        self::assertSame('App\\**', $result->matchedCriteria[0]->value);
    }

    #[Test]
    public function matches_returnsOriginalPatternStringEvenWithTrailingBackslash(): void
    {
        $definition = self::patternLayer('svc', ['App\\Service\\']);

        // The membership spec preserves the trailing backslash in the
        // original list for diagnostics. matchedCriteria echoes the source verbatim.
        $result = $definition->matches(self::context('App\\Service\\Foo'));

        self::assertCount(1, $result->matchedCriteria);
        self::assertSame('App\\Service\\', $result->matchedCriteria[0]->value);
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
            $definition = self::patternLayer('layer', $patterns);
            self::assertSame(
                $expected,
                $definition->matches(self::context($fqn))->matched,
                \sprintf('matches([%s], %s) expected %s', implode(',', $patterns), $fqn, $expected ? 'true' : 'false'),
            );
        }
    }

    // -------------------------------------------------------------------------
    // suffix criterion
    // -------------------------------------------------------------------------

    #[Test]
    public function matches_suffix_matchesShortNameSuffix(): void
    {
        $definition = new LayerDefinition('repository', new MembershipSpec(suffix: ['Repository']));

        $result = $definition->matches(self::context('App\\Service\\UserRepository'));

        self::assertTrue($result->matched);
        self::assertCount(1, $result->matchedCriteria);
        self::assertSame(MatchedCriterionKind::Suffix, $result->matchedCriteria[0]->kind);
        self::assertSame('Repository', $result->matchedCriteria[0]->value);
    }

    #[Test]
    public function matches_suffix_matchesExactShortName(): void
    {
        // 'Service' as suffix also matches a class named exactly 'Service'.
        $definition = new LayerDefinition('svc', new MembershipSpec(suffix: ['Service']));

        self::assertTrue($definition->matches(self::context('App\\Service'))->matched);
    }

    #[Test]
    public function matches_suffix_doesNotMatchWhenSuffixIsInTheMiddle(): void
    {
        $definition = new LayerDefinition('repository', new MembershipSpec(suffix: ['Repository']));

        self::assertFalse(
            $definition->matches(self::context('App\\Service\\RepositoryHelper'))->matched,
            'suffix matching is anchored to the right; "RepositoryHelper" must not match suffix "Repository".',
        );
    }

    #[Test]
    public function matches_suffix_multipleEntriesAreOred(): void
    {
        $definition = new LayerDefinition(
            'persistence',
            new MembershipSpec(suffix: ['Repository', 'Dao']),
        );

        self::assertTrue($definition->matches(self::context('App\\UserRepository'))->matched);
        self::assertTrue($definition->matches(self::context('App\\UserDao'))->matched);
    }

    // -------------------------------------------------------------------------
    // attributes criterion
    // -------------------------------------------------------------------------

    #[Test]
    public function matches_attributes_matchesByFqn(): void
    {
        $definition = new LayerDefinition(
            'entity',
            new MembershipSpec(attributes: ['Doctrine\\ORM\\Mapping\\Entity']),
        );

        $context = new ClassContext(
            'App\\Domain\\User',
            'User',
            attributeFqns: ['Doctrine\\ORM\\Mapping\\Entity', 'App\\Audit\\Loggable'],
        );

        $result = $definition->matches($context);

        self::assertTrue($result->matched);
        self::assertSame(MatchedCriterionKind::Attribute, $result->matchedCriteria[0]->kind);
        self::assertSame('Doctrine\\ORM\\Mapping\\Entity', $result->matchedCriteria[0]->value);
    }

    #[Test]
    public function matches_attributes_noMatchWhenClassHasNoAttributes(): void
    {
        $definition = new LayerDefinition(
            'entity',
            new MembershipSpec(attributes: ['Doctrine\\ORM\\Mapping\\Entity']),
        );

        $context = new ClassContext('App\\Domain\\User', 'User');

        self::assertFalse($definition->matches($context)->matched);
    }

    // -------------------------------------------------------------------------
    // implements / extends
    // -------------------------------------------------------------------------

    #[Test]
    public function matches_implements_byTransitiveInterface(): void
    {
        $definition = new LayerDefinition(
            'repository',
            new MembershipSpec(implements: ['Doctrine\\Persistence\\ObjectRepository']),
        );

        $context = new ClassContext(
            'App\\Repository\\UserRepository',
            'UserRepository',
            interfaces: ['App\\Repository\\UserRepositoryInterface', 'Doctrine\\Persistence\\ObjectRepository'],
        );

        $result = $definition->matches($context);

        self::assertTrue($result->matched);
        self::assertSame(MatchedCriterionKind::Implements, $result->matchedCriteria[0]->kind);
        self::assertSame('Doctrine\\Persistence\\ObjectRepository', $result->matchedCriteria[0]->value);
    }

    #[Test]
    public function matches_extends_byTransitiveParent(): void
    {
        $definition = new LayerDefinition(
            'aggregate',
            new MembershipSpec(extends: ['App\\Domain\\AggregateRoot']),
        );

        $context = new ClassContext(
            'App\\Domain\\User',
            'User',
            parentClasses: ['App\\Domain\\UserBase', 'App\\Domain\\AggregateRoot'],
        );

        $result = $definition->matches($context);

        self::assertTrue($result->matched);
        self::assertSame(MatchedCriterionKind::Extends, $result->matchedCriteria[0]->kind);
        self::assertSame('App\\Domain\\AggregateRoot', $result->matchedCriteria[0]->value);
    }

    // -------------------------------------------------------------------------
    // match: any | all combination semantics
    // -------------------------------------------------------------------------

    #[Test]
    public function matches_any_combinesCriteriaWithOr(): void
    {
        $definition = new LayerDefinition(
            'repository',
            new MembershipSpec(
                patterns: ['App\\Repository\\**'],
                suffix: ['Repository'],
            ),
        );

        // Class matches via suffix only — not in App\Repository namespace.
        $result = $definition->matches(self::context('App\\Service\\UserRepository'));

        self::assertTrue($result->matched);
        self::assertCount(1, $result->matchedCriteria);
        self::assertSame(MatchedCriterionKind::Suffix, $result->matchedCriteria[0]->kind);
    }

    #[Test]
    public function matches_any_recordsEveryMatchingCriterion(): void
    {
        $definition = new LayerDefinition(
            'repository',
            new MembershipSpec(
                patterns: ['App\\Repository\\**'],
                suffix: ['Repository'],
            ),
        );

        // Class matches BOTH patterns and suffix — both descriptors are recorded.
        $result = $definition->matches(self::context('App\\Repository\\UserRepository'));

        self::assertTrue($result->matched);
        self::assertCount(2, $result->matchedCriteria);
        self::assertSame(MatchedCriterionKind::Pattern, $result->matchedCriteria[0]->kind);
        self::assertSame(MatchedCriterionKind::Suffix, $result->matchedCriteria[1]->kind);
    }

    #[Test]
    public function matches_all_rejectsClassMissingOneCriterion(): void
    {
        $definition = new LayerDefinition(
            'strict-repository',
            new MembershipSpec(
                patterns: ['App\\Repository\\**'],
                suffix: ['Repository'],
                mode: MatchMode::All,
            ),
        );

        // Pattern matches but suffix doesn't.
        $result = $definition->matches(self::context('App\\Repository\\UserService'));

        self::assertFalse($result->matched);
    }

    #[Test]
    public function matches_all_acceptsClassMatchingEveryDeclaredCriterion(): void
    {
        $definition = new LayerDefinition(
            'strict-repository',
            new MembershipSpec(
                patterns: ['App\\Repository\\**'],
                suffix: ['Repository'],
                mode: MatchMode::All,
            ),
        );

        $result = $definition->matches(self::context('App\\Repository\\UserRepository'));

        self::assertTrue($result->matched);
        self::assertCount(2, $result->matchedCriteria);
    }

    #[Test]
    public function matches_all_emptyCriteriaAreTriviallySatisfied(): void
    {
        // Only patterns declared; suffix/attributes/implements/extends are empty —
        // they should not affect MatchMode::All semantics.
        $definition = new LayerDefinition(
            'service',
            new MembershipSpec(patterns: ['App\\Service\\**'], mode: MatchMode::All),
        );

        self::assertTrue($definition->matches(self::context('App\\Service\\Foo'))->matched);
    }

    // -------------------------------------------------------------------------
    // Name validation
    // -------------------------------------------------------------------------

    #[Test]
    public function construct_throwsOnEmptyName(): void
    {
        $this->expectException(InvalidLayerDefinitionException::class);
        new LayerDefinition('', new MembershipSpec(patterns: ['App\\Foo']));
    }

    #[DataProvider('invalidNameProvider')]
    #[Test]
    public function construct_throwsOnInvalidName(string $invalidName): void
    {
        $this->expectException(InvalidLayerDefinitionException::class);
        new LayerDefinition($invalidName, new MembershipSpec(patterns: ['App\\Foo']));
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
        $definition = self::patternLayer('a1_b-c', ['App\\Foo']);

        self::assertSame('a1_b-c', $definition->name());
    }

    // -------------------------------------------------------------------------
    // MembershipSpec invariants
    // -------------------------------------------------------------------------

    #[Test]
    public function membershipSpec_throwsWhenAllCriterionListsAreEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at least one non-empty criterion list/');

        new MembershipSpec();
    }

    #[Test]
    public function membershipSpec_throwsOnEmptyStringEntry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/patterns\[1\] must not be empty/');

        new MembershipSpec(patterns: ['App\\Service', '']);
    }

    #[Test]
    public function membershipSpec_throwsOnNonStringPattern(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/patterns\[0\] must be a string, int given/');

        /** @phpstan-ignore-next-line — deliberately violating the type contract to verify runtime guard */
        new MembershipSpec(patterns: [42]);
    }

    #[Test]
    public function membershipSpec_throwsOnNonStringPatternAtNonZeroIndex(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/patterns\[1\] must be a string, int given/');

        /** @phpstan-ignore-next-line — deliberately violating the type contract to verify runtime guard */
        new MembershipSpec(patterns: ['App\\Service', 99]);
    }

    #[Test]
    public function membershipSpec_throwsOnNullPattern(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/patterns\[0\] must be a string, null given/');

        /** @phpstan-ignore-next-line — deliberately violating the type contract to verify runtime guard */
        new MembershipSpec(patterns: [null]);
    }

    #[Test]
    public function membershipSpec_throwsOnArrayPattern(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/patterns\[0\] must be a string, array given/');

        /** @phpstan-ignore-next-line — deliberately violating the type contract to verify runtime guard */
        new MembershipSpec(patterns: [['App\\Service']]);
    }

    #[Test]
    public function membershipSpec_throwsOnObjectPattern(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/patterns\[0\] must be a string, stdClass given/');

        /** @phpstan-ignore-next-line — deliberately violating the type contract to verify runtime guard */
        new MembershipSpec(patterns: [new stdClass()]);
    }

    #[Test]
    public function membershipSpec_defaultsToAnyMatchMode(): void
    {
        $spec = new MembershipSpec(patterns: ['App\\Foo']);

        self::assertSame(MatchMode::Any, $spec->mode);
    }

    #[Test]
    public function membershipSpec_acceptsExplicitAllMode(): void
    {
        $spec = new MembershipSpec(patterns: ['App\\Foo'], mode: MatchMode::All);

        self::assertSame(MatchMode::All, $spec->mode);
    }

    #[Test]
    public function membershipSpec_acceptsSuffixOnly(): void
    {
        $spec = new MembershipSpec(suffix: ['Repository']);

        self::assertSame([], $spec->patterns);
        self::assertSame(['Repository'], $spec->suffix);
    }

    #[Test]
    public function membershipSpec_acceptsAttributesOnly(): void
    {
        $spec = new MembershipSpec(attributes: ['App\\Attr\\Entity']);

        self::assertSame(['App\\Attr\\Entity'], $spec->attributes);
    }

    #[Test]
    public function membershipSpec_acceptsImplementsOnly(): void
    {
        $spec = new MembershipSpec(implements: ['App\\Contracts\\Repository']);

        self::assertSame(['App\\Contracts\\Repository'], $spec->implements);
    }

    #[Test]
    public function membershipSpec_acceptsExtendsOnly(): void
    {
        $spec = new MembershipSpec(extends: ['App\\AbstractBase']);

        self::assertSame(['App\\AbstractBase'], $spec->extends);
    }

    #[Test]
    public function membershipResult_matchFactoryCarriesCriterionList(): void
    {
        $criterion = new MatchedCriterion(MatchedCriterionKind::Pattern, 'App\\Service\\**');
        $result = MembershipResult::match([$criterion]);

        self::assertTrue($result->matched);
        self::assertSame([$criterion], $result->matchedCriteria);
    }

    #[Test]
    public function membershipResult_matchFactoryRejectsEmptyCriteriaList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at least one matched criterion/');

        MembershipResult::match([]);
    }

    #[Test]
    public function membershipResult_noMatchFactoryHasEmptyCriteriaList(): void
    {
        $result = MembershipResult::noMatch();

        self::assertFalse($result->matched);
        self::assertSame([], $result->matchedCriteria);
    }

    #[Test]
    public function matchedCriterion_describeRendersKindAndValue(): void
    {
        self::assertSame('pattern "App\\Service"', (new MatchedCriterion(MatchedCriterionKind::Pattern, 'App\\Service'))->describe());
        self::assertSame('suffix "Repository"', (new MatchedCriterion(MatchedCriterionKind::Suffix, 'Repository'))->describe());
        self::assertSame('attribute "App\\Attr"', (new MatchedCriterion(MatchedCriterionKind::Attribute, 'App\\Attr'))->describe());
    }

    #[Test]
    public function matchedCriterion_rejectsEmptyValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MatchedCriterion(MatchedCriterionKind::Pattern, '');
    }

    #[Test]
    public function classContext_exposesFullMetadata(): void
    {
        $context = new ClassContext(
            'App\\Service\\UserService',
            'UserService',
            attributeFqns: ['App\\Attr\\Service'],
            interfaces: ['App\\Contracts\\Service'],
            parentClasses: ['App\\AbstractService'],
        );

        self::assertSame('App\\Service\\UserService', $context->fqn);
        self::assertSame('UserService', $context->shortName);
        self::assertSame(['App\\Attr\\Service'], $context->attributeFqns);
        self::assertSame(['App\\Contracts\\Service'], $context->interfaces);
        self::assertSame(['App\\AbstractService'], $context->parentClasses);
    }

    #[Test]
    public function classContext_emptyMetadataDefaults(): void
    {
        $context = new ClassContext('App\\Foo', 'Foo');

        self::assertSame([], $context->attributeFqns);
        self::assertSame([], $context->interfaces);
        self::assertSame([], $context->parentClasses);
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
    private static function patternLayer(string $name, array $patterns): LayerDefinition
    {
        return new LayerDefinition($name, new MembershipSpec(patterns: $patterns));
    }

    private static function context(string $fqn): ClassContext
    {
        $position = strrpos($fqn, '\\');
        $short = $position === false ? $fqn : substr($fqn, $position + 1);

        return new ClassContext($fqn, $short);
    }
}
