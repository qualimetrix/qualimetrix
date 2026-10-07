<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Unit\Domain\Layer;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\AttributeSite;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\ClassLikeDeclaration;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ExternalSupertypes;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ExternalSupertypeSourceInterface;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ClassContext\Ancestry;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ClassContext\ClassContext;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ClassContext\ClassContextFactory;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ClassContext\DeclarationRelationIndex;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ClassContext\DeclarationRelations;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ClassContext\ImplicitStringability;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ClassContext\KnownTypes;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ClassContext\NameSpellingIndex;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\ClassType;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(ClassContextFactory::class)]
#[CoversClass(ClassContext::class)]
#[CoversClass(DeclarationRelationIndex::class)]
#[CoversClass(DeclarationRelations::class)]
#[CoversClass(Ancestry::class)]
#[CoversClass(ImplicitStringability::class)]
#[CoversClass(KnownTypes::class)]
#[CoversClass(NameSpellingIndex::class)]
final class ClassContextFactoryTest extends TestCase
{
    private const bool INTERFACE_EXTENDS = true;

    #[Test]
    public function itBuildsAMinimalContextWithoutABoundGraph(): void
    {
        $factory = new ClassContextFactory();

        $context = $factory->build(SymbolPath::forClass('App\\Service', 'UserService'));

        self::assertSame('App\\Service\\UserService', $context->fqn);
        self::assertSame('UserService', $context->shortName);
        self::assertSame([], $context->attributeFqns);
        self::assertSame([], $context->interfaces);
        self::assertSame([], $context->parentClasses);
        self::assertFalse(
            $context->graphBacked,
            'Three empty lists with no graph behind them are the absence of an answer, not one.',
        );
    }

    #[Test]
    public function itBuildsAMinimalContextForANamespacePathEvenWithABoundGraph(): void
    {
        $factory = new ClassContextFactory();
        $factory->bindGraph(self::graphWith([]));

        $context = $factory->build(SymbolPath::forNamespace('App\\Service'));

        self::assertSame('App\\Service', $context->fqn);
        self::assertSame('Service', $context->shortName);
        self::assertSame([], $context->attributeFqns);
        self::assertSame([], $context->interfaces);
        self::assertSame([], $context->parentClasses);
        self::assertTrue(
            $context->graphBacked,
            'A namespace has no attributes, interfaces or parents — with a graph bound that is an answer, '
            . 'and a graph-backed criterion asked about it must get a non-match rather than a refusal.',
        );
    }

    #[Test]
    public function itKeepsAClassAndTheNamespaceOfItsOwnNameApart(): void
    {
        // Both resolve to the same FQN. Whichever was asked for first used to
        // answer for the other, and observation now warms the cache before any
        // runtime lookup, so it would always be the class that lost.
        $command = SymbolPath::forClass('App\\Console', 'Command');
        $base = SymbolPath::forClass('App\\Console', 'Base');

        $factory = new ClassContextFactory();
        $factory->bindGraph(self::graphWith([[$command, $base, DependencyType::Extends]]));

        $namespaceContext = $factory->build(SymbolPath::forNamespace('App\\Console\\Command'));
        self::assertSame([], $namespaceContext->parentClasses);

        $classContext = $factory->build($command);
        self::assertSame(['App\\Console\\Base'], $classContext->parentClasses);
    }

    #[Test]
    public function itCollectsDirectAttributesInterfacesAndParentFromTheGraph(): void
    {
        $userService = SymbolPath::forClass('App\\Service', 'UserService');
        $abstractService = SymbolPath::forClass('App\\Service', 'AbstractService');
        $entityAttr = SymbolPath::forClass('App\\Attr', 'Entity');
        $serviceInterface = SymbolPath::forClass('App\\Contracts', 'Service');

        $factory = new ClassContextFactory();
        $factory->bindGraph(self::graphWith([
            [$userService, $abstractService, DependencyType::Extends],
            [$userService, $serviceInterface, DependencyType::Implements],
            [$userService, $entityAttr, DependencyType::Attribute],
        ]));

        $context = $factory->build($userService);

        self::assertSame(['App\\Attr\\Entity'], $context->attributeFqns);
        self::assertSame(['App\\Contracts\\Service'], $context->interfaces);
        self::assertSame(['App\\Service\\AbstractService'], $context->parentClasses);
    }

