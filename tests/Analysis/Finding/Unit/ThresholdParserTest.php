<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;

use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\SectionDeclaration;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\Shorthand;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Analysis\Configuration\Document\DocumentComposer;
use Qualimetrix\Analysis\Configuration\Document\DocumentSchema;
use Qualimetrix\Analysis\Finding\Contract\Rule\BandDirection;
use Qualimetrix\Analysis\Finding\Contract\Rule\ResolvedRuleOptionValues;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionBand;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdParser;

final class ThresholdParserTest extends TestCase
{
    #[Test]
    public function itFallsBackToDefaultsForAnEmptyConfig(): void
    {
        $result = self::parse([], 'warning', 'error', 10, 20);

        self::assertSame(10, $result['warning']);
        self::assertSame(20, $result['error']);
    }

    #[Test]
    public function itUsesTheThresholdKeyForBothWarningAndError(): void
    {
        $result = self::parse(['threshold' => 15], 'warning', 'error', 10, 20);

        self::assertSame(15, $result['warning']);
        self::assertSame(15, $result['error']);
    }

    #[Test]
    public function itKeepsAZeroThresholdInsteadOfFallingBackToDefaults(): void
    {
        $result = self::parse(['threshold' => 0], 'warning', 'error', 10, 20);

        self::assertSame(0, $result['warning']);
        self::assertSame(0, $result['error']);
    }

    #[Test]
    public function itFallsBackToDefaultsWhenThresholdIsNull(): void
    {
        $result = self::parse(['threshold' => null], 'warning', 'error', 10, 20);

        self::assertSame(10, $result['warning']);
        self::assertSame(20, $result['error']);
    }

    #[Test]
    public function itUsesExplicitWarningAndErrorValues(): void
    {
        $result = self::parse(['warning' => 5, 'error' => 15], 'warning', 'error', 10, 20);

        self::assertSame(5, $result['warning']);
        self::assertSame(15, $result['error']);
    }

    #[Test]
    public function itUsesTheDefaultErrorWhenOnlyWarningIsConfigured(): void
    {
        $result = self::parse(['warning' => 5], 'warning', 'error', 10, 20);

        self::assertSame(5, $result['warning']);
        self::assertSame(20, $result['error']);
    }

    #[Test]
    public function itRejectsThresholdMixedWithWarning(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('"rules.fixture" in configuration file "/project/qmx.yaml" writes both "threshold" and "warning" in one layer; "threshold" is shorthand for "warning" and "error" — write either the shorthand or the full keys in one layer.');

        self::parse(['threshold' => 15, 'warning' => 10], 'warning', 'error', 10, 20);
    }

    #[Test]
    public function itRejectsThresholdMixedWithError(): void
    {
        self::expectException(ConfigurationRefusal::class);

        self::parse(['threshold' => 15, 'error' => 20], 'warning', 'error', 10, 20);
    }

    #[Test]
    public function itRejectsThresholdMixedWithALegacyWarningKey(): void
    {
        self::expectException(ConfigurationRefusal::class);

        self::parse(
            ['threshold' => 15, 'warningThreshold' => 10],
            'warning',
            'error',
            10,
            20,
            legacyKeys: ['warning' => ['warningThreshold']],
        );
    }

    #[Test]
    public function itRejectsThresholdMixedWithALegacyErrorKey(): void
    {
        self::expectException(ConfigurationRefusal::class);

        self::parse(
            ['threshold' => 15, 'errorThreshold' => 20],
            'warning',
            'error',
            10,
            20,
            legacyKeys: ['error' => ['errorThreshold']],
        );
    }

