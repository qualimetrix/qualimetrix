<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdParser;

final class ThresholdParserTest extends TestCase
{
    #[Test]
    public function emptyConfigReturnsDefaults(): void
    {
        $result = ThresholdParser::parse([], 'warning', 'error', 10, 20);

        self::assertSame(10, $result['warning']);
        self::assertSame(20, $result['error']);
    }

    #[Test]
    public function thresholdSetsBothValues(): void
    {
        $result = ThresholdParser::parse(['threshold' => 15], 'warning', 'error', 10, 20);

        self::assertSame(15, $result['warning']);
        self::assertSame(15, $result['error']);
    }

    #[Test]
    public function thresholdZeroSetsBothToZero(): void
    {
        $result = ThresholdParser::parse(['threshold' => 0], 'warning', 'error', 10, 20);

        self::assertSame(0, $result['warning']);
        self::assertSame(0, $result['error']);
    }

    #[Test]
    public function thresholdNullFallsBackToDefaults(): void
    {
        $result = ThresholdParser::parse(['threshold' => null], 'warning', 'error', 10, 20);

        self::assertSame(10, $result['warning']);
        self::assertSame(20, $result['error']);
    }

    #[Test]
    public function warningAndErrorParsedExplicitly(): void
    {
        $result = ThresholdParser::parse(['warning' => 5, 'error' => 15], 'warning', 'error', 10, 20);

        self::assertSame(5, $result['warning']);
        self::assertSame(15, $result['error']);
    }

    #[Test]
    public function onlyWarningParsedWithDefaultError(): void
    {
        $result = ThresholdParser::parse(['warning' => 5], 'warning', 'error', 10, 20);

        self::assertSame(5, $result['warning']);
        self::assertSame(20, $result['error']);
    }

    #[Test]
    public function thresholdWithWarningThrowsException(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage('Cannot mix "threshold" with "warning"/"error"');

        ThresholdParser::parse(['threshold' => 15, 'warning' => 10], 'warning', 'error', 10, 20);
    }

    #[Test]
    public function thresholdWithErrorThrowsException(): void
    {
        self::expectException(InvalidArgumentException::class);

        ThresholdParser::parse(['threshold' => 15, 'error' => 20], 'warning', 'error', 10, 20);
    }

    #[Test]
    public function thresholdWithLegacyWarningKeyThrowsException(): void
    {
        self::expectException(InvalidArgumentException::class);

        ThresholdParser::parse(
            ['threshold' => 15, 'warningThreshold' => 10],
            'warning',
            'error',
            10,
            20,
            legacyKeys: ['warning' => ['warningThreshold']],
        );
    }

    #[Test]
    public function thresholdWithLegacyErrorKeyThrowsException(): void
    {
        self::expectException(InvalidArgumentException::class);

        ThresholdParser::parse(
            ['threshold' => 15, 'errorThreshold' => 20],
            'warning',
            'error',
            10,
            20,
            legacyKeys: ['error' => ['errorThreshold']],
        );
    }

    #[Test]
    public function legacyKeysUsedAsFallback(): void
    {
        $result = ThresholdParser::parse(
            ['warningThreshold' => 5, 'errorThreshold' => 15],
            'warning',
            'error',
            10,
            20,
            legacyKeys: ['warning' => ['warningThreshold'], 'error' => ['errorThreshold']],
        );

        self::assertSame(5, $result['warning']);
        self::assertSame(15, $result['error']);
    }

    #[Test]
    public function primaryKeysTakePrecedenceOverLegacy(): void
    {
        $result = ThresholdParser::parse(
            ['warning' => 7, 'error' => 17, 'warningThreshold' => 5, 'errorThreshold' => 15],
            'warning',
            'error',
            10,
            20,
            legacyKeys: ['warning' => ['warningThreshold'], 'error' => ['errorThreshold']],
        );

        self::assertSame(7, $result['warning']);
        self::assertSame(17, $result['error']);
    }