    #[Test]
    public function itWalksTheTransitiveParentChain(): void
    {
        $derived = SymbolPath::forClass('App\\Domain', 'User');
        $base = SymbolPath::forClass('App\\Domain', 'AbstractUser');
        $root = SymbolPath::forClass('App\\Domain', 'AggregateRoot');

        $factory = new ClassContextFactory();
        $factory->bindGraph(self::graphWith([
            [$derived, $base, DependencyType::Extends],
            [$base, $root, DependencyType::Extends],
        ]));

        $context = $factory->build($derived);

        self::assertSame(
            ['App\\Domain\\AbstractUser', 'App\\Domain\\AggregateRoot'],
            $context->parentClasses,
        );
    }

    #[Test]
    public function itIncludesInterfacesInheritedFromAParentClass(): void
    {
        $derived = SymbolPath::forClass('App\\Domain', 'User');
        $base = SymbolPath::forClass('App\\Domain', 'AbstractUser');
        $iface = SymbolPath::forClass('App\\Contracts', 'AggregateRoot');

        $factory = new ClassContextFactory();
        $factory->bindGraph(self::graphWith([
            [$derived, $base, DependencyType::Extends],
            [$base, $iface, DependencyType::Implements],
        ]));

        $context = $factory->build($derived);

        self::assertSame(['App\\Contracts\\AggregateRoot'], $context->interfaces);
    }

    #[Test]
    public function itWalksTransitiveInterfaceExtension(): void
    {
        // Test scenario from plan: class implements Sub; Sub extends Base.
        $klass = SymbolPath::forClass('App\\Repo', 'UserRepository');
        $sub = SymbolPath::forClass('App\\Repo', 'UserRepositoryInterface');
        $base = SymbolPath::forClass('Doctrine\\Persistence', 'ObjectRepository');

        $factory = new ClassContextFactory();
        $factory->bindGraph(self::graphWith([
            [$klass, $sub, DependencyType::Implements],
            [$sub, $base, DependencyType::Extends],
        ]));

        $context = $factory->build($klass);

        self::assertContains('App\\Repo\\UserRepositoryInterface', $context->interfaces);
        self::assertContains('Doctrine\\Persistence\\ObjectRepository', $context->interfaces);
    }

    #[Test]
    public function itCountsTheInterfacesAnInterfaceExtendsAmongItsInterfaces(): void
    {
        // `getInterfaceNames()` semantics, which PHP's own interfaces already
        // get from the builtin table: `\OuterIterator` answers "yes" to
        // `implements: ['\Iterator']`, so an analysed interface extending
        // `\IteratorAggregate` must too, and not only for what is above it.
        $bag = SymbolPath::forClass('App\\Library', 'Bag');
        $child = SymbolPath::forClass('App\\Library', 'SortedBag');

        $factory = new ClassContextFactory();
        $factory->bindGraph(self::graphWith([
            [$bag, SymbolPath::fromClassFqn('IteratorAggregate'), DependencyType::Extends, self::INTERFACE_EXTENDS],
            [$child, $bag, DependencyType::Extends, self::INTERFACE_EXTENDS],
        ]), [$bag, $child]);

        self::assertSame(['IteratorAggregate', 'Traversable'], $factory->build($bag)->interfaces);
        self::assertSame(['App\\Library\\Bag', 'IteratorAggregate', 'Traversable'], $factory->build($child)->interfaces);
    }

    #[Test]
    public function itCountsEveryInterfaceUpAChainTheProjectWrites(): void
    {
        $leaf = SymbolPath::forClass('App\\Library', 'Leaf');
        $mid = SymbolPath::forClass('App\\Library', 'Mid');
        $root = SymbolPath::forClass('App\\Library', 'Root');

        $factory = new ClassContextFactory();
        $factory->bindGraph(self::graphWith([
            [$leaf, $mid, DependencyType::Extends, self::INTERFACE_EXTENDS],
            [$mid, $root, DependencyType::Extends, self::INTERFACE_EXTENDS],
        ]), [$leaf, $mid, $root]);

        $context = $factory->build($leaf);

        self::assertSame(['App\\Library\\Mid', 'App\\Library\\Root'], $context->interfaces);
        self::assertSame(['App\\Library\\Mid', 'App\\Library\\Root'], $context->parentClasses);
        self::assertTrue($context->interfacesKnown());
        // An interface extending nothing has nothing to add.
        self::assertSame([], $factory->build($root)->interfaces);
    }

