<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Unit\Domain\Layer;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ClassContext;
use Qualimetrix\Analysis\Policy\Architecture\Layer\CriterionListValidator;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ExcludeSpec;
use Qualimetrix\Analysis\Policy\Architecture\Layer\InvalidLayerDefinitionException;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerCriteriaMatcher;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerDefinition;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MatchedCriterion;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MatchedCriterionKind;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MatchMode;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MembershipResult;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MembershipSpec;
use stdClass;

#[CoversClass(LayerDefinition::class)]
#[CoversClass(MembershipSpec::class)]
#[CoversClass(MembershipResult::class)]
#[CoversClass(MatchedCriterion::class)]
#[CoversClass(MatchedCriterionKind::class)]
#[CoversClass(ClassContext::class)]
#[CoversClass(MatchMode::class)]
#[CoversClass(ExcludeSpec::class)]
#[CoversClass(InvalidLayerDefinitionException::class)]
#[CoversClass(LayerCriteriaMatcher::class)]
#[CoversClass(CriterionListValidator::class)]
final class LayerDefinitionTest extends TestCase
{
    #[Test]
    public function itReturnsTheConfiguredName(): void
    {
        $definition = self::patternLayer('controller', ['App\\Controller']);

        self::assertSame('controller', $definition->name());
    }

    #[Test]
    public function itReturnsTheOriginalPatternsUnchanged(): void
    {
        $definition = self::patternLayer('controller', ['App\\Controller', 'App\\Web\\**']);

        self::assertSame(['App\\Controller', 'App\\Web\\**'], $definition->patterns());
    }

    #[Test]
    public function itReturnsTheMembershipSpec(): void
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
    public function itReturnsNoMatchForAnEmptyFqn(): void
    {
        $definition = self::patternLayer('any', ['App\\Foo']);

        $result = $definition->matches(self::context(''));

        self::assertFalse($result->matched);
        self::assertSame([], $result->matchedCriteria);
    }

    #[Test]
    public function itMatchesAPureLiteralAgainstTheExactNamespace(): void
    {
        $definition = self::patternLayer('service', ['App\\Service']);

        self::assertTrue($definition->matches(self::context('App\\Service'))->matched);
    }

    #[Test]
    public function itMatchesAPureLiteralAgainstAChildNamespace(): void
    {
        $definition = self::patternLayer('service', ['App\\Service']);

        self::assertTrue($definition->matches(self::context('App\\Service\\Foo'))->matched);
    }

    #[Test]
    public function itMatchesAPureLiteralAgainstADeeplyNestedNamespace(): void
    {
        $definition = self::patternLayer('service', ['App\\Service']);

        self::assertTrue($definition->matches(self::context('App\\Service\\Deep\\Sub'))->matched);
    }

    #[Test]
    public function itRespectsTheNamespaceBoundaryOfAPureLiteralPattern(): void
    {
        $definition = self::patternLayer('service', ['App\\Service']);

        self::assertFalse(
            $definition->matches(self::context('App\\ServiceManager\\Foo'))->matched,
            'App\\Service must not match App\\ServiceManager — namespace boundaries are respected.',
        );
    }

    #[Test]
    public function itMatchesAGlobWithADoubleStarSegment(): void
    {
        $definition = self::patternLayer('repository', ['App\\**\\Repository']);

        self::assertTrue($definition->matches(self::context('App\\X\\Repository'))->matched);
    }

    #[Test]
    public function itMatchesAGlobWithATrailingDoubleStar(): void
    {
        $definition = self::patternLayer('service', ['App\\Service\\**']);

        self::assertTrue($definition->matches(self::context('App\\Service\\Foo'))->matched);
    }

    #[Test]
    public function itMatchesWhenAnyOfMultiplePatternsIsSatisfied(): void
    {
        $definition = self::patternLayer('mixed', ['App\\**', 'App\\Service\\Special']);

        self::assertTrue($definition->matches(self::context('App\\Service\\Special\\Foo'))->matched);
        self::assertTrue($definition->matches(self::context('App\\Other'))->matched);
    }

    #[Test]
    public function itReturnsNoMatchWhenNoPatternMatches(): void
    {
        $definition = self::patternLayer('controller', ['App\\Controller', 'App\\Http\\**']);

        $result = $definition->matches(self::context('App\\Service\\Foo'));

        self::assertFalse($result->matched);
        self::assertSame([], $result->matchedCriteria);
    }