    #[Test]
    public function customThresholdKey(): void
    {
        $result = ThresholdParser::parse(
            ['param_threshold' => 70],
            'param_warning',
            'param_error',
            80.0,
            50.0,
            thresholdKey: 'param_threshold',
        );

        self::assertSame(70, $result['warning']);
        self::assertSame(70, $result['error']);
    }

    #[Test]
    public function customKeysWithLegacyFallback(): void
    {
        $result = ThresholdParser::parse(
            ['maxWarning' => 25],
            'max_warning',
            'max_error',
            30,
            50,
            legacyKeys: ['warning' => ['maxWarning'], 'error' => ['maxError']],
        );

        self::assertSame(25, $result['warning']);
        self::assertSame(50, $result['error']);
    }

    #[Test]
    public function floatThresholds(): void
    {
        $result = ThresholdParser::parse(['threshold' => 0.5], 'warning', 'error', 0.3, 0.7);

        self::assertSame(0.5, $result['warning']);
        self::assertSame(0.5, $result['error']);
    }

    #[Test]
    public function itAcceptsACamelCaseLegacyKeyForACustomThresholdKey(): void
    {
        // Simulates the key RuleOptionsFactory/RuleOptionsParser produce once
        // they normalize a composite `$thresholdKey` (e.g. 'vo-threshold',
        // 'param_threshold') to camelCase before fromArray() runs.
        $result = ThresholdParser::parse(
            ['voThreshold' => 10],
            'vo-warning',
            'vo-error',
            8,
            12,
            'vo-threshold',
            legacyKeys: ['threshold' => ['voThreshold']],
        );

        self::assertSame(10, $result['warning']);
        self::assertSame(10, $result['error']);
    }

    #[Test]
    public function itGivesThePrimaryThresholdKeyPrecedenceOverTheLegacyThresholdKey(): void
    {
        $result = ThresholdParser::parse(
            ['vo-threshold' => 12, 'voThreshold' => 5],
            'vo-warning',
            'vo-error',
            8,
            12,
            'vo-threshold',
            legacyKeys: ['threshold' => ['voThreshold']],
        );

        self::assertSame(12, $result['warning']);
        self::assertSame(12, $result['error']);
    }

    #[Test]
    public function itThrowsWhenTheLegacyThresholdKeyConflictsWithTheWarningKey(): void
    {
        self::expectException(InvalidArgumentException::class);

        ThresholdParser::parse(
            ['voThreshold' => 10, 'vo-warning' => 8],
            'vo-warning',
            'vo-error',
            8,
            12,
            'vo-threshold',
            legacyKeys: ['threshold' => ['voThreshold']],
        );
    }

    // ---------------------------------------------------------------------
    // Characterization tests.
    //
    // These pin the exact edge-case semantics of parse() — key *presence* vs.
    // key *value*, first-match-wins ordering, null handling — so that any
    // restructuring of the parser can be proven behavior-preserving. They are
    // deliberately written against observed behavior, quirks included.
    // ---------------------------------------------------------------------

    #[Test]
    public function itReturnsTheResultKeyedByWarningThenError(): void
    {
        self::assertSame(
            ['warning' => 10, 'error' => 20],
            ThresholdParser::parse([], 'warning', 'error', 10, 20),
        );
    }

    #[Test]
    public function itDetectsTheConflictByKeyPresenceSoANullThresholdStillClashesWithWarning(): void
    {
        // The mixing check uses array_key_exists(), not the value: an explicit
        // `threshold: ~` next to `warning:` is still a configuration error.
        self::expectException(InvalidArgumentException::class);

        ThresholdParser::parse(['threshold' => null, 'warning' => 5], 'warning', 'error', 10, 20);
    }

    #[Test]
    public function itDetectsTheConflictWhenTheWarningKeyIsPresentButNull(): void
    {
        self::expectException(InvalidArgumentException::class);

        ThresholdParser::parse(['threshold' => 15, 'warning' => null], 'warning', 'error', 10, 20);
    }