    #[Test]
    public function itKeepsTheParentsOfAClassOutOfItsInterfaces(): void
    {
        // The neighbour the interface seeding must not swallow: a parent class
        // is not an interface its subclass implements, so `implements:
        // [SomeClass]` stays off the subclasses — a PHP parent class included.
        $sub = SymbolPath::forClass('App\\Domain', 'Sub');
        $base = SymbolPath::forClass('App\\Domain', 'Base');
        $root = SymbolPath::forClass('App\\Domain', 'Root');
        $failure = SymbolPath::forClass('App\\Domain', 'Failure');

        $factory = new ClassContextFactory();
        $factory->bindGraph(self::graphWith([
            [$sub, $base, DependencyType::Extends],
            [$base, $root, DependencyType::Extends],
            [$failure, SymbolPath::fromClassFqn('RuntimeException'), DependencyType::Extends],
        ]), [$sub, $base, $root, $failure]);

        self::assertSame([], $factory->build($sub)->interfaces);
        self::assertSame(['App\\Domain\\Base', 'App\\Domain\\Root'], $factory->build($sub)->parentClasses);
        self::assertNotContains('RuntimeException', $factory->build($failure)->interfaces);
        self::assertNotContains('Exception', $factory->build($failure)->interfaces);
        self::assertContains('Throwable', $factory->build($failure)->interfaces);
    }

    #[Test]
    public function itCountsAnUnreadInterfaceAnInterfaceExtendsAndSaysWhereItStopped(): void
    {
        // The edge was recorded from the analysed side, so the direct vendor
        // parent is known; what it extends in turn is not.
        $port = SymbolPath::forClass('App\\Library', 'Port');

        $factory = new ClassContextFactory();
        $factory->bindGraph(self::graphWith([
            [$port, SymbolPath::forClass('Vendor\\Contract', 'Thing'), DependencyType::Extends, self::INTERFACE_EXTENDS],
        ]), [$port]);

        $context = $factory->build($port);

        self::assertSame(['Vendor\\Contract\\Thing'], $context->interfaces);
        self::assertSame(
            ['parentChain' => ['Vendor\\Contract\\Thing'], 'interfaces' => ['Vendor\\Contract\\Thing']],
            $context->ancestryCuts,
        );
        self::assertFalse($context->interfacesKnown());
    }

    #[Test]
    public function itDeduplicatesRepeatedAttributeAndRelationEdges(): void
    {
        // Same target referenced through multiple edges (e.g. two #[Attr]
        // occurrences at different lines) collapses into a single entry.
        $klass = SymbolPath::forClass('App\\Domain', 'User');
        $attr = SymbolPath::forClass('App\\Attr', 'Audit');

        $factory = new ClassContextFactory();
        $factory->bindGraph(self::graphWith([
            [$klass, $attr, DependencyType::Attribute],
            [$klass, $attr, DependencyType::Attribute],
        ]));

        self::assertSame(['App\\Attr\\Audit'], $factory->build($klass)->attributeFqns);
    }

    #[Test]
    public function itKeepsClassAndDeclaredMemberAttributesInSeparateContexts(): void
    {
        $klass = SymbolPath::forClass('App\\Domain', 'User');

        $factory = new ClassContextFactory();
        $factory->bindGraph(self::graphWith([
            [$klass, SymbolPath::forClass('App\\Attr', 'Entity'), DependencyType::Attribute, false, AttributeSite::ClassHeader],
            [$klass, SymbolPath::forClass('App\\Attr', 'Route'), DependencyType::Attribute, false, AttributeSite::Method],
            [$klass, SymbolPath::forClass('App\\Attr', 'NestedRoute'), DependencyType::Attribute, false, AttributeSite::Method, true],
            [$klass, SymbolPath::forClass('App\\Attr', 'ClosureRoute'), DependencyType::Attribute, false, AttributeSite::NestedCallable],
        ]));

        $context = $factory->build($klass);

        self::assertSame(['App\\Attr\\Entity'], $context->attributeFqns);
        self::assertSame(['App\\Attr\\Route'], $context->memberAttributeFqns);
    }

    #[Test]
    public function itResetsCachedContextsWhenTheGraphIsRebound(): void
    {
        $klass = SymbolPath::forClass('App\\Domain', 'User');
        $parentA = SymbolPath::forClass('App\\Domain', 'BaseA');
        $parentB = SymbolPath::forClass('App\\Domain', 'BaseB');

        $factory = new ClassContextFactory();

        $factory->bindGraph(self::graphWith([[$klass, $parentA, DependencyType::Extends]]));
        self::assertSame(['App\\Domain\\BaseA'], $factory->build($klass)->parentClasses);

        $factory->bindGraph(self::graphWith([[$klass, $parentB, DependencyType::Extends]]));
        self::assertSame(['App\\Domain\\BaseB'], $factory->build($klass)->parentClasses);
    }

