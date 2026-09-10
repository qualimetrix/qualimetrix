<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricBag;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\NamespaceTree;
use Qualimetrix\Analysis\Evidence\Measurement\Repository\InMemoryMetricRepository;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\Console\DrillDownBinding;

#[CoversClass(DrillDownBinding::class)]
final class DrillDownBindingTest extends TestCase
{
    #[Test]
    public function itBindsANamespaceThatNamesAnAnalyzedOne(): void
    {
        self::assertSame(
            1,
            (new DrillDownBinding())->namespaceBindings('Demo\\Alpha', $this->repository(), null),
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
        // `Demo` binds itself plus every namespace beneath it: the same
        // prefix semantics FindingFilter applies to a finding's namespace.
        self::assertSame(
            4,
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
            1,
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
        self::assertSame(4, $binding->namespaceUniverseSize($repository, null));
    }

    #[Test]
    public function itCountsTheNamespaceUniverseTheRefusalNames(): void
    {
        $binding = new DrillDownBinding();

        self::assertSame(4, $binding->namespaceUniverseSize($this->repository(), null));
        self::assertSame(
            5,
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
