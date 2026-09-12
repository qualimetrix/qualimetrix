<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdParser;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionValueWrittenness;

/**
 * `RuleOptionValueWrittenness::isWritten()` is the one predicate both merge
 * sites ({@see \Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsFactory},
 * {@see \Qualimetrix\Analysis\Finding\Configuration\FindingConfigurationResolver})
 * ask before letting an overlay's value erase a base value. `ThresholdParser`
 * is outside this round's file set and keeps asking the same question through
 * its own `isset()` — this test proves the two agree behaviourally rather than
 * asserting they share one line of code, for every value where the two
 * COULD have diverged: `~` (null), `false`, `0`, `''`, and a key written under
 * two different spellings (where only the writtenness question, not key
 * lookup, is shared).
 */
#[CoversClass(RuleOptionValueWrittenness::class)]
final class RuleOptionValueWrittennessTest extends TestCase
{
    #[Test]
    public function itTreatsNullAsUnwritten(): void
    {
        self::assertFalse(RuleOptionValueWrittenness::isWritten(null));
    }

    #[Test]
    public function itTreatsFalseZeroAndEmptyStringAsWritten(): void
    {
        self::assertTrue(RuleOptionValueWrittenness::isWritten(false));
        self::assertTrue(RuleOptionValueWrittenness::isWritten(0));
        self::assertTrue(RuleOptionValueWrittenness::isWritten(''));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideValues(): iterable
    {
        yield 'null (the `~` case)' => [null];
        yield 'false' => [false];
        yield 'zero' => [0];
        yield 'empty string' => [''];
    }

    /**
     * Agreement with `ThresholdParser::parse()`'s own `isset()`-based
     * writtenness, for a key present with each value: `parse()` selects the
     * `threshold` (simple) mode exactly when `RuleOptionValueWrittenness`
     * says the key is written.
     */
    #[Test]
    #[DataProvider('provideValues')]
    public function itAgreesWithThresholdParserOnWhetherAKeyIsWritten(mixed $value): void
    {
        $result = ThresholdParser::parse(['threshold' => $value], 'warning', 'error', 10, 20);

        if (RuleOptionValueWrittenness::isWritten($value)) {
            self::assertSame($value, $result['warning'], 'A written threshold must select simple mode.');
            self::assertSame($value, $result['error'], 'A written threshold must select simple mode.');
        } else {
            self::assertSame(10, $result['warning'], 'An unwritten threshold must fall back to the default.');
            self::assertSame(20, $result['error'], 'An unwritten threshold must fall back to the default.');
        }
    }

    /**
     * The case where the two predicates could genuinely diverge: the SAME
     * concept written under two different spellings. `ThresholdParser` asks
     * "was THIS exact candidate key written" (its `firstWrittenKey()` walks
     * a list of alternate spellings); the merge asks "was the value already
     * sitting at THIS literal key written". Both still answer the plain
     * value-level question — written or not — identically; only the KEY
     * lookup differs, and that is deliberately not shared (see
     * `RuleOptionThresholdShorthand`'s and `FindingConfigurationResolver`'s
     * docblocks).
     */
    #[Test]
    public function itAsksOnlyAboutTheValueNotAboutWhichSpellingCarriesIt(): void
    {
        $writtenUnderPrimarySpelling = ['max_warning' => 5];
        $writtenUnderLegacySpelling = ['maxWarning' => 5];

        $viaPrimary = ThresholdParser::parse($writtenUnderPrimarySpelling, 'max_warning', 'max_error', 10, 20);
        $viaLegacy = ThresholdParser::parse($writtenUnderLegacySpelling, 'max_warning', 'max_error', 10, 20, legacyKeys: ['warning' => ['maxWarning']]);

        self::assertSame($viaPrimary['warning'], $viaLegacy['warning']);
        self::assertTrue(RuleOptionValueWrittenness::isWritten($writtenUnderPrimarySpelling['max_warning']));
        self::assertTrue(RuleOptionValueWrittenness::isWritten($writtenUnderLegacySpelling['maxWarning']));
    }
}
