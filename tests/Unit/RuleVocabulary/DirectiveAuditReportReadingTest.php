<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\RuleVocabulary;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxDirectiveAudit\AuditReportError;
use QmxDirectiveAudit\EnumeratedSite;
use QmxDirectiveAudit\HeterogeneityFloor;
use QmxDirectiveAudit\MeasuredEffects;
use QmxDirectiveAudit\Population;
use QmxDirectiveAudit\SiteEnumeration;
use QmxDirectiveAudit\VerdictReport;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveEffect;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveUnmeasurableReason;

/**
 * The one reader `composer directives:audit` and `directives:narrow-control`
 * share, and every refusal it owes them.
 *
 * The two scripts used to read the report themselves, with the same floor
 * condition spelled out twice and each with its own idea of which fields were
 * optional. What is checked here is not that the happy path parses — both
 * scripts did that already — but that the shapes they used to accept silently
 * are now refused: a missing field defaulted, a verdict value nobody named, an
 * enumeration row short of a column.
 *
 * The library has no PSR-4 entry, the same as `scripts/finding-gate/`, so this
 * test loads it the way its own scripts do.
 */
final class DirectiveAuditReportReadingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        foreach (
            [
                'AuditReportError',
                'MeasuredEffects',
                'AuditedVerdict',
                'VerdictReport',
                'HeterogeneityFloor',
                'EnumeratedSite',
                'SiteEnumeration',
                'Population',
            ] as $part
        ) {
            require_once \dirname(__DIR__, 3) . '/scripts/directive-audit/' . $part . '.php';
        }
    }

    #[Test]
    public function itReadsAWellFormedReportAsOneMeasurementAndItsContext(): void
    {
        $report = VerdictReport::fromJson(self::reportJson([
            self::verdict('src/A.php', 10, 'threshold', 'complexity.ccn', 'effective'),
            self::verdict('src/A.php', 10, 'symbol', 'complexity.ccn', 'inert'),
        ], 2));

        self::assertFalse($report->isErrorEnvelope());
        self::assertSame(2, $report->exitCode());
        self::assertSame('narrow', $report->sweep());
        self::assertSame(['analyzed_files' => 7, 'complete' => true], $report->scope());
        self::assertSame(['only' => [], 'disabled' => []], $report->selection());
        self::assertCount(2, $report->verdicts());
        self::assertCount(1, $report->thresholdVerdicts(), 'Only the threshold half is a population entry.');
        self::assertSame('src/A.php:10:complexity.ccn', $report->thresholdVerdicts()[0]->site());
        self::assertSame(
            ['src/A.php:10:threshold:complexity.ccn', 'src/A.php:10:symbol:complexity.ccn'],
            array_keys($report->rawVerdictsBySite()),
            'Two forms authored on one line against one channel are two sites, not one.',
        );
        self::assertSame(1, $report->measuredThresholdCount());
    }

    /** An `{"error": …}` envelope carries no verdicts, so reading it must not demand any. */
    #[Test]
    public function itReadsAnErrorEnvelopeWithoutDemandingVerdicts(): void
    {
        $report = VerdictReport::fromJson('{"error": "no configuration", "exit_code": 3}');

        self::assertTrue($report->isErrorEnvelope());
        self::assertSame([], $report->verdicts());
    }

    /**
     * @param array<string, mixed> $verdict
     */
    #[Test]
    #[DataProvider('provideIllShapedVerdicts')]
    public function itRefusesAVerdictWhoseFieldsAreNotTheShapeTheAuditPublishes(array $verdict): void
    {
        $this->expectException(AuditReportError::class);

        VerdictReport::fromJson(self::reportJson([$verdict], 0));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function provideIllShapedVerdicts(): iterable
    {
        $sound = self::verdict('src/A.php', 10, 'threshold', 'complexity.ccn', 'effective');

        foreach (['effect', 'form', 'file', 'target'] as $field) {
            $missing = $sound;
            unset($missing[$field]);

            yield $field . ' missing' => [$missing];
        }

        yield 'effect null' => [[...$sound, 'effect' => null]];
        yield 'effect not a string' => [[...$sound, 'effect' => 7]];
        yield 'line not a number' => [[...$sound, 'line' => '10']];
    }

    /** A verdict value outside the frozen table is a refusal rather than a guess. */
    #[Test]
    public function itRefusesAVerdictValueTheFloorDoesNotName(): void
    {
        $this->expectException(AuditReportError::class);

        VerdictReport::fromJson(self::reportJson([
            self::verdict('src/A.php', 10, 'threshold', 'complexity.ccn', 'probably-fine'),
        ], 0));
    }

    /**
     * The refusal covers the suppression half too, and that is the whole point
     * of checking it while reading: `DirectiveEffect` is one vocabulary over
     * both forms, so a fifth case can first appear on a `symbol` site — which
     * the floor never counts, and which on a tree with no threshold directive
     * at all it never even reaches.
     */
    #[Test]
    public function itRefusesAnUnknownVerdictOnTheSuppressionHalf(): void
    {
        $this->expectException(AuditReportError::class);

        VerdictReport::fromJson(self::reportJson([
            self::verdict('src/A.php', 10, 'threshold', 'complexity.ccn', 'effective'),
            self::verdict('src/A.php', 12, 'symbol', 'complexity.ccn', 'future'),
        ], 0));
    }

    /**
     * Two entries can share one keyed site, and the comparison a caller builds
     * on this map must see both: a map holding one entry per site would drop
     * the other before anything compared it.
     */
    #[Test]
    public function itKeepsEveryEntryOfASiteAuthoredTwice(): void
    {
        $report = VerdictReport::fromJson(self::reportJson([
            self::verdict('src/A.php', 10, 'threshold', 'complexity.ccn', 'effective'),
            self::verdict('src/A.php', 10, 'threshold', 'complexity.ccn', 'inert'),
        ], 0));

        $bySite = $report->rawVerdictsBySite();

        self::assertSame(['src/A.php:10:threshold:complexity.ccn'], array_keys($bySite));
        self::assertSame(
            ['effective', 'inert'],
            array_column($bySite['src/A.php:10:threshold:complexity.ccn'], 'effect'),
        );
    }

    #[Test]
    public function itRefusesAReportWhoseDirectivesAreNotAList(): void
    {
        $this->expectException(AuditReportError::class);

        VerdictReport::fromJson(\sprintf(
            '{"scope": {}, "directives": {"a": %s}, "exit_code": 0}',
            json_encode(self::verdict('src/A.php', 10, 'threshold', 'complexity.ccn', 'effective'), \JSON_THROW_ON_ERROR),
        ));
    }

    #[Test]
    public function itRefusesAReportWithNoDirectivesAtAll(): void
    {
        $this->expectException(AuditReportError::class);

        VerdictReport::fromJson('{"scope": {}, "exit_code": 0}');
    }

    /**
     * A tab inside the authored values is legal — the values group of the
     * extraction pattern takes everything up to the line break — so the split is
     * bounded at four columns rather than refusing a fifth.
     */
    #[Test]
    public function itReadsEveryEnumeratedSiteAndKeepsATabInsideItsValues(): void
    {
        $sites = SiteEnumeration::fromTsv("src/A.php\t10\tcomplexity.ccn\t20\t30\nsrc/B.php\t4\tsize.loc\t\n");

        self::assertCount(2, $sites);
        self::assertSame('src/A.php:10:complexity.ccn', $sites[0]->site());
        self::assertSame("20\t30", $sites[0]->values);
        self::assertEquals(new EnumeratedSite('src/B.php', 4, 'size.loc', ''), $sites[1]);
    }

    #[Test]
    public function itRefusesAnEnumerationRowShortOfAColumn(): void
    {
        $this->expectException(AuditReportError::class);

        SiteEnumeration::fromTsv("src/A.php\t10\tcomplexity.ccn\n");
    }

    #[Test]
    public function itRefusesAnEnumerationRowWhoseLineIsNotANumber(): void
    {
        $this->expectException(AuditReportError::class);

        SiteEnumeration::fromTsv("src/A.php\tten\tcomplexity.ccn\t20\n");
    }

    #[Test]
    public function itRefusesAnEnumerationRowThatAddressesNothing(): void
    {
        $this->expectException(AuditReportError::class);

        SiteEnumeration::fromTsv("src/A.php\t10\t\t20\n");
    }

    /** A verdict the product can publish and the table cannot name is a floor that guesses. */
    #[Test]
    public function itNamesEveryVerdictTheProductCanPublishAndNoOther(): void
    {
        $published = array_map(static fn(DirectiveEffect $effect): string => $effect->value, DirectiveEffect::cases());
        $named = array_keys(MeasuredEffects::TABLE);

        sort($published);
        sort($named);

        self::assertSame($published, $named);
    }

    /**
     * The table must still say what the condition it replaced said. Completeness
     * alone would pass a table with a boolean flipped, and so would a live run:
     * nothing in this tree publishes an unmeasured-only report.
     */
    #[Test]
    public function itKeepsTheMeasuredMeaningOfEveryVerdictKnownToday(): void
    {
        foreach (DirectiveEffect::cases() as $effect) {
            self::assertSame(
                $effect->value !== 'unmeasured',
                MeasuredEffects::isMeasured($effect->value),
                $effect->value,
            );
        }
    }

    /**
     * `reason` is nullable and still required. A reader that accepted the key's
     * absence would read a report that stopped publishing refusals as a
     * population carrying none — and the heterogeneity floor would then refuse
     * the tree instead of the report.
     */
    #[Test]
    public function itRefusesAnEntryWithoutAReasonKey(): void
    {
        $row = self::verdict('src/A.php', 10, 'threshold', 'complexity.ccn', 'unmeasured', 'masked');
        unset($row['reason']);

        $this->expectException(AuditReportError::class);

        VerdictReport::fromJson(self::reportJson([$row], 2));
    }

    /** A verdict the product can publish and the floor cannot name is a floor that guesses. */
    #[Test]
    public function itAsksForEveryVerdictTheProductCanPublishAndNoOther(): void
    {
        $published = array_map(static fn(DirectiveEffect $effect): string => $effect->value, DirectiveEffect::cases());
        $required = HeterogeneityFloor::REQUIRED_EFFECTS;

        sort($published);
        sort($required);

        self::assertSame($published, $required);
    }

    /**
     * The same in the other vocabulary, and the one that carries the point: a
     * masking coalition is `unmeasured` like any other refusal, so a floor
     * written over verdicts alone never asks for it.
     */
    #[Test]
    public function itAsksForEveryRefusalTheProductCanPublishAndNoOther(): void
    {
        $published = array_map(
            static fn(DirectiveUnmeasurableReason $reason): string => $reason->value,
            DirectiveUnmeasurableReason::cases(),
        );
        $required = HeterogeneityFloor::REQUIRED_REASONS;

        sort($published);
        sort($required);

        self::assertSame($published, $required);
    }

    #[Test]
    public function itAcceptsAPopulationCarryingEveryVerdictAndEveryRefusal(): void
    {
        self::assertSame([], HeterogeneityFloor::shortfalls(self::heterogeneousReport(), 3));
    }

    /**
     * The floor names the whole shortfall in one pass: an author repairing a
     * fixture would otherwise pay for two sweeps per missing requirement.
     */
    #[Test]
    public function itNamesEveryRequirementAHomogeneousPopulationMisses(): void
    {
        $report = VerdictReport::fromJson(self::reportJson([
            self::verdict('src/A.php', 10, 'threshold', 'complexity.ccn', 'effective'),
        ], 0));

        self::assertSame(
            [
                'no directive was judged "overrun".',
                'no directive was judged "inert".',
                'no directive was judged "unmeasured".',
                'no directive was refused for "producer-disabled".',
                'no directive was refused for "already-refused".',
                'no directive was refused for "addresses-every-channel".',
                'no directive was refused for "masked".',
            ],
            HeterogeneityFloor::shortfalls($report, 1),
        );
    }

    /**
     * Measured threshold verdicts are the only ones a sweep width can move, so
     * the count is a floor of its own rather than a consequence of the
     * vocabulary being complete.
     */
    #[Test]
    public function itRefusesAPopulationShortOfMeasuredThresholdVerdicts(): void
    {
        self::assertSame(
            [
                'measured threshold verdicts: 3, and --min-measured asks for 4. Only these can move when'
                    . ' the sweep width changes, so a population short of them agrees for free.',
            ],
            HeterogeneityFloor::shortfalls(self::heterogeneousReport(), 4),
        );
    }

    #[Test]
    public function itPrintsWhatThePopulationCarriesWhetherOrNotTheFloorIsMet(): void
    {
        self::assertSame(
            "  verdicts: effective=1 overrun=1 inert=1 unmeasured=4\n"
                . "  reasons:  producer-disabled=1 already-refused=1 addresses-every-channel=1 masked=1\n"
                . "  measured threshold verdicts: 3\n",
            HeterogeneityFloor::describe(self::heterogeneousReport()),
        );
    }

    /** The population the fixture tree produces, in the shape the report publishes it. */
    private static function heterogeneousReport(): VerdictReport
    {
        return VerdictReport::fromJson(self::reportJson([
            self::verdict('src/A.php', 10, 'threshold', 'code-smell.long-parameter-list', 'effective'),
            self::verdict('src/A.php', 12, 'threshold', 'complexity.cyclomatic', 'inert'),
            self::verdict('src/B.php', 10, 'threshold', 'code-smell.long-parameter-list', 'overrun'),
            self::verdict('src/C.php', 8, 'threshold', 'code-smell.long-parameter-list', 'unmeasured', 'masked'),
            self::verdict('src/D.php', 7, 'symbol', '*', 'unmeasured', 'addresses-every-channel'),
            self::verdict('src/D.php', 20, 'threshold', 'no.such-channel', 'unmeasured', 'already-refused'),
            self::verdict('src/D.php', 21, 'threshold', 'complexity.cognitive', 'unmeasured', 'producer-disabled'),
        ], 2));
    }

    /**
     * Two directives authored on one site are two occurrences: a set difference
     * collapses them and stops seeing one of the two go missing.
     */
    #[Test]
    public function itCountsEveryOccurrenceOfARepeatedSite(): void
    {
        [$onlyLeft, $onlyRight] = Population::diff(
            ['src/A.php:10:ccn', 'src/A.php:10:ccn'],
            ['src/A.php:10:ccn'],
        );

        self::assertSame(['src/A.php:10:ccn' => 1], $onlyLeft);
        self::assertSame([], $onlyRight);
    }

    /**
     * @return array<string, mixed>
     */
    private static function verdict(
        string $file,
        int $line,
        string $form,
        string $target,
        string $effect,
        ?string $reason = null,
    ): array {
        return [
            'file' => $file,
            'line' => $line,
            'form' => $form,
            'target' => $target,
            'effect' => $effect,
            'reason' => $reason,
            'masked_by' => null,
            'boundary_observable' => true,
        ];
    }

    /** @param list<array<string, mixed>> $verdicts */
    private static function reportJson(array $verdicts, int $exitCode): string
    {
        return json_encode([
            'scope' => ['analyzed_files' => 7, 'complete' => true],
            'selection' => ['only' => [], 'disabled' => []],
            'sweep' => 'narrow',
            'directives' => $verdicts,
            'exit_code' => $exitCode,
        ], \JSON_THROW_ON_ERROR);
    }
}
