<?php

declare(strict_types=1);

namespace QmxDirectiveAuditControls;

use QmxFindingGateControls\Shell;
use RuntimeException;

/**
 * The suite that stands in for the detector, and what one run of it said about
 * each case.
 *
 * Read from JUnit XML rather than from `--testdox`: the harness needs the name
 * of every case and its outcome as data, and a report written for a human eye
 * is not that. The files below are the ones that assert what the audit decides;
 * a mutation that reddens something elsewhere in the project is not evidence
 * about a verdict, so nothing else is run.
 *
 * **Both halves of the subject, and the command that renders them.** The list
 * held only the threshold half for one package, and that gap was not academic:
 * a defect in the suppression half — a whole channel missing from the universe
 * it judges against — reached a release candidate with this bench reporting a
 * full table of probes and no uncovered case. A bench that covers half a
 * subject says so only if the list names what it covers.
 *
 * **And the CI step that reads the answer.** The floor
 * `composer directives:audit` puts under the audit decides whether a directive
 * regression stops a build, and until it was listed here no probe could reach
 * it: the bench runs PHPUnit files, and the floor lived in a script that runs
 * on include.
 *
 * **And the second measure that step compares the audit against.** That measure
 * used to be a copy of the product's own extraction pattern, so the only thing
 * guarding it was a text comparison of the two copies — which no breakage of
 * the measure could fail. What guards it now is agreement with the product on
 * authored forms, and that is a suite file like any other.
 */
final readonly class Suite
{
    /** @var list<string> */
    public const array FILES = [
        'tests/Analysis/Policy/Inline/Integration/DirectiveUsageTest.php',
        'tests/Analysis/Policy/Inline/Integration/ThresholdDirectiveAuditTest.php',
        'tests/Analysis/Policy/Inline/Unit/Directive/ExecutionFingerprintFieldCoverageTest.php',
        'tests/Analysis/Run/Integration/DirectiveAuditPipelineTest.php',
        'tests/Infrastructure/Console/Functional/DirectivesCommandTest.php',
        'tests/Infrastructure/Console/Unit/DirectiveAuditSummaryProjectionTest.php',
        'tests/Unit/RuleVocabulary/DirectiveAuditGateTest.php',
        'tests/Unit/RuleVocabulary/DirectiveAuditReportReadingTest.php',
        'tests/Unit/RuleVocabulary/ThresholdPopulationAgreementTest.php',
    ];

    /**
     * @param array<string, bool> $cases case name => whether it passed
     * @param int $exit what PHPUnit itself said about the run
     */
    private function __construct(
        public array $cases,
        public int $exit,
    ) {}

    public static function runIn(string $tree): self
    {
        $log = $tree . '/.directive-audit-controls.xml';
        $result = Shell::run([
            'vendor/bin/phpunit',
            '--no-coverage',
            '--do-not-cache-result',
            '--log-junit',
            $log,
            ...self::FILES,
        ], $tree);

        if (!is_file($log)) {
            throw new RuntimeException(\sprintf(
                "PHPUnit wrote no JUnit log in the scratch tree, so this run says nothing.\nstdout: %s\nstderr: %s",
                $result['stdout'],
                $result['stderr'],
            ));
        }

        return self::fromJUnit(Shell::read($log), $result['exit']);
    }

    /**
     * A case is red when PHPUnit recorded a failure or an error against it.
     *
     * Skipped counts as red too, and deliberately: a mutation that turns a case
     * into a skip has removed the check exactly as thoroughly as one that
     * deletes it, and the harness must not read that as "the claim still holds".
     *
     * The exit code travels with the cases because it carries what no log
     * shows. This project fails on warnings and on risky tests, and both leave
     * every case green in the JUnit document while the run exits non-zero — a
     * bench reading only the document would call such a breakage unnoticed.
     */
    private static function fromJUnit(string $xml, int $exit): self
    {
        $document = @simplexml_load_string($xml);

        if ($document === false) {
            throw new RuntimeException('The JUnit log is not readable XML.');
        }

        $cases = [];

        foreach ($document->xpath('//testcase') ?? [] as $case) {
            $name = (string) $case['name'];

            if ($name === '') {
                continue;
            }

            // `isset()`, not `=== null`: reading a missing child off a
            // SimpleXMLElement returns an empty element rather than null, so
            // the null comparison marks every case as failed and the harness
            // reports a green suite as forty reds.
            $cases[$name] = !isset($case->failure) && !isset($case->error) && !isset($case->skipped);
        }

        if ($cases === []) {
            throw new RuntimeException(
                'The JUnit log records no cases at all. A run that executes nothing is not a green run.',
            );
        }

        ksort($cases);

        return new self($cases, $exit);
    }

    /** @return list<string> */
    public function red(): array
    {
        return array_keys(array_filter($this->cases, static fn(bool $passed): bool => !$passed));
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->cases);
    }
}
