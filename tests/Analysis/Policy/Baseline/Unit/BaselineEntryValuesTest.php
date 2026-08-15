<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\ViolationChannel;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryMode;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryRejection;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryValues;
use Qualimetrix\Analysis\Policy\Baseline\BaselineIdentity;
use Qualimetrix\Analysis\Policy\Baseline\InertEntryReason;

#[CoversClass(BaselineEntryValues::class)]
final class BaselineEntryValuesTest extends TestCase
{
    #[Test]
    public function itDecodesCountMagnitudesAndMode(): void
    {
        $values = BaselineEntryValues::decode([
            'count' => 2,
            'magnitudes' => [1, 2.5],
            'mode' => 'suppress',
        ]);

        self::assertSame(2, $values->count);
        self::assertSame([1, 2.5], $values->magnitudes);
        self::assertSame(BaselineEntryMode::Suppress, $values->mode);
    }

    #[Test]
    public function itTreatsAbsentAndNullOptionalValuesAsAbsent(): void
    {
        $absent = BaselineEntryValues::decode(['count' => 1]);
        $null = BaselineEntryValues::decode(['count' => 1, 'magnitudes' => null, 'mode' => null]);

        self::assertNull($absent->magnitudes);
        self::assertNull($absent->mode);
        self::assertNull($null->magnitudes);
        self::assertNull($null->mode);
    }

    #[Test]
    public function itRejectsAbsentNullAndNonIntegerCountsWithTheExactMalformedDetail(): void
    {
        foreach ([[], ['count' => null], ['count' => 1.0]] as $raw) {
            try {
                BaselineEntryValues::decode($raw);
                self::fail('Invalid count must be rejected.');
            } catch (BaselineEntryRejection $rejection) {
                self::assertSame(InertEntryReason::Malformed, $rejection->reason);
                self::assertSame('"count" must be an integer', $rejection->getMessage());
            }
        }
    }

    #[Test]
    public function itRejectsAMapAndANonNumericMagnitudeMemberWithExactDetails(): void
    {
        $cases = [
            [['count' => 1, 'magnitudes' => ['value' => 1]], '"magnitudes" must be a JSON array'],
            [['count' => 1, 'magnitudes' => ['one']], '"magnitudes" must hold numbers, found "one"'],
        ];

        foreach ($cases as [$raw, $detail]) {
            try {
                BaselineEntryValues::decode($raw);
                self::fail('Invalid magnitudes must be rejected.');
            } catch (BaselineEntryRejection $rejection) {
                self::assertSame(InertEntryReason::Malformed, $rejection->reason);
                self::assertSame($detail, $rejection->getMessage());
            }
        }
    }

    #[Test]
    public function itLeavesFiniteValidationToTheBaselineEntryInvariant(): void
    {
        $values = BaselineEntryValues::decode(['count' => 1, 'magnitudes' => [\INF]]);
        $identity = new BaselineIdentity(
            'project:',
            new ViolationChannel('complexity.cyclomatic', 'complexity.cyclomatic.callable'),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be finite');

        new BaselineEntry($identity, $values->magnitudes, $values->count, $values->mode);
    }

    #[Test]
    public function itRejectsUnknownAndNonStringModesAsUnrecognized(): void
    {
        foreach (['ceiling', 1] as $mode) {
            try {
                BaselineEntryValues::decode(['count' => 1, 'mode' => $mode]);
                self::fail('Unknown mode must be rejected.');
            } catch (BaselineEntryRejection $rejection) {
                self::assertSame(InertEntryReason::UnrecognizedMode, $rejection->reason);
                self::assertStringStartsWith('"mode" is not a recognized mode:', $rejection->getMessage());
            }
        }
    }
}
