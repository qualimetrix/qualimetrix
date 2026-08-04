<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Core\Comparison;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Comparison\ComparisonStatus;
use Qualimetrix\Core\Comparison\ResolutionReason;

#[CoversClass(ComparisonStatus::class)]
#[CoversClass(ResolutionReason::class)]
final class ComparisonStatusTest extends TestCase
{
    /**
     * The precedence ordering, written out in full. Pinning it as a list
     * rather than as spot checks is deliberate: the ordering exists because
     * two conditions can hold at once, and an implementer without it will pick
     * either defensible answer.
     *
     * @return list<ComparisonStatus>
     */
    private static function orderedStatuses(): array
    {
        return [
            ComparisonStatus::Orphaned,
            ComparisonStatus::Unobserved,
            ComparisonStatus::Incompatible,
            ComparisonStatus::Suppressed,
            ComparisonStatus::Regressed,
            ComparisonStatus::Resolved,
            ComparisonStatus::Improved,
            ComparisonStatus::Matched,
        ];
    }

    #[Test]
    public function itOrdersTheStatusesStrictly(): void
    {
        $previous = null;
        foreach (self::orderedStatuses() as $status) {
            if ($previous !== null) {
                self::assertLessThan(
                    $status->precedence(),
                    $previous->precedence(),
                    "{$previous->value} must be decided before {$status->value}",
                );
            }

            $previous = $status;
        }
    }

    /**
     * `new` describes a current finding with no recorded entry, so it never
     * competes with the statuses that describe a recorded one.
     */
    #[Test]
    public function itKeepsNewOutOfThePrecedenceOrdering(): void
    {
        self::assertFalse(ComparisonStatus::New->participatesInPrecedence());

        foreach (self::orderedStatuses() as $status) {
            self::assertTrue($status->participatesInPrecedence(), "{$status->value} must be ordered");
            self::assertLessThan(ComparisonStatus::New->precedence(), $status->precedence());
        }
    }

    #[Test]
    public function itOrdersEveryCaseExactlyOnce(): void
    {
        $ordered = self::orderedStatuses();
        $ordered[] = ComparisonStatus::New;

        self::assertEqualsCanonicalizing(
            ComparisonStatus::cases(),
            $ordered,
            'every status must have a documented position, or a bucket ships without a counter',
        );

        $precedences = array_map(static fn(ComparisonStatus $s): int => $s->precedence(), $ordered);
        self::assertSame(\count($precedences), \count(array_unique($precedences)), 'no two statuses may tie');
    }

    #[Test]
    public function itExposesStableSerializedNames(): void
    {
        self::assertSame('regressed', ComparisonStatus::Regressed->value);
        self::assertSame('unobserved', ComparisonStatus::Unobserved->value);
        self::assertSame('fixed', ResolutionReason::Fixed->value);
        self::assertSame('policy', ResolutionReason::Policy->value);
    }
}