    #[Test]
    public function itFallsBackToLegacyKeysWhenPrimaryKeysAreAbsent(): void
    {
        $result = self::parse(
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
    public function itRefusesPrimaryKeysOverlappingDeclaredSyntheticShorthands(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('"rules.fixture" in configuration file "/project/qmx.yaml" writes both "warningThreshold" and "warning" in one layer; "warning-threshold" is shorthand for "warning" — write either the shorthand or the full keys in one layer.');
        self::parse(['warning' => 7, 'error' => 17, 'warningThreshold' => 5, 'errorThreshold' => 15], 'warning', 'error', 10, 20, legacyKeys: ['warning' => ['warningThreshold'], 'error' => ['errorThreshold']]);
    }

    #[Test]
    public function itParsesACustomThresholdKeyIntoBothWarningAndError(): void
    {
        $result = self::parse(
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
    public function itFallsBackToALegacyKeyForACustomPrimaryKey(): void
    {
        $result = self::parse(
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
    public function itParsesAFloatThresholdIntoBothWarningAndError(): void
    {
        $result = self::parse(['threshold' => 0.5], 'warning', 'error', 0.3, 0.7);

        self::assertSame(0.5, $result['warning']);
        self::assertSame(0.5, $result['error']);
    }

    #[Test]
    public function itAcceptsACamelCaseLegacyKeyForACustomThresholdKey(): void
    {
        $result = self::parse(
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
    public function itRefusesTwoAuthoredSpellingsOfTheThresholdInOneLayer(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('Keys "vo-threshold" and "voThreshold" in "rules.fixture" in configuration file "/project/qmx.yaml" are two spellings of one key, and a layer may set it only once. Keep one of them.');
        self::parse(['vo-threshold' => 12, 'voThreshold' => 5], 'vo-warning', 'vo-error', 8, 12, 'vo-threshold', legacyKeys: ['threshold' => ['voThreshold']]);
    }

    #[Test]
    public function itThrowsWhenTheLegacyThresholdKeyConflictsWithTheWarningKey(): void
    {
        self::expectException(ConfigurationRefusal::class);

        self::parse(
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
    // These pin the exact edge-case semantics of parse() — what counts as a
    // key having been written, first-match-wins ordering, null handling — so
    // that any restructuring of the parser can be proven behavior-preserving.
    //
    // Every question the parser asks is asked of a key's VALUE: `~` is the
    // author leaving that key's own value to the default, never a mode
    // selection and never a mixing partner. The cases below that used to pin
    // the opposite are kept, inverted, because both readings were live: a
    // presence reading refused `threshold: ~` beside `warning: 5` as a mix
    // with nothing, and let a `~` alias shadow a populated one behind it.
    // ---------------------------------------------------------------------

    #[Test]
    public function itReturnsTheResultKeyedByWarningThenError(): void
    {
        self::assertSame(
            ['warning' => 10, 'error' => 20],
            self::parse([], 'warning', 'error', 10, 20),
        );
    }

    #[Test]
    public function itSeesNoConflictWhenTheThresholdKeyBesideWarningIsWrittenNull(): void
    {
        // There is one value in this document, and it is `warning`'s. A
        // refusal here would name a mix of a written value with nothing.
        $result = self::parse(['threshold' => null, 'warning' => 5], 'warning', 'error', 10, 20);

        self::assertSame(['warning' => 5, 'error' => 20], $result);
    }

    #[Test]
    public function itSeesNoConflictWhenTheWarningKeyBesideThresholdIsWrittenNull(): void
    {
        $result = self::parse(['threshold' => 15, 'warning' => null], 'warning', 'error', 10, 20);

        self::assertSame(['warning' => 15, 'error' => 15], $result);
    }

    #[Test]
    public function itSeesNoConflictWhenALegacyErrorKeyBesideThresholdIsWrittenNull(): void
    {
        $result = self::parse(
            ['threshold' => 15, 'errorThreshold' => null],
            'warning',
            'error',
            10,
            20,
            legacyKeys: ['error' => ['errorThreshold']],
        );

        self::assertSame(['warning' => 15, 'error' => 15], $result);
    }

    #[Test]
    public function itNamesThePrimaryKeysInTheConflictMessageEvenWhenALegacyKeyTriggeredIt(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage(
            '"rules.fixture" in configuration file "/project/qmx.yaml" writes both "voThreshold" and "voWarning" in one layer; "vo-threshold" is shorthand for "vo-warning" and "vo-error" — write either the shorthand or the full keys in one layer.',
        );

        self::parse(
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
        $result = self::parse(
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
    public function itSkipsANullFirstLegacyThresholdKeyAndUsesTheNextOneThatCarriesAValue(): void
    {
        // Alias resolution stops at the first candidate WRITTEN WITH A VALUE,
        // so a `~` alias no longer shadows a populated one behind it — the
        // shape the threshold slot shares with the warning/error slots below.
        $result = self::parse(
            ['firstLegacy' => null, 'secondLegacy' => 7],
            'warning',
            'error',
            10,
            20,
            'threshold',
            legacyKeys: ['threshold' => ['firstLegacy', 'secondLegacy']],
        );

        self::assertSame(['warning' => 7, 'error' => 7], $result);
    }

    #[Test]
    public function itFallsBackToTheLegacyThresholdKeyWhenThePrimaryThresholdIsWrittenNull(): void
    {
        $result = self::parse(
            ['threshold' => null, 'legacyThreshold' => 7],
            'warning',
            'error',
            10,
            20,
            'threshold',
            legacyKeys: ['threshold' => ['legacyThreshold']],
        );

        self::assertSame(['warning' => 7, 'error' => 7], $result);
    }

    #[Test]
    public function itFallsBackToTheLegacyWarningKeyWhenThePrimaryWarningIsExplicitlyNull(): void
    {
        $result = self::parse(
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
        $result = self::parse(
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
        $result = self::parse(
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
        $result = self::parse(['warning' => 0], 'warning', 'error', 10, 20);

        self::assertSame(['warning' => 0, 'error' => 20], $result);
    }

    #[Test]
    public function itKeepsAZeroLegacyWarningInsteadOfFallingBackToTheDefault(): void
    {
        $result = self::parse(
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
        $result = self::parse(['error' => 15], 'warning', 'error', 10, 20);

        self::assertSame(['warning' => 10, 'error' => 15], $result);
    }

    #[Test]
    public function itRefusesUnknownKeysOutsideTheDeclaredFixture(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('Unknown key "rules.fixture.warningThreshold" in configuration file "/project/qmx.yaml". Accepted keys: warning, error, threshold.');
        self::parse(['warningThreshold' => 5, 'errorThreshold' => 15], 'warning', 'error', 10, 20);
    }

    #[Test]
    public function itRefusesAnUnrelatedKeyBesideDeclaredSyntheticShorthands(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessage('Unknown key "rules.fixture.unrelated" in configuration file "/project/qmx.yaml". Accepted keys: warning, error, warning-threshold, error-threshold, threshold, threshold-alias.');
        self::parse(['unrelated' => 1], 'warning', 'error', 10, 20, legacyKeys: ['warning' => ['warningThreshold'], 'error' => ['errorThreshold'], 'threshold' => ['thresholdAlias']]);
    }

    #[Test]
    public function itAppliesTheLegacyErrorFallbackIndependentlyOfTheWarningResolution(): void
    {
        $result = self::parse(
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
        $result = self::parse(
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
        $result = self::parse(['warning' => 5], 'warning', 'error', 10.5, 20.5);

        self::assertSame(['warning' => 5, 'error' => 20.5], $result);
    }

    #[Test]
    public function itPropagatesTheLegacyThresholdValueToBothWarningAndError(): void
    {
        $result = self::parse(
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

    /**
     * @param array<string, mixed> $config
     * @param array<string, list<string>> $legacyKeys
     *
     * @return array{warning: int|float, error: int|float}
     */
    private static function parse(array $config, string $warningKey, string $errorKey, int|float $defaultWarning, int|float $defaultError, string $thresholdKey = 'threshold', array $legacyKeys = []): array
    {
        $number = NodeSchema::scalar(ScalarForm::Number);
        $primary = ['warning' => $warningKey, 'error' => $errorKey, 'threshold' => $thresholdKey];
        $shorthands = [];
        $last = ['warning' => $warningKey, 'error' => $errorKey];
        foreach (['warning', 'error'] as $role) {
            foreach ($legacyKeys[$role] ?? [] as $key) {
                if (ConfigKeySpelling::normalize($key) === ConfigKeySpelling::normalize($primary[$role])) {
                    continue;
                }
                $canonical = strtolower(preg_replace('/[A-Z]/', '-$0', $key) ?? $key);
                $shorthands[] = Shorthand::spreading($canonical, [$last[$role]]);
                $last[$role] = $canonical;
            }
        }
        $shorthands[] = Shorthand::spreading($thresholdKey, [$last['warning'], $last['error']]);
        $lastThreshold = $thresholdKey;
        foreach ($legacyKeys['threshold'] ?? [] as $key) {
            if (ConfigKeySpelling::normalize($key) === ConfigKeySpelling::normalize($thresholdKey)) {
                continue;
            }
            $canonical = strtolower(preg_replace('/[A-Z]/', '-$0', $key) ?? $key);
            $shorthands[] = Shorthand::spreading($canonical, [$lastThreshold]);
            $lastThreshold = $canonical;
        }
        $entry = NodeSchema::map([$warningKey => $number, $errorKey => $number], ...$shorthands);
        $section = new class ($entry) implements DocumentSectionSchemaInterface {
            public function __construct(private readonly NodeSchema $entry) {}
            public function declaration(): SectionDeclaration
            {
                return new SectionDeclaration('rules', NodeSchema::namedMap($this->entry));
            }
        };
        $document = DocumentComposer::compose(new DocumentSchema([$section]), [new AuthoredLayer(
            ConfigurationOrigin::of(ConfigurationSource::ConfigFile, '/project/qmx.yaml'),
            AuthoredNode::fromPlain(['rules' => ['fixture' => $config]]),
        )]);
        return ThresholdParser::parse(new ResolvedRuleOptionValues($document, 'fixture'), new RuleOptionBand($thresholdKey, $warningKey, $errorKey, BandDirection::Rising), $defaultWarning, $defaultError);
    }
}
