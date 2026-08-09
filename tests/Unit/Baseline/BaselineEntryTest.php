<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Baseline;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Baseline\BaselineEdge;
use Qualimetrix\Baseline\BaselineEntry;
use Qualimetrix\Baseline\BaselineEntryMode;
use Qualimetrix\Baseline\BaselineIdentity;
use Qualimetrix\Core\Dependency\DependencyType;
use Qualimetrix\Core\Violation\ChannelShape;
use Qualimetrix\Core\Violation\ViolationChannel;

#[CoversClass(BaselineEntry::class)]
final class BaselineEntryTest extends TestCase
{
    #[Test]
    public function itRoundsMagnitudesToSixDecimalPlaces(): void
    {
        $entry = new BaselineEntry(self::identity(), [1.23456789], 1);

        self::assertSame([1.234568], $entry->magnitudes);
    }

    #[Test]
    public function itLeavesValuesAlreadyRoundedByTheirOwnRuleUntouched(): void
    {
        // maintainability.index and computed metrics store round($v, 1);
        // one decimal place passes through six unchanged.
        $entry = new BaselineEntry(self::identity(), [64.3], 1);

        self::assertSame([64.3], $entry->magnitudes);
    }

    #[Test]
    public function itCollapsesNegativeZeroToPositiveZero(): void
    {
        $entry = new BaselineEntry(self::identity(), [-0.0000001], 1);

        self::assertSame([0.0], $entry->magnitudes);
        self::assertSame('[0]', json_encode($entry->magnitudes));
    }

    #[Test]
    public function itStoresMagnitudesAscending(): void
    {
        $entry = new BaselineEntry(self::identity(), [100, 40, 65], 3);

        self::assertSame([40.0, 65.0, 100.0], $entry->magnitudes);
    }

    #[Test]
    public function itRejectsANonFiniteMagnitude(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BaselineEntry(self::identity(), [\INF], 1);
    }

    #[Test]
    public function itRejectsNotANumber(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BaselineEntry(self::identity(), [\NAN], 1);
    }

    #[Test]
    public function itRejectsAMagnitudeListThatDisagreesWithTheCount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BaselineEntry(self::identity(), [10.0, 20.0], 3);
    }

    #[Test]
    public function itRejectsANonPositiveCount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BaselineEntry(self::identity(), null, 0);
    }

    #[Test]
    public function itReadsItsShapeOffItsOwnContents(): void
    {
        self::assertSame(ChannelShape::Magnitude, (new BaselineEntry(self::identity(), [1.0], 1))->shape());
        self::assertSame(ChannelShape::Occurrence, (new BaselineEntry(self::identity(), null, 1))->shape());
    }

    #[Test]
    public function itSerializesTheFieldsOfTheContractInAFixedOrder(): void
    {
        $identity = new BaselineIdentity(
            'class:App\Web\Controller',
            new ViolationChannel('architecture.layer-violation', 'architecture.layer-violation'),
            new BaselineEdge('class:App\Db\Connection', DependencyType::New_),
        );

        $entry = new BaselineEntry($identity, null, 1, BaselineEntryMode::Suppress);

        self::assertSame(
            ['channel', 'edge', 'count', 'mode'],
            array_keys($entry->toArray()),
        );
        self::assertSame(
            ['target' => 'class:App\Db\Connection', 'type' => 'new'],
            $entry->toArray()['edge'],
        );
    }

    #[Test]
    public function itOmitsEdgeMagnitudesAndModeWhenThereAreNone(): void
    {
        $entry = new BaselineEntry(self::identity(), null, 3);

        self::assertSame(
            ['channel' => 'code-smell.goto#code-smell.goto', 'count' => 3],
            $entry->toArray(),
        );
    }

    /**
     * The observable ADR 0017 names outright: a normalized `40.0` is written as
     * `40` and decodes as an `int`. Stated here rather than left to surface
     * as a flaky byte-stability failure somewhere else.
     */
    #[Test]
    public function itWritesAWholeMagnitudeWithoutAZeroFractionAndReloadsItAsAnInt(): void
    {
        $entry = new BaselineEntry(self::identity(), [40.0], 1);

        $json = json_encode($entry->toArray(), \JSON_THROW_ON_ERROR);
        self::assertStringContainsString('"magnitudes":[40]', $json);

        /** @var array{magnitudes: list<int|float>} $decoded */
        $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame(40, $decoded['magnitudes'][0]);

        // And the int reloads into an entry that writes the same bytes again.
        $reloaded = new BaselineEntry(self::identity(), $decoded['magnitudes'], 1);
        self::assertSame($json, json_encode($reloaded->toArray(), \JSON_THROW_ON_ERROR));
    }

    private static function identity(): BaselineIdentity
    {
        return new BaselineIdentity(
            'callable:App\Foo::bar',
            new ViolationChannel('code-smell.goto', 'code-smell.goto'),
        );
    }
}