    #[Test]
    public function itSwitchesBackToAMinimalContextWhenTheGraphIsUnbound(): void
    {
        $klass = SymbolPath::forClass('App\\Domain', 'User');
        $parent = SymbolPath::forClass('App\\Domain', 'Base');

        $factory = new ClassContextFactory();
        $factory->bindGraph(self::graphWith([[$klass, $parent, DependencyType::Extends]]));
        self::assertNotSame([], $factory->build($klass)->parentClasses);

        $factory->bindGraph(null);
        self::assertSame([], $factory->build($klass)->parentClasses);
    }

    #[Test]
    public function itMemoizesTheBuiltContextAcrossRepeatedCalls(): void
    {
        $klass = SymbolPath::forClass('App\\Domain', 'User');
        $parent = SymbolPath::forClass('App\\Domain', 'Base');

        $factory = new ClassContextFactory();
        $factory->bindGraph(self::graphWith([[$klass, $parent, DependencyType::Extends]]));

        $first = $factory->build($klass);
        $second = $factory->build($klass);

        self::assertSame($first, $second, 'Repeated build() calls for the same FQN must hand back the same instance.');
    }

    #[Test]
    public function itIgnoresDependencyTypesOtherThanExtendsImplementsAndAttribute(): void
    {
        // Only Extends/Implements/Attribute should feed ClassContext. Other
        // dependency kinds (TypeHint, New_, etc.) must be ignored.
        $klass = SymbolPath::forClass('App', 'A');
        $other = SymbolPath::forClass('App', 'B');

        $factory = new ClassContextFactory();
        $factory->bindGraph(self::graphWith([
            [$klass, $other, DependencyType::TypeHint],
            [$klass, $other, DependencyType::New_],
            [$klass, $other, DependencyType::StaticCall],
        ]));

        $context = $factory->build($klass);
        self::assertSame([], $context->attributeFqns);
        self::assertSame([], $context->interfaces);
        self::assertSame([], $context->parentClasses);
    }

    #[Test]
    public function itDerivesImplicitStringableFromDeclarationsTraitsParentsAndInterfaces(): void
    {
        $string = SymbolPath::forClass('App', 'Str');
        $abstractString = SymbolPath::forClass('App', 'AbsStr');
        $stringInterface = SymbolPath::forClass('App', 'StrIface');
        $implementsStringInterface = SymbolPath::forClass('App', 'ImplStrIface');
        $stringTrait = SymbolPath::forClass('App', 'TraitStr');
        $deepStringTrait = SymbolPath::forClass('App', 'DeepTraitStr');
        $usesStringTrait = SymbolPath::forClass('App', 'UsesTraitStr');
        $usesDeepStringTrait = SymbolPath::forClass('App', 'UsesDeepTraitStr');
        $childOfString = SymbolPath::forClass('App', 'ChildOfStr');
        $parentUsingTrait = SymbolPath::forClass('App', 'ParentUsingTrait');
        $childOfTraitString = SymbolPath::forClass('App', 'ChildOfTraitStr');

        $graph = self::graphWith([
            [$implementsStringInterface, $stringInterface, DependencyType::Implements],
            [$usesStringTrait, $stringTrait, DependencyType::TraitUse],
            [$deepStringTrait, $stringTrait, DependencyType::TraitUse],
            [$usesDeepStringTrait, $deepStringTrait, DependencyType::TraitUse],
            [$childOfString, $string, DependencyType::Extends],
            [$parentUsingTrait, $stringTrait, DependencyType::TraitUse],
            [$childOfTraitString, $parentUsingTrait, DependencyType::Extends],
        ], [
            self::declaration($string, ClassType::Class_, true),
            self::declaration($abstractString, ClassType::Class_, true),
            self::declaration($stringInterface, ClassType::Interface_, true),
            self::declaration($implementsStringInterface, ClassType::Class_),
            self::declaration($stringTrait, ClassType::Trait_, true),
            self::declaration($deepStringTrait, ClassType::Trait_),
            self::declaration($usesStringTrait, ClassType::Class_),
            self::declaration($usesDeepStringTrait, ClassType::Class_),
            self::declaration($childOfString, ClassType::Class_),
            self::declaration($parentUsingTrait, ClassType::Class_),
            self::declaration($childOfTraitString, ClassType::Class_),
        ]);

        $factory = new ClassContextFactory();
        $factory->bindGraph($graph, [
            $string,
            $abstractString,
            $stringInterface,
            $implementsStringInterface,
            $stringTrait,
            $deepStringTrait,
            $usesStringTrait,
            $usesDeepStringTrait,
            $childOfString,
            $parentUsingTrait,
            $childOfTraitString,
        ]);

        foreach ([$string, $abstractString, $implementsStringInterface, $usesStringTrait, $usesDeepStringTrait, $childOfString, $childOfTraitString] as $subject) {
            self::assertContains('Stringable', $factory->build($subject)->interfaces, $subject->toString());
        }
        self::assertContains('Stringable', $factory->build($stringInterface)->interfaces);
        self::assertContains('Stringable', $factory->build($stringInterface)->parentClasses);
        self::assertNotContains('Stringable', $factory->build($stringTrait)->interfaces);
        self::assertNotContains('Stringable', $factory->build($deepStringTrait)->interfaces);
    }