    #[Test]
    public function itDetectsTheConflictWhenALegacyErrorKeyIsPresentButNull(): void
    {
        self::expectException(InvalidArgumentException::class);

        ThresholdParser::parse(
            ['threshold' => 15, 'errorThreshold' => null],
            'warning',
            'error',
            10,
            20,
            legacyKeys: ['error' => ['errorThreshold']],
        );
    }

    #[Test]
    public function itNamesThePrimaryKeysInTheConflictMessageEvenWhenALegacyKeyTriggeredIt(): void
    {
        self::expectException(InvalidArgumentException::class);
        self::expectExceptionMessage(
            'Cannot mix "vo-threshold" with "vo-warning"/"vo-error". Use either "vo-threshold" alone'
            . ' (simple mode) or "vo-warning"/"vo-error" (graduated mode).',
        );

        ThresholdParser::parse(
            ['voThreshold' => 10, 'voWarning' => 8],
            'vo-warning',
            'vo-error',
            8,
            12,
            'vo-threshold',
            legacyKeys: ['threshold' => ['voThreshold'], 'warning' => ['voWarning']],
        );
    }

    #[Test]
    public function itFallsBackToDefaultsWhenTheOnlyLegacyThresholdKeyIsNull(): void
    {
        $result = ThresholdParser::parse(
            ['voThreshold' => null],
            'vo-warning',
            'vo-error',
            8,
            12,
            'vo-threshold',
            legacyKeys: ['threshold' => ['voThreshold']],
        );

        self::assertSame(['warning' => 8, 'error' => 12], $result);
    }

    #[Test]
    public function itResolvesTheThresholdKeyByPresenceSoTheFirstListedLegacyKeyWinsEvenWhenNull(): void
    {
        // Quirk, pinned deliberately: threshold-key resolution is presence-based
        // and stops at the first match, so a null first legacy key shadows a
        // populated second one and the defaults are used.
        $result = ThresholdParser::parse(
            ['firstLegacy' => null, 'secondLegacy' => 7],
            'warning',
            'error',
            10,
            20,
            'threshold',
            legacyKeys: ['threshold' => ['firstLegacy', 'secondLegacy']],
        );

        self::assertSame(['warning' => 10, 'error' => 20], $result);
    }

    #[Test]
    public function itPrefersThePrimaryThresholdKeyEvenWhenItIsNullAndALegacyKeyHasAValue(): void
    {
        $result = ThresholdParser::parse(
            ['threshold' => null, 'legacyThreshold' => 7],
            'warning',
            'error',
            10,
            20,
            'threshold',
            legacyKeys: ['threshold' => ['legacyThreshold']],
        );

        self::assertSame(['warning' => 10, 'error' => 20], $result);
    }

    #[Test]
    public function itFallsBackToTheLegacyWarningKeyWhenThePrimaryWarningIsExplicitlyNull(): void
    {
        // Unlike threshold resolution, warning/error resolution is value-based:
        // a null primary value is skipped in favor of a non-null legacy value.
        $result = ThresholdParser::parse(
            ['warning' => null, 'warningThreshold' => 5],
            'warning',
            'error',
            10,
            20,
            legacyKeys: ['warning' => ['warningThreshold']],
        );

        self::assertSame(['warning' => 5, 'error' => 20], $result);
    }

    #[Test]
    public function itSkipsNullLegacyWarningValuesAndUsesTheNextNonNullLegacyKey(): void
    {
        $result = ThresholdParser::parse(
            ['firstLegacy' => null, 'secondLegacy' => 5],
            'warning',
            'error',
            10,
            20,
            legacyKeys: ['warning' => ['firstLegacy', 'secondLegacy']],
        );

        self::assertSame(['warning' => 5, 'error' => 20], $result);
    }

