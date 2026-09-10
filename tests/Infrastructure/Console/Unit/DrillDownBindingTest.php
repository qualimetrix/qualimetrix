<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Offender\RankedOffenderLevels;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\CallableWithMetrics;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\CallableKind;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\SymbolLevel;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\Console\DrillDownBinding;

#[CoversClass(DrillDownBinding::class)]
final class DrillDownBindingTest extends TestCase
{
    #[Test]
    public function itBindsANamespaceThatNamesAnAnalyzedOne(): void
    {
        // The namespace itself and the one class canonical name under it: the
        // filter compares a `--namespace` value against both.
        self::assertSame(
            2,
            (new DrillDownBinding())->namespaceBindings('Demo\\Alpha', $this->repository(), null),
        );
    }

    /**
     * The comparison the worst-offender half of the filter makes.
     *
     * `FindingFilter::filterWorstOffenders()` offers a `--namespace` value the
     * offender's whole canonical name, so `Demo\Alpha\*` selects the class
     * `Demo\Alpha\Widget` while matching no namespace at all. Counting only
     * namespaces refused this value and printed an empty report's refusal over
     * a selection that was not empty.
     */
    #[Test]
    public function itBindsAGlobThatOnlyTheCanonicalSymbolNameSatisfies(): void
    {
        self::assertSame(
            1,
            (new DrillDownBinding())->namespaceBindings('Demo\\Alpha\\*', $this->repository(), null),
        );
    }

    #[Test]
    public function itBindsNothingForANamespaceNoAnalyzedSymbolCarries(): void
    {
        self::assertSame(
            0,
            (new DrillDownBinding())->namespaceBindings('Zzz\\Nope', $this->repository(), null),
        );
    }

    #[Test]
    public function itBindsASubtreeByItsPrefixTheWayTheFilterMatchesIt(): void
    {
        // `Demo` binds itself, every namespace beneath it and every canonical
        // symbol name under those: the same prefix semantics FindingFilter
        // applies to a finding's namespace and to an offender's whole name.
        self::assertSame(
            6,
            (new DrillDownBinding())->namespaceBindings('Demo', $this->repository(), null),
        );
    }

    #[Test]
    public function itRefusesAPrefixThatOnlyLooksLikeOne(): void
    {
        self::assertSame(
            0,
            (new DrillDownBinding())->namespaceBindings('Demo\\Alp', $this->repository(), null),
        );
    }

    #[Test]
    public function itBindsAGlobNamespacePattern(): void
    {
        self::assertSame(
            1,
            (new DrillDownBinding())->namespaceBindings('Demo\\Al?ha', $this->repository(), null),
        );
    }

    #[Test]
    public function itBindsAnIntermediateNamespaceByGlobWithoutANamespaceTree(): void
    {
        // `Demo\Beta` holds no symbols of its own — only `Demo\Beta\Deep` does.
        // A glob cannot reach it by prefix, so a run without a tree would refuse
        // an existing subtree unless the ancestors are synthesized.
        self::assertSame(
            1,
            (new DrillDownBinding())->namespaceBindings('Demo\\Bet?', $this->repository(), null),
        );
    }

    #[Test]
    public function itBindsANamespaceThatOnlyTheNamespaceTreeKnows(): void
    {
        $tree = new NamespaceTree(['Demo\\Gamma']);

        self::assertSame(
            1,
            (new DrillDownBinding())->namespaceBindings('Demo\\Gamma', $this->repository(), $tree),
        );
    }

    #[Test]
    public function itTreatsATrailingBackslashAsCosmetic(): void
    {
        self::assertSame(
            2,
            (new DrillDownBinding())->namespaceBindings('Demo\\Alpha\\', $this->repository(), null),
        );
    }

    #[Test]
    public function itKeepsTheProjectSentinelOutOfTheNamespaceUniverse(): void
    {
        $repository = $this->repository();
        $repository->add(SymbolPath::forProject(), new MetricBag(), null, null);

        $binding = new DrillDownBinding();

        self::assertSame(0, $binding->namespaceBindings('__PROJECT__', $repository, null));
        self::assertSame(6, $binding->namespaceUniverseSize($repository, null));
    }

    #[Test]
    public function itCountsTheNamespaceUniverseTheRefusalNames(): void
    {
        $binding = new DrillDownBinding();

        self::assertSame(6, $binding->namespaceUniverseSize($this->repository(), null));
        self::assertSame(
            7,
            $binding->namespaceUniverseSize($this->repository(), new NamespaceTree(['Demo\\Gamma'])),
        );
    }

    #[Test]
    public function itBindsAClassByItsFullyQualifiedName(): void
    {
        self::assertSame(
            1,
            (new DrillDownBinding())->classBindings('Demo\\Beta\\Deep\\Thing', $this->repository()),
        );
    }