    #[Test]
    public function itKeepsOneObservedSpellingWithoutMakingWrongCaseCriteriaKnown(): void
    {
        $subject = SymbolPath::forClass('App', 'Subject');
        $target = SymbolPath::forClass('Vendor', 'Contract');
        $graph = self::graphWith(
            [[$subject, $target, DependencyType::Implements]],
            [self::declaration($subject, ClassType::Class_)],
        );
        $index = new NameSpellingIndex($graph, [$subject]);
        $factory = new ClassContextFactory();
        $factory->bindGraph($graph, [$subject]);

        self::assertSame('Vendor\\Contract', $index->spellingOf('vendor\\contract'));
        self::assertTrue($factory->knownTypes()->met('Vendor\\Contract'));
        self::assertFalse($factory->knownTypes()->met('vendor\\contract'));
    }

    #[Test]
    public function itWalksExternalParentInterfaceAndTraitChains(): void
    {
        $subject = SymbolPath::forClass('App', 'Subject');
        $base = SymbolPath::forClass('Vendor', 'Base');
        $factory = new ClassContextFactory();
        $factory->bindExternalSupertypeSource(self::externalSource([
            'Vendor\\Base' => self::externalFacts(
                'Vendor\\Base',
                ClassType::Class_,
                parent: 'Vendor\\Root',
                interfaces: ['Vendor\\Readable'],
                traits: ['Vendor\\StringTrait'],
            ),
            'Vendor\\Root' => self::externalFacts('Vendor\\Root', ClassType::Class_),
            'Vendor\\Readable' => self::externalFacts('Vendor\\Readable', ClassType::Interface_, interfaces: ['Vendor\\BaseContract']),
            'Vendor\\BaseContract' => self::externalFacts('Vendor\\BaseContract', ClassType::Interface_),
            'Vendor\\StringTrait' => self::externalFacts('Vendor\\StringTrait', ClassType::Trait_, declaresToString: true),
        ]));
        $factory->bindGraph(self::graphWith(
            [[$subject, $base, DependencyType::Extends]],
            [self::declaration($subject, ClassType::Class_)],
        ), [$subject]);
        self::assertTrue($factory->knownTypes()->met('Vendor\\Readable'));

        $context = $factory->build($subject);

        self::assertSame(['Vendor\\Base', 'Vendor\\Root'], $context->parentClasses);
        self::assertContains('Vendor\\Readable', $context->interfaces);
        self::assertContains('Vendor\\BaseContract', $context->interfaces);
        self::assertContains('Stringable', $context->interfaces);
        self::assertTrue($context->parentChainKnown());
        self::assertTrue($context->interfacesKnown());
    }

    #[Test]
    public function itKeepsUnreadableAndUnmappedExternalLinksAsDoubts(): void
    {
        $subject = SymbolPath::forClass('App', 'Subject');
        $factory = new ClassContextFactory();
        $factory->bindExternalSupertypeSource(self::externalSource([
            'Vendor\\Unreadable' => ExternalSupertypes::unreadable('test parse failure'),
        ]));
        $factory->bindGraph(self::graphWith([
            [$subject, SymbolPath::fromClassFqn('Vendor\\Unreadable'), DependencyType::Extends],
            [$subject, SymbolPath::fromClassFqn('Vendor\\Unmapped'), DependencyType::Implements],
        ], [self::declaration($subject, ClassType::Class_)]), [$subject]);

        $context = $factory->build($subject);

        self::assertContains('Vendor\\Unreadable', $context->ancestryCuts['parentChain']);
        self::assertContains('Vendor\\Unmapped', $context->ancestryCuts['interfaces']);
        self::assertFalse($context->parentChainKnown());
        self::assertFalse($context->interfacesKnown());
    }

