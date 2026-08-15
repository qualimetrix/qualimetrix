<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CircularDependency\CycleMemberLabels;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(CycleMemberLabels::class)]
final class CycleMemberLabelsTest extends TestCase
{
    #[Test]
    public function itKeepsShortNamesWhenTheyAreAlreadyUnique(): void
    {
        $labels = CycleMemberLabels::forMembers($this->paths(['App\\Order', 'App\\Payment']));

        self::assertSame('Order', $labels->labelFor($this->path('App\\Order')));
        self::assertSame('Payment', $labels->labelFor($this->path('App\\Payment')));
    }

    #[Test]
    public function itAddsOneSegmentToSeparateCollidingShortNames(): void
    {
        $labels = CycleMemberLabels::forMembers($this->paths(['App\\Billing\\Service', 'App\\Orders\\Service']));

        self::assertSame('Billing\\Service', $labels->labelFor($this->path('App\\Billing\\Service')));
        self::assertSame('Orders\\Service', $labels->labelFor($this->path('App\\Orders\\Service')));
    }

    #[Test]
    public function itKeepsAddingSegmentsWhileTheGrownLabelsStillCollide(): void
    {
        $labels = CycleMemberLabels::forMembers($this->paths(['App\\Api\\Http\\Client', 'App\\Cdn\\Http\\Client']));

        self::assertSame('Api\\Http\\Client', $labels->labelFor($this->path('App\\Api\\Http\\Client')));
        self::assertSame('Cdn\\Http\\Client', $labels->labelFor($this->path('App\\Cdn\\Http\\Client')));
    }

    #[Test]
    public function itGrowsOnlyTheCollidingMembers(): void
    {
        $labels = CycleMemberLabels::forMembers($this->paths([
            'App\\Billing\\Service',
            'App\\Orders\\Service',
            'App\\Reporting\\Exporter',
        ]));

        self::assertSame('Exporter', $labels->labelFor($this->path('App\\Reporting\\Exporter')));
        self::assertSame('Billing\\Service', $labels->labelFor($this->path('App\\Billing\\Service')));
    }

    #[Test]
    public function itFallsBackToTheFullNameWhenOnlyTheRootSegmentDiffers(): void
    {
        $labels = CycleMemberLabels::forMembers($this->paths(['Acme\\Log\\Writer', 'Widgets\\Log\\Writer']));

        self::assertSame('Acme\\Log\\Writer', $labels->labelFor($this->path('Acme\\Log\\Writer')));
        self::assertSame('Widgets\\Log\\Writer', $labels->labelFor($this->path('Widgets\\Log\\Writer')));
    }

    #[Test]
    public function itAnchorsAGlobalClassAtTheRootWhenItFacesANamespacedNamesake(): void
    {
        $labels = CycleMemberLabels::forMembers($this->paths(['App\\Service', 'Service']));

        // A bare `Service` is what an unambiguous short name looks like, so the
        // global class cannot be left alone: it would read as `App\Service`.
        self::assertSame('App\\Service', $labels->labelFor($this->path('App\\Service')));
        self::assertSame('\\Service', $labels->labelFor($this->path('Service')));
    }

    #[Test]
    public function itAnchorsAMemberWhoseFullNameIsASuffixOfAnother(): void
    {
        $labels = CycleMemberLabels::forMembers($this->paths(['App\\Log\\Writer', 'Acme\\App\\Log\\Writer']));

        // `App\Log\Writer` is a telling label for neither: as written, it is
        // also how the second class ends.
        self::assertSame('\\App\\Log\\Writer', $labels->labelFor($this->path('App\\Log\\Writer')));
        self::assertSame('Acme\\App\\Log\\Writer', $labels->labelFor($this->path('Acme\\App\\Log\\Writer')));
    }

    #[Test]
    public function itAnchorsEveryLinkOfASuffixChainThatHasNoTellingSuffix(): void
    {
        $labels = CycleMemberLabels::forMembers($this->paths(['Foo', 'A\\Foo', 'B\\A\\Foo']));

        // Each name is a suffix of the next, so only the longest can be written
        // relatively; the other two have to be anchored.
        self::assertSame('\\Foo', $labels->labelFor($this->path('Foo')));
        self::assertSame('\\A\\Foo', $labels->labelFor($this->path('A\\Foo')));
        self::assertSame('B\\A\\Foo', $labels->labelFor($this->path('B\\A\\Foo')));
    }

    /**
     * @param list<string> $fqns
     */
    #[Test]
    #[DataProvider('provideMembershipCases')]
    public function itNeverGivesTwoMembersTheSameLabel(array $fqns): void
    {
        $labels = CycleMemberLabels::forMembers($this->paths($fqns));

        $rendered = array_map(
            fn(string $fqn): string => $labels->labelFor($this->path($fqn)),
            $fqns,
        );

        self::assertCount(\count($fqns), array_unique($rendered), implode(', ', $rendered));
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function provideMembershipCases(): iterable
    {
        yield 'unrelated names' => [['App\\Order', 'App\\Payment', 'Billing\\Invoice']];
        yield 'shared class name' => [['App\\Billing\\Service', 'App\\Orders\\Service']];
        yield 'shared class name and namespace' => [['App\\Api\\Http\\Client', 'App\\Cdn\\Http\\Client']];
        yield 'differing only at the root' => [['Acme\\Log\\Writer', 'Widgets\\Log\\Writer']];
        yield 'global namesake' => [['App\\Service', 'Service']];
        yield 'suffix chain' => [['Foo', 'A\\Foo', 'B\\A\\Foo']];
        yield 'suffix chain crossing a shared name' => [
            ['App\\Log\\Writer', 'Acme\\App\\Log\\Writer', 'Log\\Writer', 'Writer', 'Other\\Writer'],
        ];
        yield 'colliding and unique members mixed' => [
            ['App\\Billing\\Service', 'App\\Orders\\Service', 'App\\Reporting\\Exporter', 'Legacy\\Exporter\\Service'],
        ];
    }

    #[Test]
    public function itLeavesAGlobalClassBareWhenNoMemberEndsWithItsName(): void
    {
        $labels = CycleMemberLabels::forMembers($this->paths(['Service', 'App\\Exporter']));

        self::assertSame('Service', $labels->labelFor($this->path('Service')));
    }

    #[Test]
    public function itHandlesAnEmptyMembership(): void
    {
        $labels = CycleMemberLabels::forMembers([]);

        self::assertSame('App\\Order', $labels->labelFor($this->path('App\\Order')));
    }

    #[Test]
    public function itGivesRepeatedMembersTheSameLabel(): void
    {
        // The cycle path carries its representative at both ends.
        $labels = CycleMemberLabels::forMembers($this->paths([
            'App\\Billing\\Service',
            'App\\Orders\\Service',
            'App\\Billing\\Service',
        ]));

        self::assertSame('Billing\\Service', $labels->labelFor($this->path('App\\Billing\\Service')));
    }

    #[Test]
    public function itFallsBackToTheFullNameForAMemberItWasNotBuiltFrom(): void
    {
        $labels = CycleMemberLabels::forMembers($this->paths(['App\\Order']));

        self::assertSame('Other\\Stranger', $labels->labelFor($this->path('Other\\Stranger')));
    }

    /**
     * @param list<string> $fqns
     *
     * @return list<SymbolPath>
     */
    private function paths(array $fqns): array
    {
        return array_map($this->path(...), $fqns);
    }

    private function path(string $fqn): SymbolPath
    {
        return SymbolPath::fromClassFqn($fqn);
    }
}