    #[Test]
    public function itMatchesAQuestionMarkWildcard(): void
    {
        $definition = self::patternLayer('q', ['App\\?oo']);

        self::assertTrue($definition->matches(self::context('App\\Foo'))->matched);
    }

    #[Test]
    public function itMatchesACharacterClassWildcard(): void
    {
        $definition = self::patternLayer('c', ['App\\[ABC]oo']);

        self::assertTrue($definition->matches(self::context('App\\Aoo'))->matched);
    }

    #[Test]
    public function itNormalizesATrailingBackslashInThePattern(): void
    {
        $definition = self::patternLayer('svc', ['App\\Service\\']);

        self::assertTrue($definition->matches(self::context('App\\Service\\Foo'))->matched);
        self::assertTrue($definition->matches(self::context('App\\Service'))->matched);
    }

    #[Test]
    public function itRecordsOnlyTheFirstMatchingPatternDescriptor(): void
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
    public function itEchoesTheOriginalPatternStringIncludingATrailingBackslash(): void
    {
        $definition = self::patternLayer('svc', ['App\\Service\\']);

        // The membership spec preserves the trailing backslash in the
        // original list for diagnostics. matchedCriteria echoes the source verbatim.
        $result = $definition->matches(self::context('App\\Service\\Foo'));

        self::assertCount(1, $result->matchedCriteria);
        self::assertSame('App\\Service\\', $result->matchedCriteria[0]->value);
    }

    /**
     * Pins delegation to {@see \Qualimetrix\Core\Pattern\NamespaceMatcher::matchesSingle()}:
     * if the underlying primitive's semantics ever drift from what
     * {@see LayerDefinition} expects, this test surfaces the mismatch.
     */
    #[Test]
    public function itAgreesWithNamespaceMatcherAcrossGlobAndPrefixCases(): void
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
    public function itMatchesASuffixAgainstTheShortNameEnding(): void
    {
        $definition = new LayerDefinition('repository', new MembershipSpec(suffix: ['Repository']));

        $result = $definition->matches(self::context('App\\Service\\UserRepository'));

        self::assertTrue($result->matched);
        self::assertCount(1, $result->matchedCriteria);
        self::assertSame(MatchedCriterionKind::Suffix, $result->matchedCriteria[0]->kind);
        self::assertSame('Repository', $result->matchedCriteria[0]->value);
    }

    #[Test]
    public function itMatchesASuffixThatEqualsTheWholeShortName(): void
    {
        // 'Service' as suffix also matches a class named exactly 'Service'.
        $definition = new LayerDefinition('svc', new MembershipSpec(suffix: ['Service']));

        self::assertTrue($definition->matches(self::context('App\\Service'))->matched);
    }

    #[Test]
    public function itDoesNotMatchASuffixThatAppearsInTheMiddleOfTheShortName(): void
    {
        $definition = new LayerDefinition('repository', new MembershipSpec(suffix: ['Repository']));

        self::assertFalse(
            $definition->matches(self::context('App\\Service\\RepositoryHelper'))->matched,
            'suffix matching is anchored to the right; "RepositoryHelper" must not match suffix "Repository".',
        );
    }

    #[Test]
    public function itOrsMultipleSuffixEntriesTogether(): void
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
    public function itMatchesAnAttributeByItsFqn(): void
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
    public function itReturnsNoMatchWhenTheClassHasNoAttributes(): void
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
    public function itMatchesATransitivelyImplementedInterface(): void
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
    public function itMatchesATransitiveParentClass(): void
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
    public function itCombinesCriteriaWithOrInAnyMode(): void
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
    public function itRecordsEveryMatchingCriterionInAnyMode(): void
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
    public function itRejectsAClassMissingOneCriterionInAllMode(): void
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
    public function itAcceptsAClassMatchingEveryDeclaredCriterionInAllMode(): void
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
    public function itTreatsUndeclaredCriteriaAsTriviallySatisfiedInAllMode(): void
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
    public function itRejectsAnEmptyName(): void
    {
        $this->expectException(InvalidLayerDefinitionException::class);
        new LayerDefinition('', new MembershipSpec(patterns: ['App\\Foo']));
    }

    #[DataProvider('invalidNameProvider')]
    #[Test]
    public function itRejectsAnInvalidName(string $invalidName): void
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
    public function itAcceptsAValidNameWithDigitsUnderscoreAndHyphen(): void
    {
        $definition = self::patternLayer('a1_b-c', ['App\\Foo']);

        self::assertSame('a1_b-c', $definition->name());
    }