    #[Test]
    public function itKeepsATraitAliasToToStringAsADoubtWithoutPositiveEvidence(): void
    {
        $subject = SymbolPath::forClass('App', 'Subject');
        $trait = SymbolPath::forClass('App', 'AliasTrait');
        $factory = new ClassContextFactory();
        $factory->bindGraph(self::graphWith(
            [[$subject, $trait, DependencyType::TraitUse]],
            [
                self::declaration($subject, ClassType::Class_),
                self::declaration($trait, ClassType::Trait_, aliasesTraitMethodAsToString: true),
            ],
        ), [$subject, $trait]);

        $context = $factory->build($subject);

        self::assertNotContains('Stringable', $context->interfaces);
        self::assertTrue($context->interfacesKnown(), 'A trait alias affects only implicit Stringable, not the generic interface closure.');
        self::assertFalse($context->implicitStringableKnown);

        $stringable = new \Qualimetrix\Analysis\Policy\Architecture\Layer\LayerDefinition(
            'stringable',
            new \Qualimetrix\Analysis\Policy\Architecture\Layer\MembershipSpec(implements: ['Stringable']),
        );
        $unrelated = new \Qualimetrix\Analysis\Policy\Architecture\Layer\LayerDefinition(
            'unrelated',
            new \Qualimetrix\Analysis\Policy\Architecture\Layer\MembershipSpec(implements: ['Vendor\\Other']),
        );
        self::assertTrue($stringable->matches($context)->undecided);
        self::assertFalse($unrelated->matches($context)->undecided);
    }

    #[Test]
    public function itDoesNotTreatACompleteBuiltinParentAsAnImplicitStringableDoubt(): void
    {
        $subject = SymbolPath::forClass('App', 'Collection');
        $factory = new ClassContextFactory();
        $factory->bindGraph(self::graphWith(
            [[$subject, SymbolPath::fromClassFqn('ArrayObject'), DependencyType::Extends]],
            [self::declaration($subject, ClassType::Class_)],
        ), [$subject]);

        $context = $factory->build($subject);
        $stringable = new \Qualimetrix\Analysis\Policy\Architecture\Layer\LayerDefinition(
            'stringable',
            new \Qualimetrix\Analysis\Policy\Architecture\Layer\MembershipSpec(implements: ['Stringable']),
        );

        self::assertTrue($context->implicitStringableKnown);
        self::assertFalse($stringable->matches($context)->matched);
        self::assertFalse($stringable->matches($context)->undecided);
    }

    #[Test]
    public function itPlacesImplicitStringableByTheExternalDeclarationKind(): void
    {
        $factory = new ClassContextFactory();
        $factory->bindExternalSupertypeSource(self::externalSource([
            'Vendor\\StringClass' => self::externalFacts('Vendor\\StringClass', ClassType::Class_, declaresToString: true),
            'Vendor\\StringInterface' => self::externalFacts('Vendor\\StringInterface', ClassType::Interface_, declaresToString: true),
        ]));
        $factory->bindGraph(self::graphWith([]), []);

        $class = $factory->build(SymbolPath::fromClassFqn('Vendor\\StringClass'));
        $interface = $factory->build(SymbolPath::fromClassFqn('Vendor\\StringInterface'));

        self::assertSame([], $class->parentClasses);
        self::assertContains('Stringable', $class->interfaces);
        self::assertContains('Stringable', $interface->parentClasses);
        self::assertContains('Stringable', $interface->interfaces);
    }

    #[Test]
    public function itDoesNotTreatReadableExternalSupertypesAsAnAnalysedDeclarationHeader(): void
    {
        $factory = new ClassContextFactory();
        $factory->bindExternalSupertypeSource(self::externalSource([
            'Vendor\\Base' => self::externalFacts('Vendor\\Base', ClassType::Class_, parent: 'Vendor\\Root'),
            'Vendor\\Root' => self::externalFacts('Vendor\\Root', ClassType::Class_),
        ]));
        $factory->bindGraph(self::graphWith([]), []);

        $context = $factory->build(SymbolPath::fromClassFqn('Vendor\\Base'));
        $classAttribute = new \Qualimetrix\Analysis\Policy\Architecture\Layer\LayerDefinition(
            'class-attribute',
            new \Qualimetrix\Analysis\Policy\Architecture\Layer\MembershipSpec(attributes: ['Vendor\\Entity']),
        );
        $memberAttribute = new \Qualimetrix\Analysis\Policy\Architecture\Layer\LayerDefinition(
            'member-attribute',
            new \Qualimetrix\Analysis\Policy\Architecture\Layer\MembershipSpec(memberAttributes: ['Vendor\\Route']),
        );

        self::assertFalse($context->declarationAnalysed);
        self::assertTrue($context->parentChainKnown());
        self::assertTrue($classAttribute->matches($context)->undecided);
        self::assertTrue($memberAttribute->matches($context)->undecided);
    }

