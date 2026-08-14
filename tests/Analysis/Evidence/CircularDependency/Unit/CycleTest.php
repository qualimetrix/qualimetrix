<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\CircularDependency\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\CircularDependency\Cycle;
use Qualimetrix\Core\Symbol\SymbolPath;

#[CoversClass(Cycle::class)]
final class CycleTest extends TestCase
{
    #[Test]
    public function itGetsSize(): void
    {
        $cycle = new Cycle(
            classes: $this->paths(['App\\A', 'App\\B', 'App\\C']),
            path: $this->paths(['App\\A', 'App\\B', 'App\\C', 'App\\A']),
        );

        self::assertSame(3, $cycle->getSize());
        self::assertCount(3, $cycle->getClasses());
        self::assertCount(4, $cycle->getPath());
    }

    #[Test]
    public function itConvertsToString(): void
    {
        $cycle = new Cycle(
            classes: $this->paths(['App\\A', 'App\\B', 'App\\C']),
            path: $this->paths(['App\\A', 'App\\B', 'App\\C', 'App\\A']),
        );

        self::assertSame(
            'App\\A → App\\B → App\\C → App\\A',
            $cycle->toString(),
        );
    }

    #[Test]
    public function itConvertsToShortString(): void
    {
        $cycle = new Cycle(
            classes: $this->paths(['App\\Service\\UserService', 'App\\Service\\OrderService']),
            path: $this->paths(['App\\Service\\UserService', 'App\\Service\\OrderService', 'App\\Service\\UserService']),
        );

        self::assertSame(
            'UserService → OrderService → UserService',
            $cycle->toShortString(),
        );
    }

    #[Test]
    public function itConvertsToShortStringWithoutNamespace(): void
    {
        $cycle = new Cycle(
            classes: $this->paths(['A', 'B']),
            path: $this->paths(['A', 'B', 'A']),
        );

        self::assertSame(
            'A → B → A',
            $cycle->toShortString(),
        );
    }

    #[Test]
    public function itDisambiguatesMembersSharingAClassName(): void
    {
        $cycle = new Cycle(
            classes: $this->paths(['App\\Billing\\Service', 'App\\Orders\\Service']),
            path: $this->paths(['App\\Billing\\Service', 'App\\Orders\\Service', 'App\\Billing\\Service']),
        );

        self::assertSame(
            'Billing\\Service → Orders\\Service → Billing\\Service',
            $cycle->toShortString(),
        );
    }

    #[Test]
    public function itKeepsUniqueShortNamesBareWhileDisambiguatingTheRest(): void
    {
        $cycle = new Cycle(
            classes: $this->paths(['App\\Billing\\Service', 'App\\Orders\\Service', 'App\\Reporting\\Exporter']),
            path: $this->paths([
                'App\\Billing\\Service',
                'App\\Reporting\\Exporter',
                'App\\Orders\\Service',
                'App\\Billing\\Service',
            ]),
        );

        self::assertSame(
            'Billing\\Service → Exporter → Orders\\Service → Billing\\Service',
            $cycle->toShortString(),
        );
    }

    #[Test]
    public function itGrowsLabelsUntilTheySeparateWhenTheFirstSuffixStillCollides(): void
    {
        $cycle = new Cycle(
            classes: $this->paths(['App\\Api\\Http\\Client', 'App\\Cdn\\Http\\Client']),
            path: $this->paths(['App\\Api\\Http\\Client', 'App\\Cdn\\Http\\Client', 'App\\Api\\Http\\Client']),
        );

        // "Client" collides, and so does "Http\Client" — one more segment separates them.
        self::assertSame(
            'Api\\Http\\Client → Cdn\\Http\\Client → Api\\Http\\Client',
            $cycle->toShortString(),
        );
    }

    #[Test]
    public function itAnchorsAMemberThatHasRunOutOfSegments(): void
    {
        $cycle = new Cycle(
            classes: $this->paths(['App\\Service', 'Service']),
            path: $this->paths(['App\\Service', 'Service', 'App\\Service']),
        );

        // The global class cannot grow, and leaving it bare would read as a
        // short name for the other member.
        self::assertSame(
            'App\\Service → \\Service → App\\Service',
            $cycle->toShortString(),
        );
    }