    // -------------------------------------------------------------------------
    // Expansion-mode name validation (Phase 2 direction 2)
    // -------------------------------------------------------------------------

    #[Test]
    public function itAcceptsAPascalCaseNameInExpandedMode(): void
    {
        $definition = LayerDefinition::expanded(
            'domain-Order',
            new MembershipSpec(patterns: ['App\\Module\\Order\\Domain\\**']),
        );

        self::assertSame('domain-Order', $definition->name());
    }

    #[Test]
    public function itAcceptsALowercaseNameInExpandedMode(): void
    {
        $definition = LayerDefinition::expanded(
            'domain-order',
            new MembershipSpec(patterns: ['App\\Module\\order\\Domain\\**']),
        );

        self::assertSame('domain-order', $definition->name());
    }

    #[Test]
    public function itRejectsAnExpandedNameStartingWithADigit(): void
    {
        $this->expectException(InvalidLayerDefinitionException::class);

        LayerDefinition::expanded('1domain', new MembershipSpec(patterns: ['App\\Foo']));
    }

    #[Test]
    public function itRejectsAnExpandedNameContainingABackslash(): void
    {
        $this->expectException(InvalidLayerDefinitionException::class);

        LayerDefinition::expanded('domain\\Order', new MembershipSpec(patterns: ['App\\Foo']));
    }

    #[Test]
    public function itKeepsThePhase1RestrictionRejectingUppercaseInTheStrictConstructor(): void
    {
        $this->expectException(InvalidLayerDefinitionException::class);

        new LayerDefinition('Domain', new MembershipSpec(patterns: ['App\\Foo']));
    }

    // -------------------------------------------------------------------------
    // MembershipSpec invariants
    // -------------------------------------------------------------------------

    #[Test]
    public function itRejectsASpecWhereEveryCriterionListIsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at least one non-empty criterion list/');