    #[Test]
    public function itCutsEachExternalInheritanceBranchAtTheDepthLimit(): void
    {
        $parentCalls = 0;
        $parentFactory = new ClassContextFactory();
        $parentFactory->bindExternalSupertypeSource(self::deepExternalSource(
            'Parent',
            ClassType::Class_,
            static function () use (&$parentCalls): void {
                ++$parentCalls;
            },
        ));
        $parentFactory->bindGraph(self::graphWith([]), []);

        $parentContext = $parentFactory->build(SymbolPath::fromClassFqn('Vendor\\Parent000'));

        self::assertCount(256, $parentContext->parentClasses);
        self::assertSame('Vendor\\Parent256', $parentContext->parentClasses[255]);
        self::assertSame(['Vendor\\Parent257'], $parentContext->ancestryCuts['parentChain']);
        self::assertFalse($parentContext->parentChainKnown());
        self::assertSame(257, $parentCalls);

        $interfaceCalls = 0;
        $interfaceFactory = new ClassContextFactory();
        $interfaceFactory->bindExternalSupertypeSource(self::deepExternalSource(
            'Interface',
            ClassType::Interface_,
            static function () use (&$interfaceCalls): void {
                ++$interfaceCalls;
            },
        ));
        $interfaceFactory->bindGraph(self::graphWith([]), []);

        $interfaceContext = $interfaceFactory->build(SymbolPath::fromClassFqn('Vendor\\Interface000'));

        self::assertCount(256, $interfaceContext->parentClasses);
        self::assertSame('Vendor\\Interface256', $interfaceContext->parentClasses[255]);
        self::assertSame(['Vendor\\Interface257'], $interfaceContext->ancestryCuts['parentChain']);
        self::assertFalse($interfaceContext->interfacesKnown());
        self::assertSame(257, $interfaceCalls);
    }

    #[Test]
    public function itDropsExternalFactsWhenANewRunIsBound(): void
    {
        $source = new class implements ExternalSupertypeSourceInterface {
            public string $parent = 'Vendor\\First';

            public function isConfigured(): bool
            {
                return true;
            }

            public function supertypesOf(string $fqcn): ExternalSupertypes
            {
                return self::external($fqcn, $fqcn === 'Vendor\\Base' ? $this->parent : null);
            }

            private static function external(string $spelling, ?string $parent): ExternalSupertypes
            {
                return new ExternalSupertypes(true, $spelling, ClassType::Class_, $parent, [], [], false, false, null);
            }
        };
        $subject = SymbolPath::forClass('App', 'Subject');
        $graph = self::graphWith(
            [[$subject, SymbolPath::fromClassFqn('Vendor\\Base'), DependencyType::Extends]],
            [self::declaration($subject, ClassType::Class_)],
        );
        $factory = new ClassContextFactory();
        $factory->bindExternalSupertypeSource($source);
        $factory->bindGraph($graph, [$subject]);
        self::assertSame(['Vendor\\Base', 'Vendor\\First'], $factory->build($subject)->parentClasses);

        $source->parent = 'Vendor\\Second';
        $factory->bindExternalSupertypeSource($source);
        $factory->bindGraph($graph, [$subject]);

        self::assertSame(['Vendor\\Base', 'Vendor\\Second'], $factory->build($subject)->parentClasses);
    }

