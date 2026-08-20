<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Reporting\Formatter\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\AcceptedLevel;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\DeclarationPath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Reporting\Formatter\Support\AcceptedLevelNarrator;

#[CoversClass(AcceptedLevelNarrator::class)]
final class AcceptedLevelNarratorTest extends TestCase
{
    #[Test]
    public function itReturnsNullWhenNoAcceptedLevelIsPresent(): void
    {
        self::assertNull(AcceptedLevelNarrator::describe(self::baseViolation()));
    }

    #[Test]
    public function itDescribesAMagnitudeBreachWithTheCurrentValue(): void
    {
        $violation = self::baseViolation(metricValue: 31)
            ->reportedAsBreach(new AcceptedLevel([25.0], 1));

        self::assertSame('accepted at 25, now 31', AcceptedLevelNarrator::describe($violation));
    }

    #[Test]
    public function itTrimsTrailingZerosOnBothSides(): void
    {
        $violation = self::baseViolation(metricValue: 31.0)
            ->reportedAsBreach(new AcceptedLevel([25.500000], 1));

        self::assertSame('accepted at 25.5, now 31', AcceptedLevelNarrator::describe($violation));
    }

    #[Test]
    public function itListsMultipleAcceptedMagnitudesForAGroupOfMoreThanOne(): void
    {
        $violation = self::baseViolation(metricValue: 40)
            ->reportedAsBreach(new AcceptedLevel([20.0, 30.0], 2));

        self::assertSame('accepted at 20, 30, now 40', AcceptedLevelNarrator::describe($violation));
    }

    #[Test]
    public function itOmitsNowWhenTheMagnitudeChannelHasNoCurrentMetricValue(): void
    {
        $violation = self::baseViolation(metricValue: null)
            ->reportedAsBreach(new AcceptedLevel([25.0], 1));

        self::assertSame('accepted at 25', AcceptedLevelNarrator::describe($violation));
    }

    #[Test]
    public function itOmitsNowWhenTheCurrentMetricValueIsNonFinite(): void
    {
        $violation = self::baseViolation(metricValue: \NAN)
            ->reportedAsBreach(new AcceptedLevel([25.0], 1));

        self::assertSame('accepted at 25', AcceptedLevelNarrator::describe($violation));
    }

    #[Test]
    public function itDescribesAnOccurrenceBreachAsACountWithoutInventingANowValue(): void
    {
        $violation = self::baseViolation(metricValue: null)
            ->reportedAsBreach(new AcceptedLevel(null, 3));

        self::assertSame('accepted at 3 occurrences', AcceptedLevelNarrator::describe($violation));
    }

    #[Test]
    public function itSingularizesASingleOccurrence(): void
    {
        $violation = self::baseViolation(metricValue: null)
            ->reportedAsBreach(new AcceptedLevel(null, 1));

        self::assertSame('accepted at 1 occurrence', AcceptedLevelNarrator::describe($violation));
    }

    #[Test]
    public function itIgnoresAMetricValuePresentOnAnOccurrenceChannel(): void
    {
        // An occurrence channel's magnitudes are null by construction, so the
        // "now" side is never printed even when metricValue happens to be set
        // (a fixed marker such as 1.0, per ChannelShape::Occurrence's docblock).
        $violation = self::baseViolation(metricValue: 1.0)
            ->reportedAsBreach(new AcceptedLevel(null, 3));

        self::assertSame('accepted at 3 occurrences', AcceptedLevelNarrator::describe($violation));
    }

    private static function baseViolation(int|float|null $metricValue = null): Violation
    {
        return new Violation(
            location: new Location(RelativePath::fromString('src/Foo.php'), 10),
            subject: MetricSubject::declaration(DeclarationPath::of(SymbolPath::forMethod('App', 'Foo', 'bar'), RelativePath::fromString('src/Foo.php'), DeclarationOrdinal::fromRank(0))),
            symbolPath: SymbolPath::forMethod('App', 'Foo', 'bar'),
            ruleName: 'complexity.cyclomatic',
            violationCode: 'complexity.cyclomatic.callable',
            message: 'Cyclomatic complexity exceeds threshold',
            severity: Severity::Warning,
            metricValue: $metricValue,
        );
    }
}
