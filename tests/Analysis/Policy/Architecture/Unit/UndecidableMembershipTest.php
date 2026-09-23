<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\Dependency;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;
use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyType;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ClassContextFactory;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ClassSet;
use Qualimetrix\Analysis\Policy\Architecture\Layer\CriteriaEvaluation;
use Qualimetrix\Analysis\Policy\Architecture\Layer\CriterionOutcome;
use Qualimetrix\Analysis\Policy\Architecture\Layer\ExcludeSpec;
use Qualimetrix\Analysis\Policy\Architecture\Layer\Expansion\TupleExtractor;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerCriteriaMatcher;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerDefinition;
use Qualimetrix\Analysis\Policy\Architecture\Layer\LayerRegistry;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MatchedCriterion;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MatchedCriterionKind;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MatchMode;
use Qualimetrix\Analysis\Policy\Architecture\Layer\MembershipSpec;
use Qualimetrix\Analysis\Policy\Architecture\Layer\TemplateLayerDefinition;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\LogicalClassPath;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * The border of the analysed set, read as a border rather than as an answer.
 *
 * `extends`, `implements` and `attributes` are answered from declaration edges
 * the run recorded. Where those run out, the lists are empty, and an empty list
 * used to mean "this class has no parents" rather than "this run did not look".
 * The distinction is invisible in the result of a single lookup, so every test
 * here pairs the broken shape with the control that must keep working.
 *
 * **Exactly which shape breaks.** A criterion naming the class's own DIRECT
 * parent is fine even when that parent is vendor code: the edge was recorded
 * from the analysed child. Only a link further up the chain is missing, which
 * is why {@see itMatchesACriterionNamingADirectParentOutsideTheAnalysedSet()}
 * and {@see itCannotDecideExtendsWhenAMidChainLinkIsOutsideTheAnalysedSet()}
 * sit next to each other.
 */
#[CoversClass(CriterionOutcome::class)]
#[CoversClass(CriteriaEvaluation::class)]
#[CoversClass(LayerCriteriaMatcher::class)]
#[CoversClass(ClassContextFactory::class)]
#[CoversClass(LayerDefinition::class)]
#[CoversClass(LayerRegistry::class)]
final class UndecidableMembershipTest extends TestCase
{
    private const string CHILD_NS = 'App\\Web';

    private const string CHILD = 'App\\Web\\OrderController';

    private const string MIDDLE = 'Vendor\\Lib\\Middle';

    private const string BASE = 'Vendor\\Lib\\Base';

    #[Test]
    public function itMatchesACriterionNamingADirectParentOutsideTheAnalysedSet(): void
    {
        // The narrow boundary of the defect: the child was analysed, so its own
        // `extends` edge exists and names the vendor parent. Nothing here is
        // undecidable, and a cure that made it so would turn every
        // vendor-anchored layer into a coverage gap.
        $registry = $this->registryFor(
            new MembershipSpec(extends: [self::BASE]),
            [[self::CHILD, self::BASE, DependencyType::Extends]],
        );

        self::assertSame('web', $registry->resolveLayer($this->child()));
        self::assertSame([], $registry->undecidedLayers($this->child()));
    }

    #[Test]
    public function itCannotDecideExtendsWhenAMidChainLinkIsOutsideTheAnalysedSet(): void
    {
        // `App\Web\OrderController extends Vendor\Lib\Middle extends
        // Vendor\Lib\Base`, with only the child analysed. The walk stops at
        // Middle, so the criterion naming Base is not answered — and used to
        // report a confident non-match.
        $registry = $this->registryFor(
            new MembershipSpec(extends: [self::BASE]),
            [[self::CHILD, self::MIDDLE, DependencyType::Extends]],
        );

        self::assertNull($registry->resolveLayer($this->child()), 'An unproven membership is not a membership.');
        self::assertSame(
            ['web'],
            $registry->undecidedLayers($this->child()),
            'The layer the run could not answer must be nameable, or the gap reads as an ordinary one.',
        );
    }