    #[Test]
    public function itDisambiguatesAgainstAMemberTheDisplayedPathSkips(): void
    {
        // getPath() is the shortest loop through the representative, so a member
        // of the cycle can be missing from it — and still make a short name
        // ambiguous for whoever reads the message.
        $cycle = new Cycle(
            classes: $this->paths(['App\\Billing\\Service', 'App\\Orders\\Service', 'App\\Reporting\\Exporter']),
            path: $this->paths(['App\\Billing\\Service', 'App\\Reporting\\Exporter', 'App\\Billing\\Service']),
        );

        self::assertSame(
            'Billing\\Service → Exporter → Billing\\Service',
            $cycle->toShortString(),
        );
    }

    #[Test]
    public function itLabelsTruncatedPathConsistentlyWithTheFullPath(): void
    {
        $classes = ['App\\Billing\\Service', 'App\\Orders\\Service'];
        $path = ['App\\Billing\\Service', 'App\\Orders\\Service'];
        for ($i = 0; $i < 4; $i++) {
            $classes[] = 'App\\Filler\\C' . $i;
            $path[] = 'App\\Filler\\C' . $i;
        }
        $path[] = 'App\\Billing\\Service';

        $cycle = new Cycle(
            classes: $this->paths($classes),
            path: $this->paths($path),
        );

        // Disambiguation is computed over the whole cycle, so a member shown in
        // the truncated rendering carries the same label as in the full one.
        self::assertSame(
            'Billing\\Service → Orders\\Service → C0 → ... (3 more)',
            $cycle->toTruncatedShortString(3),
        );
        self::assertStringStartsWith(
            'Billing\\Service → Orders\\Service → C0',
            $cycle->toShortString(),
        );
    }

    #[Test]
    public function itRepresentsDirectCycle(): void
    {
        $cycle = new Cycle(
            classes: $this->paths(['App\\A', 'App\\B']),
            path: $this->paths(['App\\A', 'App\\B', 'App\\A']),
        );

        self::assertSame(2, $cycle->getSize());
        self::assertSame('App\\A → App\\B → App\\A', $cycle->toString());
        self::assertSame('A → B → A', $cycle->toShortString());
    }

    #[Test]
    public function itDoesNotTruncateShortStringForSmallCycle(): void
    {
        $cycle = new Cycle(
            classes: $this->paths(['A', 'B', 'C']),
            path: $this->paths(['A', 'B', 'C', 'A']),
        );

        // Small cycles should not be truncated
        self::assertSame('A → B → C → A', $cycle->toTruncatedShortString(5));
    }

    #[Test]
    public function itTruncatesShortStringForLargeCycle(): void
    {
        $classes = [];
        $path = [];
        for ($i = 0; $i < 10; $i++) {
            $classes[] = 'C' . $i;
            $path[] = 'C' . $i;
        }
        $path[] = 'C0'; // Close the cycle

        $cycle = new Cycle(
            classes: $this->paths($classes),
            path: $this->paths($path),
        );

        $truncated = $cycle->toTruncatedShortString(5);
        self::assertSame('C0 → C1 → C2 → C3 → C4 → ... (5 more)', $truncated);
    }

    #[Test]
    public function itDoesNotTruncateShortStringExactlyAtLimit(): void
    {
        $classes = [];
        $path = [];
        for ($i = 0; $i < 5; $i++) {
            $classes[] = 'C' . $i;
            $path[] = 'C' . $i;
        }
        $path[] = 'C0'; // Close the cycle (6 entries in path, maxEntries=5 + 1 closing = 6)

        $cycle = new Cycle(
            classes: $this->paths($classes),
            path: $this->paths($path),
        );

        // Path has 6 entries, maxEntries=5 → 5+1=6 → fits, no truncation
        self::assertSame('C0 → C1 → C2 → C3 → C4 → C0', $cycle->toTruncatedShortString(5));
    }

    #[Test]
    public function itReturnsSmallSizeCategory(): void
    {
        $cycle = new Cycle(
            classes: $this->paths(['A', 'B', 'C']),
            path: $this->paths(['A', 'B', 'C', 'A']),
        );
        self::assertSame('small', $cycle->getSizeCategory());
    }