        new MembershipSpec();
    }

    #[Test]
    public function itRejectsAnEmptyStringPatternEntry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/patterns\[1\] must not be empty/');

        new MembershipSpec(patterns: ['App\\Service', '']);
    }

    #[Test]
    public function itRejectsANonStringPatternEntryAtIndexZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/patterns\[0\] must be a string, int given/');

        /** @phpstan-ignore-next-line — deliberately violating the type contract to verify runtime guard */
        new MembershipSpec(patterns: [42]);
    }

    #[Test]
    public function itRejectsANonStringPatternEntryAtANonZeroIndex(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/patterns\[1\] must be a string, int given/');

        /** @phpstan-ignore-next-line — deliberately violating the type contract to verify runtime guard */
        new MembershipSpec(patterns: ['App\\Service', 99]);
    }

    #[Test]
    public function itRejectsANullPatternEntry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/patterns\[0\] must be a string, null given/');

        /** @phpstan-ignore-next-line — deliberately violating the type contract to verify runtime guard */
        new MembershipSpec(patterns: [null]);
    }

    #[Test]
    public function itRejectsAnArrayPatternEntry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/patterns\[0\] must be a string, array given/');

        /** @phpstan-ignore-next-line — deliberately violating the type contract to verify runtime guard */
        new MembershipSpec(patterns: [['App\\Service']]);
    }

    #[Test]
    public function itRejectsAnObjectPatternEntry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/patterns\[0\] must be a string, stdClass given/');

        /** @phpstan-ignore-next-line — deliberately violating the type contract to verify runtime guard */
        new MembershipSpec(patterns: [new stdClass()]);
    }

    #[Test]
    public function itDefaultsToAnyMatchMode(): void
    {
        $spec = new MembershipSpec(patterns: ['App\\Foo']);

        self::assertSame(MatchMode::Any, $spec->mode);
    }

    #[Test]
    public function itAcceptsAnExplicitAllMatchMode(): void
    {
        $spec = new MembershipSpec(patterns: ['App\\Foo'], mode: MatchMode::All);

        self::assertSame(MatchMode::All, $spec->mode);
    }

    #[Test]
    public function itAcceptsASuffixOnlySpec(): void
    {
        $spec = new MembershipSpec(suffix: ['Repository']);

        self::assertSame([], $spec->patterns);
        self::assertSame(['Repository'], $spec->suffix);
    }

    #[Test]
    public function itAcceptsAnAttributesOnlySpec(): void
    {
        $spec = new MembershipSpec(attributes: ['App\\Attr\\Entity']);

        self::assertSame(['App\\Attr\\Entity'], $spec->attributes);
    }

    #[Test]
    public function itAcceptsAnImplementsOnlySpec(): void
    {
        $spec = new MembershipSpec(implements: ['App\\Contracts\\Repository']);

        self::assertSame(['App\\Contracts\\Repository'], $spec->implements);
    }

    #[Test]
    public function itAcceptsAnExtendsOnlySpec(): void
    {
        $spec = new MembershipSpec(extends: ['App\\AbstractBase']);

        self::assertSame(['App\\AbstractBase'], $spec->extends);
    }

    #[Test]
    public function itCarriesTheCriterionListOnAMatchResult(): void
    {
        $criterion = new MatchedCriterion(MatchedCriterionKind::Pattern, 'App\\Service\\**');
        $result = MembershipResult::match([$criterion]);

        self::assertTrue($result->matched);
        self::assertSame([$criterion], $result->matchedCriteria);
    }

    #[Test]
    public function itRejectsAnEmptyCriteriaListOnTheMatchFactory(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at least one matched criterion/');

        MembershipResult::match([]);
    }

    /**
     * The third variant is a non-match for every membership consumer and
     * differs only in the one question `architecture.unmatched-exclude` asks.
     */
    #[Test]
    public function itReportsAnExcludedResultAsANonMatchThatKnowsItsCause(): void
    {
        $excluded = MembershipResult::excluded();

        self::assertFalse($excluded->matched);
        self::assertSame([], $excluded->matchedCriteria);
        self::assertTrue($excluded->isExcluded());

        self::assertFalse(MembershipResult::noMatch()->isExcluded());
        self::assertFalse(
            MembershipResult::match([new MatchedCriterion(MatchedCriterionKind::Pattern, 'App\\**')])->isExcluded(),
        );
    }

    /** The clause firing is what produces the excluded variant, not the factory alone. */
    #[Test]
    public function itDowngradesAMatchToTheExcludedVariantWhenTheClauseFires(): void
    {
        $definition = new LayerDefinition('service', new MembershipSpec(
            ['App\\Service\\**'],
            exclude: new ExcludeSpec(['App\\Service\\Legacy\\**']),
        ));

        $excluded = $definition->matches(new ClassContext('App\\Service\\Legacy\\OldService', 'OldService'));
        self::assertFalse($excluded->matched);
        self::assertTrue($excluded->isExcluded());

        $kept = $definition->matches(new ClassContext('App\\Service\\UserService', 'UserService'));
        self::assertTrue($kept->matched);
        self::assertFalse($kept->isExcluded());

        $never = $definition->matches(new ClassContext('Other\\Place\\Foo', 'Foo'));
        self::assertFalse($never->matched);
        self::assertFalse($never->isExcluded());
    }

    #[Test]
    public function itLeavesTheCriteriaListEmptyOnANoMatchResult(): void
    {
        $result = MembershipResult::noMatch();

        self::assertFalse($result->matched);
        self::assertSame([], $result->matchedCriteria);
    }

    #[Test]
    public function itDescribesItselfWithItsKindAndValue(): void
    {
        self::assertSame('pattern "App\\Service"', (new MatchedCriterion(MatchedCriterionKind::Pattern, 'App\\Service'))->describe());
        self::assertSame('suffix "Repository"', (new MatchedCriterion(MatchedCriterionKind::Suffix, 'Repository'))->describe());
        self::assertSame('attribute "App\\Attr"', (new MatchedCriterion(MatchedCriterionKind::Attribute, 'App\\Attr'))->describe());
    }

    #[Test]
    public function itRejectsAnEmptyCriterionValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MatchedCriterion(MatchedCriterionKind::Pattern, '');
    }

    #[Test]
    public function itExposesTheFullMetadataItWasGiven(): void
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
    public function itDefaultsToEmptyMetadataLists(): void
    {
        $context = new ClassContext('App\\Foo', 'Foo');

        self::assertSame([], $context->attributeFqns);
        self::assertSame([], $context->interfaces);
        self::assertSame([], $context->parentClasses);
    }

    #[Test]
    public function itPermitsAnEmptyFqnAndShortName(): void
    {
        $context = new ClassContext('', '');

        self::assertSame('', $context->fqn);
        self::assertSame('', $context->shortName);
    }

    // -------------------------------------------------------------------------
    // exclude clause (Step F)
    // -------------------------------------------------------------------------

    #[Test]
    public function itExcludesASubtreeMatchedByAPositivePattern(): void
    {
        $definition = new LayerDefinition(
            'service',
            new MembershipSpec(
                patterns: ['App\\Service\\**'],
                exclude: new ExcludeSpec(patterns: ['App\\Service\\Legacy\\**']),
            ),
        );

        self::assertTrue($definition->matches(self::context('App\\Service\\UserService'))->matched);
        self::assertFalse($definition->matches(self::context('App\\Service\\Legacy\\OldService'))->matched);
    }

    #[Test]
    public function itReturnsNoMatchedCriteriaWhenAnExclusionFires(): void
    {
        $definition = new LayerDefinition(
            'service',
            new MembershipSpec(
                patterns: ['App\\Service\\**'],
                exclude: new ExcludeSpec(patterns: ['App\\Service\\Legacy\\**']),
            ),
        );

        // Excluded class is indistinguishable from non-matching class at the
        // rule layer — no descriptor surfaces.
        $result = $definition->matches(self::context('App\\Service\\Legacy\\OldService'));
        self::assertSame([], $result->matchedCriteria);
    }

    #[Test]
    public function itExcludesByAShortNameSuffix(): void
    {
        $definition = new LayerDefinition(
            'repository',
            new MembershipSpec(
                suffix: ['Repository'],
                exclude: new ExcludeSpec(patterns: ['App\\Test\\**']),
            ),
        );

        self::assertTrue($definition->matches(self::context('App\\Repo\\UserRepository'))->matched);
        self::assertFalse($definition->matches(self::context('App\\Test\\UserRepository'))->matched);
    }

    #[Test]
    public function itExcludesByAnAttribute(): void
    {
        $definition = new LayerDefinition(
            'service',
            new MembershipSpec(
                patterns: ['App\\Service\\**'],
                exclude: new ExcludeSpec(attributes: ['App\\Attr\\Deprecated']),
            ),
        );

        $clean = new ClassContext('App\\Service\\UserService', 'UserService');
        $deprecated = new ClassContext(
            'App\\Service\\LegacyService',
            'LegacyService',
            attributeFqns: ['App\\Attr\\Deprecated'],
        );

        self::assertTrue($definition->matches($clean)->matched);
        self::assertFalse($definition->matches($deprecated)->matched);
    }

    #[Test]
    public function itExcludesByAnImplementedInterface(): void
    {
        $definition = new LayerDefinition(
            'service',
            new MembershipSpec(
                patterns: ['App\\Service\\**'],
                exclude: new ExcludeSpec(implements: ['App\\Marker\\LegacyAdapter']),
            ),
        );

        $clean = new ClassContext('App\\Service\\UserService', 'UserService');
        $legacy = new ClassContext(
            'App\\Service\\LegacyService',
            'LegacyService',
            interfaces: ['App\\Marker\\LegacyAdapter'],
        );

        self::assertTrue($definition->matches($clean)->matched);
        self::assertFalse($definition->matches($legacy)->matched);
    }

    #[Test]
    public function itExcludesByAParentClass(): void
    {
        $definition = new LayerDefinition(
            'service',
            new MembershipSpec(
                patterns: ['App\\Service\\**'],
                exclude: new ExcludeSpec(extends: ['App\\Service\\BaseLegacyService']),
            ),
        );

        $clean = new ClassContext('App\\Service\\UserService', 'UserService');
        $legacy = new ClassContext(
            'App\\Service\\OldUserService',
            'OldUserService',
            parentClasses: ['App\\Service\\BaseLegacyService'],
        );

        self::assertTrue($definition->matches($clean)->matched);
        self::assertFalse($definition->matches($legacy)->matched);
    }

    #[Test]
    public function itFiresTheExclusionOnTheFirstMatchingCriterionInAnyMode(): void
    {
        $definition = new LayerDefinition(
            'service',
            new MembershipSpec(
                patterns: ['App\\Service\\**'],
                exclude: new ExcludeSpec(
                    patterns: ['App\\Service\\Legacy\\**'],
                    suffix: ['Bridge'],
                    mode: MatchMode::Any,
                ),
            ),
        );

        // Matches exclude.patterns only.
        self::assertFalse($definition->matches(self::context('App\\Service\\Legacy\\Foo'))->matched);
        // Matches exclude.suffix only.
        self::assertFalse($definition->matches(self::context('App\\Service\\PaymentBridge'))->matched);
        // Matches neither — stays in the layer.
        self::assertTrue($definition->matches(self::context('App\\Service\\UserService'))->matched);
    }

    #[Test]
    public function itRequiresEveryDeclaredExclusionCriterionToFireInAllMode(): void
    {
        $definition = new LayerDefinition(
            'service',
            new MembershipSpec(
                patterns: ['App\\Service\\**'],
                exclude: new ExcludeSpec(
                    patterns: ['App\\Service\\Legacy\\**'],
                    suffix: ['Bridge'],
                    mode: MatchMode::All,
                ),
            ),
        );

        // In Legacy subtree AND ends with Bridge → excluded.
        self::assertFalse($definition->matches(self::context('App\\Service\\Legacy\\PaymentBridge'))->matched);
        // In Legacy subtree but not Bridge → stays in layer.
        self::assertTrue($definition->matches(self::context('App\\Service\\Legacy\\OldThing'))->matched);
        // Bridge but not in Legacy → stays in layer.
        self::assertTrue($definition->matches(self::context('App\\Service\\PaymentBridge'))->matched);
    }

    #[Test]
    public function itAppliesTheExclusionAsAHardFilterRegardlessOfThePositiveMode(): void
    {
        // Positive criteria require BOTH patterns and suffix to match; exclude
        // is then evaluated as a hard filter regardless of the positive mode.
        $definition = new LayerDefinition(
            'service',
            new MembershipSpec(
                patterns: ['App\\Service\\**'],
                suffix: ['Service'],
                mode: MatchMode::All,
                exclude: new ExcludeSpec(patterns: ['App\\Service\\Legacy\\**']),
            ),
        );

        self::assertTrue($definition->matches(self::context('App\\Service\\UserService'))->matched);
        self::assertFalse($definition->matches(self::context('App\\Service\\Legacy\\OldService'))->matched);
    }

    #[Test]
    public function itEvaluatesPositiveAllModeAndExcludeAllModeTogether(): void
    {
        // Both sides use MatchMode::All — exercises declaredKindCount on
        // both the positive and exclude branches in a single matches() call.
        $definition = new LayerDefinition(
            'service',
            new MembershipSpec(
                patterns: ['App\\Service\\**'],
                suffix: ['Service'],
                mode: MatchMode::All,
                exclude: new ExcludeSpec(
                    patterns: ['App\\Service\\Legacy\\**'],
                    suffix: ['Bridge'],
                    mode: MatchMode::All,
                ),
            ),
        );

        // Positive match: in App\Service\** AND ends with Service.
        // Excluded only if BOTH: in Legacy AND ends with Bridge.
        self::assertTrue($definition->matches(self::context('App\\Service\\UserService'))->matched);
        // In Legacy AND ends with Service (not Bridge) — kept; exclude does not fire.
        self::assertTrue($definition->matches(self::context('App\\Service\\Legacy\\PaymentService'))->matched);
        // In Legacy AND ends with Bridge — excluded.
        self::assertFalse($definition->matches(self::context('App\\Service\\Legacy\\PaymentBridge'))->matched);
        // Doesn't pass positive (no Service suffix) — exclude never even evaluated.
        self::assertFalse($definition->matches(self::context('App\\Service\\PaymentBridge'))->matched);
    }

    #[Test]
    public function itSkipsExclusionEvaluationWhenThePositiveCriteriaDidNotMatch(): void
    {
        // Class outside the positive patterns is non-matching regardless of
        // whether the exclude clause would also fire. NoMatch on positive
        // short-circuits — exclude evaluation never runs.
        $definition = new LayerDefinition(
            'service',
            new MembershipSpec(
                patterns: ['App\\Service\\**'],
                exclude: new ExcludeSpec(patterns: ['App\\Service\\Legacy\\**']),
            ),
        );

        $result = $definition->matches(self::context('App\\Controller\\UserController'));
        self::assertFalse($result->matched);
        self::assertSame([], $result->matchedCriteria);
    }

    #[Test]
    public function itKeepsUnchangedSemanticsWhenNoExcludeIsDeclared(): void
    {
        // Regression pin: omitting exclude must keep the descriptor list and
        // match outcome byte-for-byte identical to the pre-Step-F shape.
        $definition = new LayerDefinition('service', new MembershipSpec(patterns: ['App\\Service\\**']));

        $result = $definition->matches(self::context('App\\Service\\UserService'));
        self::assertTrue($result->matched);
        self::assertCount(1, $result->matchedCriteria);
        self::assertSame(MatchedCriterionKind::Pattern, $result->matchedCriteria[0]->kind);
        self::assertSame('App\\Service\\**', $result->matchedCriteria[0]->value);
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