    #[Test]
    public function itFallsBackToDefaultsWhenEveryWarningAndErrorCandidateIsNull(): void
    {
        $result = ThresholdParser::parse(
            ['warning' => null, 'error' => null, 'warningThreshold' => null, 'errorThreshold' => null],
            'warning',
            'error',
            10,
            20,
            legacyKeys: ['warning' => ['warningThreshold'], 'error' => ['errorThreshold']],
        );

        self::assertSame(['warning' => 10, 'error' => 20], $result);
    }

    #[Test]
    public function itKeepsAZeroWarningInsteadOfFallingBackToTheDefault(): void
    {
        $result = ThresholdParser::parse(['warning' => 0], 'warning', 'error', 10, 20);

        self::assertSame(['warning' => 0, 'error' => 20], $result);
    }

    #[Test]
    public function itKeepsAZeroLegacyWarningInsteadOfFallingBackToTheDefault(): void
    {
        $result = ThresholdParser::parse(
            ['warningThreshold' => 0],
            'warning',
            'error',
            10,
            20,
            legacyKeys: ['warning' => ['warningThreshold']],
        );

        self::assertSame(['warning' => 0, 'error' => 20], $result);
    }

    #[Test]
    public function itUsesTheDefaultWarningWhenOnlyTheErrorKeyIsConfigured(): void
    {
        $result = ThresholdParser::parse(['error' => 15], 'warning', 'error', 10, 20);

        self::assertSame(['warning' => 10, 'error' => 15], $result);
    }

    #[Test]
    public function itIgnoresConfigKeysThatAreNeitherPrimaryNorDeclaredAsLegacy(): void
    {
        $result = ThresholdParser::parse(
            ['warningThreshold' => 5, 'errorThreshold' => 15],
            'warning',
            'error',
            10,
            20,
        );

        self::assertSame(['warning' => 10, 'error' => 20], $result);
    }

    #[Test]
    public function itIgnoresLegacyWarningKeysWhenTheThresholdKeyIsAbsentAndNoCandidateMatches(): void
    {
        $result = ThresholdParser::parse(
            ['unrelated' => 1],
            'warning',
            'error',
            10,
            20,
            legacyKeys: ['warning' => ['warningThreshold'], 'error' => ['errorThreshold'], 'threshold' => ['thresholdAlias']],
        );

        self::assertSame(['warning' => 10, 'error' => 20], $result);
    }

    #[Test]
    public function itAppliesTheLegacyErrorFallbackIndependentlyOfTheWarningResolution(): void
    {
        $result = ThresholdParser::parse(
            ['warning' => 5, 'errorThreshold' => 15],
            'warning',
            'error',
            10,
            20,
            legacyKeys: ['warning' => ['warningThreshold'], 'error' => ['errorThreshold']],
        );

        self::assertSame(['warning' => 5, 'error' => 15], $result);
    }

    #[Test]
    public function itDoesNotTreatALegacyWarningKeyAsAThresholdKey(): void
    {
        // legacyKeys are scoped per primary key; a key listed under 'warning'
        // never satisfies the threshold lookup.
        $result = ThresholdParser::parse(
            ['warningThreshold' => 5],
            'warning',
            'error',
            10,
            20,
            legacyKeys: ['warning' => ['warningThreshold'], 'threshold' => ['thresholdAlias']],
        );

        self::assertSame(['warning' => 5, 'error' => 20], $result);
    }

    #[Test]
    public function itMixesIntegerAndFloatDefaultsWithoutCoercion(): void
    {
        $result = ThresholdParser::parse(['warning' => 5], 'warning', 'error', 10.5, 20.5);

        self::assertSame(['warning' => 5, 'error' => 20.5], $result);
    }

    #[Test]
    public function itPropagatesTheLegacyThresholdValueToBothWarningAndError(): void
    {
        $result = ThresholdParser::parse(
            ['maxThreshold' => 0.25],
            'max_warning',
            'max_error',
            0.8,
            0.95,
            'max_threshold',
            legacyKeys: ['threshold' => ['maxThreshold'], 'warning' => ['maxWarning'], 'error' => ['maxError']],
        );

        self::assertSame(['warning' => 0.25, 'error' => 0.25], $result);
    }
}
