<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Baseline;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Baseline\Baseline;
use Qualimetrix\Baseline\BaselineEntry;
use Qualimetrix\Baseline\BaselineIdentity;
use Qualimetrix\Baseline\Filter\BaselineFilter;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Tests\Support\Violation\ViolationFactory;

#[CoversClass(BaselineFilter::class)]
final class BaselineFilterTest extends TestCase
{
    #[Test]
    public function itFiltersOutAViolationRecordedInTheBaseline(): void
    {
        $violation = ViolationFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 15);

        $filter = new BaselineFilter(self::baselineFor($violation));

        self::assertFalse($filter->shouldInclude($violation));
    }

    #[Test]
    public function itPassesAViolationTheBaselineDoesNotHold(): void
    {
        $recorded = ViolationFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 15);
        $fresh = ViolationFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'other'), 15);

        $filter = new BaselineFilter(self::baselineFor($recorded));

        self::assertTrue($filter->shouldInclude($fresh));
    }

    #[Test]
    public function itPassesAViolationOnAnotherChannelOfTheSameSymbol(): void
    {
        $symbol = SymbolPath::forMethod('App', 'Foo', 'bar');
        $recorded = ViolationFactory::magnitude($symbol, 15);
        $otherChannel = ViolationFactory::occurrence($symbol);

        $filter = new BaselineFilter(self::baselineFor($recorded));

        self::assertTrue($filter->shouldInclude($otherChannel));
    }

    /**
     * The reason the edge is part of the identity: without it, swapping one
     * forbidden dependency for another leaves the count unchanged and passes
     * in silence.
     */
    #[Test]
    public function itPassesAForbiddenEdgeSwappedForAnother(): void
    {
        $source = SymbolPath::forClass('App\Web', 'Controller');
        $recorded = ViolationFactory::edge($source, SymbolPath::forClass('App\Db', 'Connection'));
        $swapped = ViolationFactory::edge($source, SymbolPath::forClass('App\Db', 'Statement'));

        $filter = new BaselineFilter(self::baselineFor($recorded));

        self::assertFalse($filter->shouldInclude($recorded));
        self::assertTrue($filter->shouldInclude($swapped));
    }

    /**
     * An entry the loader could not apply is not in `entries` at all, so it
     * cannot suppress — the governing invariant, observed from the outside.
     */
    #[Test]
    public function itSuppressesNothingForAnEmptyBaseline(): void
    {
        $filter = new BaselineFilter(new Baseline(
            generated: new DateTimeImmutable(),
            scope: ['src'],
            entries: [],
        ));

        self::assertTrue($filter->shouldInclude(
            ViolationFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 15),
        ));
    }

    #[Test]
    public function itReportsEntriesWhoseGroupDidNotAppear(): void
    {
        $repaired = ViolationFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'repaired'), 15);
        $stillFailing = ViolationFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'failing'), 15);

        $filter = new BaselineFilter(self::baselineFor($repaired, $stillFailing));

        $resolved = $filter->getResolvedFromBaseline([$stillFailing]);

        self::assertCount(1, $resolved);
        self::assertSame('method:App\Foo::repaired', $resolved[0]->identity->symbolKey);
    }

    #[Test]
    public function itReportsNothingResolvedWhenEveryGroupStillAppears(): void
    {
        $violation = ViolationFactory::magnitude(SymbolPath::forMethod('App', 'Foo', 'bar'), 15);

        $filter = new BaselineFilter(self::baselineFor($violation));

        self::assertSame([], $filter->getResolvedFromBaseline([$violation]));
    }

    #[Test]
    public function itCollapsesAGroupIntoOneMeasuredIdentityKey(): void
    {
        $symbol = SymbolPath::forFile(RelativePath::fromString('src/dup.php'));

        $keys = BaselineFilter::measuredIdentityKeys([
            ViolationFactory::magnitude($symbol, 100, 'duplication.code-duplication', 'duplication.code-duplication'),
            ViolationFactory::magnitude($symbol, 40, 'duplication.code-duplication', 'duplication.code-duplication'),
        ]);

        self::assertCount(1, $keys);
    }

    private static function baselineFor(Violation ...$violations): Baseline
    {
        $entries = [];
        foreach ($violations as $violation) {
            $identity = BaselineIdentity::forViolation($violation);
            $entries[] = new BaselineEntry(
                $identity,
                $violation->metricValue !== null && $identity->edge === null ? [$violation->metricValue] : null,
                1,
            );
        }

        return new Baseline(
            generated: new DateTimeImmutable(),
            scope: ['src'],
            entries: $entries,
        );
    }
}