    #[Test]
    public function itDecidesTheSameChainOnceEveryLinkIsAnalysed(): void
    {
        // Control for the case above: same criterion, same two edges, and the
        // middle link inside the analysed set. A decided answer, both ways.
        $registry = $this->registryFor(
            new MembershipSpec(extends: [self::BASE]),
            [
                [self::CHILD, self::MIDDLE, DependencyType::Extends],
                [self::MIDDLE, self::BASE, DependencyType::Extends],
            ],
            analysed: [self::CHILD, self::MIDDLE],
        );

        self::assertSame('web', $registry->resolveLayer($this->child()));
        self::assertSame([], $registry->undecidedLayers($this->child()));

        // The negative half needs every node of the chain analysed, the root
        // included: a walk that stops on an analysed class has seen that
        // class's own parents and knows there are none, while one that stops on
        // `Vendor\Lib\Base` has not — Base may well extend the named type.
        $absent = $this->registryFor(
            new MembershipSpec(extends: ['Vendor\\Lib\\Unrelated']),
            [
                [self::CHILD, self::MIDDLE, DependencyType::Extends],
                [self::MIDDLE, self::BASE, DependencyType::Extends],
            ],
            analysed: [self::CHILD, self::MIDDLE, self::BASE],
        );

        self::assertNull($absent->resolveLayer($this->child()));
        self::assertSame(
            [],
            $absent->undecidedLayers($this->child()),
            'A chain walked to its end inside the analysed set is a decided non-match, not a doubt.',
        );
    }

    #[Test]
    public function itCannotDecideImplementsWhenTheChainIsTruncated(): void
    {
        $registry = $this->registryFor(
            new MembershipSpec(implements: ['Vendor\\Lib\\Marker']),
            [[self::CHILD, self::MIDDLE, DependencyType::Extends]],
        );

        self::assertSame(['web'], $registry->undecidedLayers($this->child()));
    }

    #[Test]
    public function itCannotDecideAttributesForASymbolTheRunNeverAnalysed(): void
    {
        // The other half of the same mechanism, and the one that reaches every
        // dependency-edge end: a symbol seen only as an edge TARGET carries no
        // attribute facts at all, and `attributes:` used to answer "no" about
        // it. Attributes sit on the class itself, so an analysed class is
        // always decided — only this case is not.
        $registry = $this->registryFor(
            new MembershipSpec(attributes: ['Vendor\\Lib\\AsEntity']),
            [[self::CHILD, self::MIDDLE, DependencyType::Extends]],
        );

        self::assertSame([], $registry->undecidedLayers($this->child()), 'The analysed child is decided.');
        self::assertSame(
            ['web'],
            $registry->undecidedLayers(SymbolPath::forClass('Vendor\\Lib', 'Middle')),
            'The unanalysed edge end is not.',
        );
    }

    /**
     * The five criterion kinds, enumerated from the code that walks them
     * ({@see LayerCriteriaMatcher::evaluate()},
     * {@see LayerCriteriaMatcher::declaredKindCount()},
     * {@see MatchedCriterionKind}), not from documentation: `patterns`,
     * `suffix`, `attributes`, `implements`, `extends`. The first two are
     * answered from the FQN and can never be undecidable; the last three read
     * declaration facts and can.
     *
     * @return iterable<string, array{0: MembershipSpec, 1: bool}>
     */
    public static function provideEveryCriterionKind(): iterable
    {
        yield 'patterns' => [new MembershipSpec(patterns: ['App\\Nowhere\\**']), false];
        yield 'suffix' => [new MembershipSpec(suffix: ['Absent']), false];
        yield 'attributes' => [new MembershipSpec(attributes: ['Vendor\\Lib\\AsEntity']), true];
        yield 'implements' => [new MembershipSpec(implements: ['Vendor\\Lib\\Marker']), true];
        yield 'extends' => [new MembershipSpec(extends: [self::BASE]), true];
    }

    #[Test]
    #[DataProvider('provideEveryCriterionKind')]
    public function itDecidesEveryFqnAnsweredKindAndOnlyDoubtsTheFactAnsweredOnes(
        MembershipSpec $membership,
        bool $undecidableWithoutFacts,
    ): void {
        // One subject the run never analysed: every kind that reads declaration
        // facts is blind about it, every kind that reads the FQN is not.
        $registry = $this->registryFor(
            $membership,
            [[self::CHILD, self::MIDDLE, DependencyType::Extends]],
        );

        $subject = SymbolPath::forClass('Vendor\\Lib', 'Middle');

        self::assertNull($registry->resolveLayer($subject), 'None of these criteria can match this subject.');
        self::assertSame(
            $undecidableWithoutFacts ? ['web'] : [],
            $registry->undecidedLayers($subject),
        );
    }

