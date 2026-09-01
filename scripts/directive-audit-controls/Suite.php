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
 * is not that. The three files below are the ones that assert what the audit
 * decides; a mutation that reddens something elsewhere in the project is not
 * evidence about a verdict, so nothing else is run.
 */
final readonly class Suite
{
    /** @var list<string> */
    public const array FILES = [
        'tests/Analysis/Policy/Inline/Integration/ThresholdDirectiveAuditTest.php',
        'tests/Analysis/Policy/Inline/Unit/Directive/ExecutionFingerprintFieldCoverageTest.php',
        'tests/Analysis/Run/Integration/DirectiveAuditPipelineTest.php',
    ];

    /** @param array<string, bool> $cases case name => whether it passed */
    private function __construct(public array $cases) {}

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

        return self::fromJUnit(Shell::read($log));
    }

    /**
     * A case is red when PHPUnit recorded a failure or an error against it.
     *
     * Skipped counts as red too, and deliberately: a mutation that turns a case
     * into a skip has removed the check exactly as thoroughly as one that
     * deletes it, and the harness must not read that as "the claim still holds".
     */
    private static function fromJUnit(string $xml): self
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

        return new self($cases);
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
