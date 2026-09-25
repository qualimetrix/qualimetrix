<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * How one side's run of one case ends, and which checks that ending leaves
 * something to check.
 *
 * A case analyses by default. One that exists to exercise a refusal or an
 * incomplete analysis says so in `case.json`, with the exit it must end with;
 * a step that changes how a case ends declares it in `declared-outcomes.tsv`.
 * Whichever it is, a check does not decide for itself whether it applies: it
 * asks {@see self::applies()}, and the table below is the one answer. A check
 * that reads findings has nothing to read in a refusal, and one that holds the
 * baseline file to existing would fail an incomplete analysis for writing none.
 *
 * An outcome nothing verifies must not be accepted either, or a case declared
 * a refusal would skip every check and pass: each outcome other than an
 * analysis names the check that holds it to its exit and output, and a corpus
 * using that outcome while no form registers the check is refused.
 */
final class CaseOutcome
{
    public const string ANALYSIS = 'analysis';

    public const string REFUSAL = 'refusal';

    public const string INCOMPLETE = 'incomplete';

    public const array ALL = [self::ANALYSIS, self::REFUSAL, self::INCOMPLETE];

    /** The findings section is there and whole: `CaseOutcomeCheck::findingsOf`. */
    public const string CHECK_FINDINGS = 'findings';

    /** `baseline:generate` exited 0 and wrote a file. */
    public const string CHECK_BASELINE_FILE = 'baseline-file';

    public const string CHECK_TUPLE = 'tuple';

    public const string CHECK_NORMALIZATION_LEAVES_FINDINGS = 'normalization-leaves-findings';

    public const string CHECK_FINGERPRINTS = 'fingerprints';

    /** The case's observed channels count toward coverage. */
    public const string CHECK_COVERAGE = 'coverage';

    /** Declared records are withdrawn or introduced on this case's reports. */
    public const string CHECK_RECORDS = 'records';

    /** A refusal ended with its expected exit and its declared output. */
    public const string CHECK_REFUSAL = 'refusal';

    /** An incomplete analysis ended with its expected exit and wrote no baseline file. */
    public const string CHECK_INCOMPLETE = 'incomplete';

    /** @var array<string, list<string>> check => the outcomes it applies to */
    public const array CHECKS = [
        self::CHECK_FINDINGS => [self::ANALYSIS, self::INCOMPLETE],
        self::CHECK_BASELINE_FILE => [self::ANALYSIS],
        self::CHECK_TUPLE => [self::ANALYSIS, self::INCOMPLETE],
        self::CHECK_NORMALIZATION_LEAVES_FINDINGS => [self::ANALYSIS, self::INCOMPLETE],
        self::CHECK_FINGERPRINTS => [self::ANALYSIS, self::INCOMPLETE],
        self::CHECK_COVERAGE => [self::ANALYSIS, self::INCOMPLETE],
        self::CHECK_RECORDS => [self::ANALYSIS, self::INCOMPLETE],
        self::CHECK_REFUSAL => [self::REFUSAL],
        self::CHECK_INCOMPLETE => [self::INCOMPLETE],
    ];

    /** @var array<string, string> outcome => the check that holds a case to it */
    public const array VERIFIED_BY = [
        self::ANALYSIS => self::CHECK_FINDINGS,
        self::REFUSAL => self::CHECK_REFUSAL,
        self::INCOMPLETE => self::CHECK_INCOMPLETE,
    ];

    public static function applies(string $check, string $outcome): bool
    {
        $outcomes = self::CHECKS[$check] ?? throw new GateError(\sprintf('"%s" is no check of CaseOutcome::CHECKS.', $check));

        return \in_array($outcome, $outcomes, true);
    }

    /**
     * How the case is expected to end on this side. The side is part of the
     * question because a declared outcome changes one side only; until the
     * declared outcomes are read, both sides end as `case.json` says.
     */
    public static function of(CaseDefinition $case, string $side): string
    {
        return $case->outcome;
    }

    /**
     * Refuses a corpus whose cases end in an outcome no check holds them to.
     *
     * @param list<string> $registered the names of the checks the forms registered
     */
    public static function assertVerifiable(Corpus $corpus, array $registered): void
    {
        $built = [self::CHECK_FINDINGS];

        foreach ($corpus->cases as $case) {
            $verifier = self::VERIFIED_BY[$case->outcome];

            if (!\in_array($verifier, [...$built, ...$registered], true)) {
                throw new GateError(\sprintf(
                    'Case "%s" is expected to end in %s, and no registered check ("%s") holds a case to that outcome.'
                    . ' Without one every other check would step aside and the case would pass unexamined.',
                    $case->id,
                    $case->outcome,
                    $verifier,
                ));
            }
        }
    }
}