    #[Test]
    public function itKeepsAHitFoundOnATruncatedChain(): void
    {
        // Evidence that was found stands: truncation can hide a match, never
        // invent one, so a positive hit is conclusive whatever the walk missed.
        $registry = $this->registryFor(
            new MembershipSpec(extends: [self::MIDDLE]),
            [[self::CHILD, self::MIDDLE, DependencyType::Extends]],
        );

        self::assertSame('web', $registry->resolveLayer($this->child()));
        self::assertSame([], $registry->undecidedLayers($this->child()));
    }

    #[Test]
    public function itLetsADecidedHitSettleAnUndecidableSiblingKindUnderMatchAny(): void
    {
        // Kleene OR: one kind that fired decides the layer regardless of a
        // sibling kind nobody could answer.
        $registry = $this->registryFor(
            new MembershipSpec(patterns: [self::CHILD_NS . '\\**'], extends: [self::BASE]),
            [[self::CHILD, self::MIDDLE, DependencyType::Extends]],
        );

        self::assertSame('web', $registry->resolveLayer($this->child()));
        self::assertSame([], $registry->undecidedLayers($this->child()));
    }

    #[Test]
    public function itLetsADecidedMissSettleAnUndecidableSiblingKindUnderMatchAll(): void
    {
        // Kleene AND, the mirror: one kind that definitively did not fire
        // decides the layer, so the undecidable sibling changes nothing.
        $registry = $this->registryFor(
            new MembershipSpec(
                patterns: ['App\\Nowhere\\**'],
                extends: [self::BASE],
                mode: MatchMode::All,
            ),
            [[self::CHILD, self::MIDDLE, DependencyType::Extends]],
        );

        self::assertNull($registry->resolveLayer($this->child()));
        self::assertSame(
            [],
            $registry->undecidedLayers($this->child()),
            'Under `all`, a criterion that failed settles the layer without the doubtful one.',
        );
    }

    #[Test]
    public function itCannotDecideMatchAllWhenEveryOtherKindFired(): void
    {
        $registry = $this->registryFor(
            new MembershipSpec(
                patterns: [self::CHILD_NS . '\\**'],
                extends: [self::BASE],
                mode: MatchMode::All,
            ),
            [[self::CHILD, self::MIDDLE, DependencyType::Extends]],
        );

        self::assertNull($registry->resolveLayer($this->child()));
        self::assertSame(['web'], $registry->undecidedLayers($this->child()));
    }

    #[Test]
    public function itCannotDecideMembershipWhenTheExcludeClauseIsTheUnanswerableHalf(): void
    {
        // The positive criteria caught the class; whether the clause that would
        // remove it fires is unknown. Reporting the class as a member would be
        // deciding the clause in the layer's favour.
        $registry = $this->registryFor(
            new MembershipSpec(
                patterns: [self::CHILD_NS . '\\**'],
                exclude: new ExcludeSpec(extends: [self::BASE]),
            ),
            [[self::CHILD, self::MIDDLE, DependencyType::Extends]],
        );

        self::assertNull($registry->resolveLayer($this->child()));
        self::assertSame(['web'], $registry->undecidedLayers($this->child()));
        self::assertSame(
            [],
            $registry->excludedLayers($this->child()),
            'A clause nobody could evaluate did not fire, so it must not be counted as one that did.',
        );
    }

    #[Test]
    public function itDoesNotWithdrawALaterLayersMatchBecauseAnEarlierOneIsUndecidable(): void
    {
        // Deliberate: dropping the decided match would leave the class in no
        // layer, so no allow-list would judge its edges and real violations
        // would stop being reported. The match stands; the doubt is published
        // beside it.
        $registry = new LayerRegistry(
            [
                new LayerDefinition('vendorish', new MembershipSpec(extends: [self::BASE])),
                new LayerDefinition('web', new MembershipSpec(patterns: [self::CHILD_NS . '\\**'])),
            ],
            new ClassContextFactory(),
        );
        $registry->bindGraph(
            self::graphWith([[self::CHILD, self::MIDDLE, DependencyType::Extends]]),
            [$this->child()],
        );

        self::assertSame('web', $registry->resolveLayer($this->child()));
        self::assertSame(['vendorish'], $registry->undecidedLayers($this->child()));
    }