    #[Test]
    public function itBindsNothingForAClassNoAnalyzedSymbolCarries(): void
    {
        self::assertSame(
            0,
            (new DrillDownBinding())->classBindings('Zzz\\Nope\\Thing', $this->repository()),
        );
    }

    #[Test]
    public function itDoesNotAcceptANamespaceInPlaceOfAClass(): void
    {
        self::assertSame(
            0,
            (new DrillDownBinding())->classBindings('Demo\\Alpha', $this->repository()),
        );
    }

    #[Test]
    public function itCountsTheClassUniverseTheRefusalNames(): void
    {
        self::assertSame(2, (new DrillDownBinding())->classUniverseSize($this->repository()));
    }

    /**
     * A canonical name that no filter ever compares must not count as a
     * binding.
     *
     * A File's canonical name is its path, and `FindingFilter::filterFindings()`
     * compares a File finding by its namespace — which a File symbol does not
     * have. Worst offenders are the only thing compared by canonical name, and
     * they are ranked for {@see RankedOffenderLevels::LEVELS} alone. So a value
     * matching a File path and nothing else selected nothing anywhere while
     * counting as bound: the refusal was withheld and the empty report went out
     * as if the subtree were clean.
     */
    #[Test]
    public function itDoesNotBindAValueToAFileCanonicalNameNoFilterCompares(): void
    {
        $repository = $this->repository();
        $repository->add(
            SymbolPath::forFile(RelativePath::fromString('src/Only.php')),
            new MetricBag(),
            RelativePath::fromString('src/Only.php'),
            5,
        );

        $binding = new DrillDownBinding();

        self::assertSame(0, $binding->namespaceBindings('src/*', $repository, null));
        self::assertSame(6, $binding->namespaceUniverseSize($repository, null));
    }

    /** The same for a Callable, whose canonical name carries the member. */
    #[Test]
    public function itDoesNotBindAValueToACallableCanonicalNameNoFilterCompares(): void
    {
        $repository = $this->repository();
        $repository->addCallable(new CallableWithMetrics(
            declarationPath: DeclarationPath::of(
                SymbolPath::forMethod('Demo\\Alpha', 'Widget', 'calculate'),
                RelativePath::fromString('src/Alpha/Widget.php'),
                DeclarationOrdinal::fromRank(0),
            ),
            startFilePos: 0,
            kind: CallableKind::Method,
            anonymousSyntax: null,
            lexicalClassContext: null,
            classAggregationOwner: null,
            metrics: new MetricBag(),
        ));

        $binding = new DrillDownBinding();

        self::assertSame(0, $binding->namespaceBindings('*::calculate', $repository, null));

        // The opposite error the narrowing must not cause: the callable's
        // namespace is what `filterFindings()` compares its findings by, so it
        // stays in the universe and the enclosing namespace still binds.
        self::assertSame(2, $binding->namespaceBindings('Demo\\Alpha', $repository, null));
    }

    /**
     * The universe stays exactly the enumeration
     * {@see \Qualimetrix\Analysis\Evidence\ComputedMetrics\Health\Contract\Summary\HealthSummaryBuilder}
     * ranks offenders for. A level added to one side and not the other is the
     * whole defect, in either direction: a missing level refuses a value whose
     * report is not empty, an extra one accepts a value whose report cannot be
     * anything else.
     */
    #[Test]
    public function itRanksOffendersForExactlyTheLevelsTheUniverseTrustsWithACanonicalName(): void
    {
        $source = file_get_contents(\dirname(__DIR__, 4)
            . '/src/Analysis/Evidence/ComputedMetrics/Health/Contract/Summary/HealthSummaryBuilder.php');
        self::assertIsString($source);

        preg_match_all('/buildWorstOffenders\\([^;]*?SymbolLevel::([A-Za-z_]+)/s', $source, $matches);

        self::assertSame(
            array_map(static fn(SymbolLevel $level): string => $level->name, RankedOffenderLevels::LEVELS),
            $matches[1],
            'HealthSummaryBuilder ranks a different set of levels than RankedOffenderLevels names.',
        );
    }

    /**
     * Two classes in two namespaces, one of them nested one level deeper than
     * any symbol-bearing namespace, so `Demo` and `Demo\Beta` exist only as
     * ancestors.
     */
    private function repository(): InMemoryMetricRepository
    {
        $repository = new InMemoryMetricRepository();
        $repository->add(
            SymbolPath::forClass('Demo\\Alpha', 'Widget'),
            new MetricBag(),
            RelativePath::fromString('src/Alpha/Widget.php'),
            5,
        );
        $repository->add(
            SymbolPath::forClass('Demo\\Beta\\Deep', 'Thing'),
            new MetricBag(),
            RelativePath::fromString('src/Beta/Deep/Thing.php'),
            5,
        );

        return $repository;
    }
}
