<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\RuleVocabulary;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxDirectiveAudit\Gate;

/**
 * What `composer directives:audit` does with a report, on synthetic reports.
 *
 * The step used to be evidence about nothing: its floor and its population
 * comparison were exercised only by the live tree, where both are satisfied,
 * and no test and no control probe ever saw either refuse. A floor nobody has
 * watched refuse is a floor nobody has tested.
 *
 * The judgement is called directly rather than through the script, which runs
 * on include and exits.
 */
final class DirectiveAuditGateTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $scripts = \dirname(__DIR__, 3) . '/scripts';

        require_once $scripts . '/finding-gate/Process.php';

        foreach (
            [
                'AuditReportError',
                'MeasuredEffects',
                'AuditedVerdict',
                'VerdictReport',
                'EnumeratedSite',
                'SiteEnumeration',
                'Population',
                'Gate',
            ] as $part
        ) {
            require_once $scripts . '/directive-audit/' . $part . '.php';
        }
    }

    #[Test]
    public function itAcceptsATreeWhoseSitesMatchAndWhereSomethingWasMeasured(): void
    {
        self::assertSame(0, self::judge(
            self::report([
                self::verdict('src/A.php', 10, 'threshold', 'complexity.ccn', 'effective'),
                self::verdict('src/A.php', 10, 'symbol', 'complexity.ccn', 'inert'),
            ]),
            0,
            "src/A.php\t10\tcomplexity.ccn\t20\n",
        ), 'The suppression half is not part of the population the enumeration measures.');
    }

    /** An enumerated site nobody judged, and a judged site nobody authored, are both this gate's own finding. */
    #[Test]
    public function itReportsAPopulationMismatch(): void
    {
        self::assertSame(5, self::judge(
            self::report([self::verdict('src/A.php', 10, 'threshold', 'complexity.ccn', 'effective')]),
            0,
            "src/B.php\t4\tsize.loc\t100\n",
        ));
    }

    /** The one case a set difference hides: one of two directives on a site goes missing, the twin survives. */
    #[Test]
    public function itSeesOneOfTwoDirectivesOnASiteGoMissing(): void
    {
        self::assertSame(5, self::judge(
            self::report([self::verdict('src/A.php', 10, 'threshold', 'complexity.ccn', 'effective')]),
            0,
            "src/A.php\t10\tcomplexity.ccn\t20\nsrc/A.php\t10\tcomplexity.ccn\t30\n",
        ));
    }

    #[Test]
    public function itRefusesAReportWhoseThresholdVerdictsAreAllUnmeasured(): void
    {
        self::assertSame(6, self::judge(
            self::report([self::verdict('src/A.php', 10, 'threshold', 'complexity.ccn', 'unmeasured')]),
            0,
            "src/A.php\t10\tcomplexity.ccn\t20\n",
        ));
    }

    /** The floor cannot decide what an unnamed verdict is worth, so it refuses instead of guessing leniently. */
    #[Test]
    public function itRefusesToJudgeAVerdictValueTheFloorCannotWeigh(): void
    {
        self::assertSame(7, self::judge(
            self::report([self::verdict('src/A.php', 10, 'threshold', 'complexity.ccn', 'probably-fine')]),
            0,
            "src/A.php\t10\tcomplexity.ccn\t20\n",
        ));
    }

    /**
     * The vocabulary is one over both forms, so an unnamed verdict on a
     * suppression site is refused as loudly as one on a threshold site — the
     * floor never counts that half, and the population comparison would have
     * passed over it in silence.
     */
    #[Test]
    public function itRefusesAnUnknownVerdictOnASuppressionSite(): void
    {
        self::assertSame(7, self::judge(
            self::report([
                self::verdict('src/A.php', 10, 'threshold', 'complexity.ccn', 'effective'),
                self::verdict('src/A.php', 12, 'symbol', 'complexity.ccn', 'future'),
            ]),
            0,
            "src/A.php\t10\tcomplexity.ccn\t20\n",
        ));
    }

    /**
     * And on a tree with no threshold directive at all, where the early return
     * for an empty population means the floor is never reached.
     */
    #[Test]
    public function itRefusesAnUnknownVerdictWhereTheFloorIsNeverReached(): void
    {
        self::assertSame(7, self::judge(
            self::report([self::verdict('src/A.php', 12, 'symbol', 'complexity.ccn', 'future')]),
            0,
            '',
        ));
    }

    /** A tree with no threshold directive is not an error, and there is nothing to floor-check in it. */
    #[Test]
    public function itFloorsNothingWhenNoThresholdSiteIsInScope(): void
    {
        self::assertSame(0, self::judge(
            self::report([self::verdict('src/A.php', 10, 'symbol', 'complexity.ccn', 'unmeasured')]),
            0,
            '',
        ));
    }

    /**
     * An incomplete run (4) and a configuration error (3) already disqualify the
     * report from judging anything; this gate propagates that answer instead of
     * replacing it with one of its own — the population below would mismatch.
     */
    #[Test]
    public function itPropagatesARunThatWasAlreadyDisqualified(): void
    {
        self::assertSame(4, self::judge(
            self::report([self::verdict('src/A.php', 10, 'threshold', 'complexity.ccn', 'effective')]),
            4,
            '',
        ));
    }

    /** An error envelope is the command's own answer; the gate propagates the code the command chose. */
    #[Test]
    public function itPropagatesTheCommandsOwnCodeThroughAnErrorEnvelope(): void
    {
        self::assertSame(0, self::judge('{"error": "no configuration", "exit_code": 0}', 0, ''));
    }

    /**
     * A run that died before writing a report is the command's failure, not a
     * malformed report: its exit code is the answer, and 1 stands in when the
     * command claimed success while writing nothing.
     */
    #[Test]
    public function itRefusesAnAuditThatProducedNoJson(): void
    {
        self::assertSame(1, self::judge('PHP Fatal error: something', 0, ''));
    }

    /**
     * The report goes to one sink and this gate's own sentences to another, so
     * a caller piping the JSON onwards never receives the diagnostics in it.
     * Both are discarded here; what the cases are about is the code.
     */
    private static function judge(string $stdout, int $exit, string $enumeration): int
    {
        return Gate::judge(
            $stdout,
            $exit,
            static fn(): string => $enumeration,
            static function (string $payload): void {},
            static function (string $diagnostic): void {},
        );
    }

    /** @return array<string, mixed> */
    private static function verdict(string $file, int $line, string $form, string $target, string $effect): array
    {
        return [
            'file' => $file,
            'line' => $line,
            'form' => $form,
            'target' => $target,
            'effect' => $effect,
            'reason' => null,
            'masked_by' => null,
            'boundary_observable' => true,
        ];
    }

    /** @param list<array<string, mixed>> $verdicts */
    private static function report(array $verdicts): string
    {
        return json_encode([
            'scope' => ['analyzed_files' => 7, 'complete' => true],
            'selection' => ['only' => [], 'disabled' => []],
            'sweep' => 'narrow',
            'directives' => $verdicts,
            'exit_code' => 0,
        ], \JSON_THROW_ON_ERROR);
    }
}