    #[Test]
    public function itKeepsANamespaceSymbolDecisive(): void
    {
        // A namespace has no parents and no attributes, and that IS the answer
        // — a cure that made every namespace lookup undecidable would flood the
        // gap report with symbols no layer was ever meant to claim.
        $registry = $this->registryFor(
            new MembershipSpec(extends: [self::BASE]),
            [[self::CHILD, self::MIDDLE, DependencyType::Extends]],
        );

        $namespace = SymbolPath::forNamespace(self::CHILD_NS);

        self::assertNull($registry->resolveLayer($namespace));
        self::assertSame([], $registry->undecidedLayers($namespace));
    }

    #[Test]
    public function itAnswersDecisivelyWhenNoAnalysedUniverseWasBound(): void
    {
        // A registry assembled by hand, with a graph but no universe, cannot
        // tell a chain that ended from one that was cut — and says so by
        // keeping the answers it always gave rather than calling everything
        // doubtful.
        $registry = new LayerRegistry(
            [new LayerDefinition('web', new MembershipSpec(extends: [self::BASE]))],
            new ClassContextFactory(),
        );
        $registry->bindGraph(self::graphWith([[self::CHILD, self::MIDDLE, DependencyType::Extends]]));

        self::assertNull($registry->resolveLayer($this->child()));
        self::assertSame([], $registry->undecidedLayers($this->child()));
    }

    #[Test]
    public function itReportsWhereTheChainWasCut(): void
    {
        // The count alone would not tell a reader which boundary to look at.
        $factory = new ClassContextFactory();
        $factory->bindGraph(
            self::graphWith([[self::CHILD, self::MIDDLE, DependencyType::Extends]]),
            [$this->child()],
        );

        self::assertSame([self::MIDDLE], $factory->build($this->child())->unresolvedDeclarations);
        self::assertTrue($factory->build($this->child())->declarationAnalysed);
    }

    #[Test]
    public function itCombinesOutcomesTheSameWayForBothModes(): void
    {
        // The combinator directly, so a future caller that reimplements it can
        // be checked against one table rather than against a walk.
        $hit = [new MatchedCriterion(MatchedCriterionKind::Pattern, 'App\\**')];

        self::assertSame(
            CriterionOutcome::Matches,
            (new CriteriaEvaluation($hit, [MatchedCriterionKind::Extends]))->outcome(MatchMode::Any, 2),
        );
        self::assertSame(
            CriterionOutcome::Undecidable,
            (new CriteriaEvaluation([], [MatchedCriterionKind::Extends]))->outcome(MatchMode::Any, 2),
        );
        self::assertSame(
            CriterionOutcome::DoesNotMatch,
            (new CriteriaEvaluation([], []))->outcome(MatchMode::Any, 2),
        );
        self::assertSame(
            CriterionOutcome::Undecidable,
            (new CriteriaEvaluation($hit, [MatchedCriterionKind::Extends]))->outcome(MatchMode::All, 2),
        );
        self::assertSame(
            CriterionOutcome::DoesNotMatch,
            (new CriteriaEvaluation([], [MatchedCriterionKind::Extends]))->outcome(MatchMode::All, 2),
        );
        self::assertSame(
            CriterionOutcome::Matches,
            (new CriteriaEvaluation($hit, []))->outcome(MatchMode::All, 1),
        );
    }

    #[Test]
    public function itStillObservesATupleWhenTheTemplatesCriterionCannotBeDecided(): void
    {
        // Observation fails toward existence on purpose. Dropping the tuple
        // would delete the concrete layer, runtime would never evaluate the
        // class against it, and the doubt would come out as an ordinary
        // non-match with nothing left to report — the silence this package
        // exists to remove, reintroduced one step earlier.
        $factory = new ClassContextFactory();
        $factory->bindGraph(
            self::graphWith([['App\\Module\\Order\\Domain\\Order', self::MIDDLE, DependencyType::Extends]]),
            [SymbolPath::fromClassFqn('App\\Module\\Order\\Domain\\Order')],
        );

        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(
                patterns: ['App\\Module\\{module}\\Domain\\**'],
                extends: [self::BASE],
                mode: MatchMode::All,
            ),
        );

        $tuples = (new TupleExtractor())->collect(
            $template,
            new ClassSet([SymbolPath::fromClassFqn('App\\Module\\Order\\Domain\\Order')], $factory),
        );