    /**
     * The optional fourth element of an edge marks an `Extends` edge an
     * interface declares.
     *
     * @param list<array{0: SymbolPath, 1: SymbolPath, 2: DependencyType, 3?: bool, 4?: AttributeSite, 5?: bool}> $edges
     * @param list<ClassLikeDeclaration> $declarations
     */
    private static function graphWith(array $edges, array $declarations = []): DependencyGraphInterface
    {
        $deps = [];
        foreach ($edges as $edge) {
            [$source, $target, $type] = $edge;
            $declaration = DeclarationPath::of($source, RelativePath::fromString('test.php'), DeclarationOrdinal::fromRank(0));
            $deps[] = $type === DependencyType::Attribute
                ? Dependency::ofAttribute(
                    $declaration,
                    new LogicalClassPath($target),
                    Location::none(),
                    $edge[4] ?? AttributeSite::ClassHeader,
                    $edge[5] ?? false,
                )
                : Dependency::ofClassLike(
                    $declaration,
                    new LogicalClassPath($target),
                    $type,
                    Location::none(),
                    false,
                    $edge[3] ?? false,
                );
        }

        return new readonly class ($deps, $declarations) implements DependencyGraphInterface {
            /**
             * @param list<Dependency> $deps
             * @param list<ClassLikeDeclaration> $declarations
             */
            public function __construct(private array $deps, private array $declarations) {}

            public function getClassDependencies(SymbolPath $class): array
            {
                return [];
            }

            public function getClassDependents(SymbolPath $class): array
            {
                return [];
            }

            public function getClassCe(SymbolPath $class): int
            {
                return 0;
            }

            public function getClassCa(SymbolPath $class): int
            {
                return 0;
            }

            public function getNamespaceCe(SymbolPath $namespace): int
            {
                return 0;
            }

            public function getNamespaceCa(SymbolPath $namespace): int
            {
                return 0;
            }

            public function getNamespaceOwnCe(SymbolPath $namespace): int
            {
                return 0;
            }

            public function getNamespaceOwnCa(SymbolPath $namespace): int
            {
                return 0;
            }

            public function getAllClasses(): array
            {
                return [];
            }

            public function getAllNamespaces(): array
            {
                return [];
            }

            public function getAllDependencies(): array
            {
                return $this->deps;
            }

            public function getDeclarationDependencies(): array
            {
                return $this->deps;
            }

            public function getClassLikeDeclarations(): array
            {
                return $this->declarations;
            }
        };
    }

    private static function declaration(
        SymbolPath $symbol,
        ClassType $type,
        bool $declaresToString = false,
        bool $aliasesTraitMethodAsToString = false,
    ): ClassLikeDeclaration {
        return ClassLikeDeclaration::of(
            DeclarationPath::of($symbol, RelativePath::fromString('test.php'), DeclarationOrdinal::fromRank(0)),
            $type,
            $declaresToString,
            $aliasesTraitMethodAsToString,
        );
    }

    /** @param array<string, ExternalSupertypes> $facts */
    private static function externalSource(array $facts): ExternalSupertypeSourceInterface
    {
        return new readonly class ($facts) implements ExternalSupertypeSourceInterface {
            /** @param array<string, ExternalSupertypes> $facts */
            public function __construct(private array $facts) {}

            public function isConfigured(): bool
            {
                return true;
            }

            public function supertypesOf(string $fqcn): ExternalSupertypes
            {
                return $this->facts[$fqcn] ?? ExternalSupertypes::notPlaced();
            }
        };
    }

    /**
     * @param list<string> $interfaces
     * @param list<string> $traits
     */
    private static function externalFacts(
        string $spelling,
        ClassType $type,
        ?string $parent = null,
        array $interfaces = [],
        array $traits = [],
        bool $declaresToString = false,
    ): ExternalSupertypes {
        return new ExternalSupertypes(
            true,
            $spelling,
            $type,
            $parent,
            $interfaces,
            $traits,
            $declaresToString,
            false,
            null,
        );
    }

    private static function deepExternalSource(
        string $prefix,
        ClassType $type,
        Closure $recordRead,
    ): ExternalSupertypeSourceInterface {
        return new readonly class ($prefix, $type, $recordRead) implements ExternalSupertypeSourceInterface {
            public function __construct(
                private string $prefix,
                private ClassType $type,
                private Closure $recordRead,
            ) {}

            public function isConfigured(): bool
            {
                return true;
            }

            public function supertypesOf(string $fqcn): ExternalSupertypes
            {
                ($this->recordRead)();
                if (preg_match('/^Vendor\\\\' . $this->prefix . '(\\d{3})$/D', $fqcn, $match) !== 1) {
                    return ExternalSupertypes::notPlaced();
                }

                $index = (int) $match[1];
                $next = $index < 300 ? \sprintf('Vendor\\%s%03d', $this->prefix, $index + 1) : null;

                return new ExternalSupertypes(
                    true,
                    $fqcn,
                    $this->type,
                    $this->type === ClassType::Class_ ? $next : null,
                    $this->type === ClassType::Interface_ && $next !== null ? [$next] : [],
                    [],
                    false,
                    false,
                    null,
                );
            }
        };
    }
}