    #[Test]
    public function itReturnsSmallSizeCategoryAtBoundary(): void
    {
        $classes = [];
        $path = [];
        for ($i = 0; $i < 5; $i++) {
            $classes[] = 'C' . $i;
            $path[] = 'C' . $i;
        }
        $path[] = 'C0';

        $cycle = new Cycle(
            classes: $this->paths($classes),
            path: $this->paths($path),
        );
        self::assertSame('small', $cycle->getSizeCategory());
    }

    #[Test]
    public function itReturnsMediumSizeCategory(): void
    {
        $classes = [];
        $path = [];
        for ($i = 0; $i < 10; $i++) {
            $classes[] = 'C' . $i;
            $path[] = 'C' . $i;
        }
        $path[] = 'C0';

        $cycle = new Cycle(
            classes: $this->paths($classes),
            path: $this->paths($path),
        );
        self::assertSame('medium', $cycle->getSizeCategory());
    }

    #[Test]
    public function itReturnsMediumSizeCategoryAtBoundary(): void
    {
        $classes = [];
        $path = [];
        for ($i = 0; $i < 20; $i++) {
            $classes[] = 'C' . $i;
            $path[] = 'C' . $i;
        }
        $path[] = 'C0';

        $cycle = new Cycle(
            classes: $this->paths($classes),
            path: $this->paths($path),
        );
        self::assertSame('medium', $cycle->getSizeCategory());
    }

    #[Test]
    public function itReturnsLargeSizeCategory(): void
    {
        $classes = [];
        $path = [];
        for ($i = 0; $i < 21; $i++) {
            $classes[] = 'C' . $i;
            $path[] = 'C' . $i;
        }
        $path[] = 'C0';

        $cycle = new Cycle(
            classes: $this->paths($classes),
            path: $this->paths($path),
        );
        self::assertSame('large', $cycle->getSizeCategory());
    }

    #[Test]
    public function itConvertsToStructuredData(): void
    {
        $cycle = new Cycle(
            classes: $this->paths(['App\\A', 'App\\B', 'App\\C']),
            path: $this->paths(['App\\A', 'App\\B', 'App\\C', 'App\\A']),
        );

        $data = $cycle->toStructuredData();

        self::assertSame(['App\\A', 'App\\B', 'App\\C', 'App\\A'], $data['cycle']);
        self::assertSame(3, $data['length']);
        self::assertSame('small', $data['category']);
    }

    #[Test]
    public function itKeepsStructuredDataUnambiguousWhenMembersShareAClassName(): void
    {
        $cycle = new Cycle(
            classes: $this->paths(['App\\Billing\\Service', 'App\\Orders\\Service']),
            path: $this->paths(['App\\Billing\\Service', 'App\\Orders\\Service', 'App\\Billing\\Service']),
        );

        // Structured data is for processing, so members are never shortened —
        // not even to the disambiguated label used for display.
        self::assertSame(
            ['App\\Billing\\Service', 'App\\Orders\\Service', 'App\\Billing\\Service'],
            $cycle->toStructuredData()['cycle'],
        );
    }

    #[Test]
    public function itContainsFullPathInStructuredDataForLargeCycle(): void
    {
        $classes = [];
        $path = [];
        for ($i = 0; $i < 25; $i++) {
            $classes[] = 'C' . $i;
            $path[] = 'C' . $i;
        }
        $path[] = 'C0';

        $cycle = new Cycle(
            classes: $this->paths($classes),
            path: $this->paths($path),
        );

        $data = $cycle->toStructuredData();

        self::assertSame(25, $data['length']);
        self::assertSame('large', $data['category']);
        // Full path is preserved in structured data
        self::assertCount(26, $data['cycle']);
        self::assertSame('C0', $data['cycle'][0]);
        self::assertSame('C0', $data['cycle'][25]);
    }

    /**
     * @param list<string> $fqns
     *
     * @return list<SymbolPath>
     */
    private function paths(array $fqns): array
    {
        return array_map(
            static fn(string $fqn): SymbolPath => SymbolPath::fromClassFqn($fqn),
            $fqns,
        );
    }
}