        self::assertSame([['module' => 'Order']], $tuples);
    }

    #[Test]
    public function itStillObservesATupleWhenTheTemplatesExcludeCannotBeDecided(): void
    {
        // Same rule on the other side of the clause: an `exclude:` the run
        // cannot evaluate must not remove the tuple, or the layer disappears
        // and the doubt about its membership has nowhere left to surface.
        $order = SymbolPath::fromClassFqn('App\\Module\\Order\\Domain\\Order');

        $factory = new ClassContextFactory();
        $factory->bindGraph(
            self::graphWith([['App\\Module\\Order\\Domain\\Order', self::MIDDLE, DependencyType::Extends]]),
            [$order],
        );

        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(
                patterns: ['App\\Module\\{module}\\Domain\\**'],
                exclude: new ExcludeSpec(extends: [self::BASE]),
            ),
        );

        self::assertSame(
            [['module' => 'Order']],
            (new TupleExtractor())->collect($template, new ClassSet([$order], $factory)),
        );
    }

    #[Test]
    public function itObservesNoTupleWhenTheTemplatesExcludeIsDecidedToFire(): void
    {
        // The control: an exclude the run CAN answer, and answers yes to, still
        // removes the class from observation.
        $order = SymbolPath::fromClassFqn('App\\Module\\Order\\Domain\\Order');

        $factory = new ClassContextFactory();
        $factory->bindGraph(
            self::graphWith([['App\\Module\\Order\\Domain\\Order', self::MIDDLE, DependencyType::Extends]]),
            [$order],
        );

        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(
                patterns: ['App\\Module\\{module}\\Domain\\**'],
                exclude: new ExcludeSpec(extends: [self::MIDDLE]),
            ),
        );

        self::assertSame([], (new TupleExtractor())->collect($template, new ClassSet([$order], $factory)));
    }

    #[Test]
    public function itObservesNoTupleWhenTheTemplatesCriterionIsDecidedAgainst(): void
    {
        // The control that keeps the rule above from meaning "never filter":
        // a criterion the run CAN answer, and answers no to, still removes the
        // class from observation.
        $order = SymbolPath::fromClassFqn('App\\Module\\Order\\Domain\\Order');

        $factory = new ClassContextFactory();
        $factory->bindGraph(self::graphWith([]), [$order]);

        $template = new TemplateLayerDefinition(
            'domain-{module}',
            new MembershipSpec(
                patterns: ['App\\Module\\{module}\\Domain\\**'],
                suffix: ['Repository'],
                mode: MatchMode::All,
            ),
        );

        self::assertSame([], (new TupleExtractor())->collect($template, new ClassSet([$order], $factory)));
    }

    private function child(): SymbolPath
    {
        return SymbolPath::forClass(self::CHILD_NS, 'OrderController');
    }

    /**
     * @param list<array{0: string, 1: string, 2: DependencyType}> $edges
     * @param list<string>|null $analysed FQNs the run analysed; defaults to the
     *                                    child alone, which is the shape the
     *                                    defect needs.
     */
    private function registryFor(MembershipSpec $membership, array $edges, ?array $analysed = null): LayerRegistry
    {
        $registry = new LayerRegistry(
            [new LayerDefinition('web', $membership)],
            new ClassContextFactory(),
        );

        $registry->bindGraph(
            self::graphWith($edges),
            array_map(
                static fn(string $fqn): SymbolPath => SymbolPath::fromClassFqn($fqn),
                $analysed ?? [self::CHILD],
            ),
        );

        return $registry;
    }

    /**
     * @param list<array{0: string, 1: string, 2: DependencyType}> $edges
     */
    private static function graphWith(array $edges): DependencyGraphInterface
    {
        $deps = [];
        foreach ($edges as [$source, $target, $type]) {
            $deps[] = new Dependency(
                DeclarationPath::of(
                    SymbolPath::fromClassFqn($source),
                    RelativePath::fromString('test.php'),
                    DeclarationOrdinal::fromRank(0),
                ),
                new LogicalClassPath(SymbolPath::fromClassFqn($target)),
                $type,
                Location::none(),
            );
        }

        return new readonly class ($deps) implements DependencyGraphInterface {
            /**
             * @param list<Dependency> $deps
             */
            public function __construct(private array $deps) {}

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
        };
    }
}
