<?php

declare(strict_types=1);

namespace QmxDirectiveNarrowControl;

use QmxDirectiveAudit\AuditReportError;
use QmxDirectiveAudit\VerdictReport;
use QmxFindingGate\Process;
use RuntimeException;

/**
 * Proves that `--sweep=narrow` and `--sweep=full` agree, by measuring both.
 *
 * `DirectiveSweepScope::Narrow` re-executes only the rule a directive
 * addresses; `Full` re-executes every enabled rule. They are supposed to be
 * one answer at two prices, and the only evidence for that is running both
 * over a real tree and comparing verdict for verdict — a claim reviewed by
 * reading the two rule executors side by side proves nothing about shared
 * mutable state between them.
 *
 * Every non-zero exit prints what would have gone unnoticed as a silent
 * "matched": a run that produced no JSON, an error envelope, an incomplete
 * scope, an empty directive list, a directive list with no measured
 * `@qmx-threshold` verdict (the only kind `--sweep` can move), or a genuine
 * verdict disagreement. A comparator that returned 0 on any of those would be
 * green regardless of whether narrow and full agree. Each of those leaves here
 * as a code rather than as an uncaught exception: 7 when a report cannot be
 * read at all, 4 when both were readable and neither is comparable, 1 on a real
 * disagreement.
 *
 * The report itself is read by {@see \QmxDirectiveAudit\VerdictReport}, the
 * same reader `composer directives:audit` uses. The floor on measured
 * threshold verdicts used to be spelled out here and there in byte-identical
 * words, which is a pair that drifts apart the first time one of them is fixed.
 *
 * Not part of `composer check`: it pays both sweeps' cost on top of what
 * `directives:audit` already pays once. Run it when the audit or the sweep
 * scopes change, same as `gate:controls` and `directives:controls`.
 */

require __DIR__ . '/finding-gate/Process.php';

foreach ([
    'AuditReportError',
    'MeasuredEffects',
    'AuditedVerdict',
    'VerdictReport',
    'EnumeratedSite',
    'SiteEnumeration',
    'Population',
] as $part) {
    require __DIR__ . '/directive-audit/' . $part . '.php';
}

final class Harness
{
    private const string ROOT = __DIR__ . '/..';

    /** @var list<string> */
    private const array TARGET = ['src/'];

    /** The report is not of a shape this comparison knows how to read — the audit gate's code for the same event. */
    private const int EXIT_UNREADABLE = 7;

    /**
     * The two runs were readable and still cannot be compared: one of them did
     * not complete, judged nothing, or disagrees with itself about its own exit
     * code.
     *
     * A code of its own rather than the unreadable one, because the two are
     * different news: "the report is not of a shape I know" points at the
     * report's producer, "this run is not comparable" points at the run. Both
     * used to leave here as an uncaught exception and a shell's 255, which
     * names neither.
     */
    private const int EXIT_NOT_COMPARABLE = 4;

    public static function main(): int
    {
        try {
            return self::compareSweeps();
        } catch (AuditReportError $error) {
            fwrite(\STDERR, 'UNREADABLE: ' . $error->getMessage() . "\n");

            return self::EXIT_UNREADABLE;
        } catch (RuntimeException $error) {
            fwrite(\STDERR, 'NOT COMPARABLE: ' . $error->getMessage() . "\n");

            return self::EXIT_NOT_COMPARABLE;
        }
    }

    private static function compareSweeps(): int
    {
        [$narrow, $narrowSeconds] = self::run('narrow');
        [$full, $fullSeconds] = self::run('full');

        fwrite(\STDOUT, \sprintf("narrow sweep: %.1fs\n", $narrowSeconds));
        fwrite(\STDOUT, \sprintf("full sweep:   %.1fs\n", $fullSeconds));

        $mismatches = self::compare($narrow, $full);

        if ($mismatches === []) {
            fwrite(\STDOUT, \sprintf(
                "MATCH: %d directive verdict(s) agree between narrow and full (%d of them threshold " .
                    "verdicts with a measured outcome — the only ones --sweep can affect).\n",
                \count($narrow->verdicts()),
                $narrow->measuredThresholdCount(),
            ));

            return 0;
        }

        fwrite(\STDOUT, \sprintf("MISMATCH: %d directive verdict(s) disagree.\n", \count($mismatches)));
        foreach ($mismatches as $mismatch) {
            fwrite(\STDOUT, \sprintf(
                "  %s — narrow=%s full=%s\n",
                $mismatch['site'],
                json_encode($mismatch['narrow']),
                json_encode($mismatch['full']),
            ));
        }

        return 1;
    }

    /**
     * One sweep, as the fully-read and validated report plus its wall time.
     *
     * Validation happens here rather than in `compare()`: a report that fails
     * these checks has nothing to compare, and folding the checks into the
     * comparison would let a caller who only reads `$mismatches` mistake "ran
     * with nothing to say" for "matched".
     *
     * @return array{0: VerdictReport, 1: float}
     */
    private static function run(string $sweep): array
    {
        $command = ['php', 'bin/qmx', 'directives', ...self::TARGET, '--format=json', '--sweep=' . $sweep];

        $startedAt = microtime(true);
        $result = Process::run($command, self::rootPath());
        $seconds = microtime(true) - $startedAt;

        if (!\is_array(json_decode($result['stdout'], true))) {
            throw new AuditReportError(\sprintf(
                "--sweep=%s produced no parseable JSON (exit %d).\nstdout: %s\nstderr: %s",
                $sweep,
                $result['exit'],
                $result['stdout'],
                $result['stderr'],
            ));
        }

        $report = VerdictReport::fromJson($result['stdout']);
        self::validate($sweep, $report, $result['exit']);

        return [$report, $seconds];
    }

    private static function validate(string $sweep, VerdictReport $report, int $processExit): void
    {
        if ($report->isErrorEnvelope()) {
            throw new RuntimeException(\sprintf(
                '--sweep=%s failed before producing a report: %s',
                $sweep,
                $report->errorText(),
            ));
        }

        // 0 (clean) and 2 (inert directive found) are the only exit codes a
        // completed audit of a real tree can return. Anything else — 3
        // (configuration), 4 (incomplete run), 1 (unexpected failure) — means
        // this run has no verdicts worth comparing, not that it agrees.
        if (!\in_array($processExit, [0, 2], true)) {
            throw new RuntimeException(\sprintf(
                '--sweep=%s exited %d, which is not a completed audit.',
                $sweep,
                $processExit,
            ));
        }

        if ($report->exitCode() !== $processExit) {
            throw new RuntimeException(\sprintf(
                '--sweep=%s: the process exit code (%d) disagrees with the report\'s own exit_code field (%d).',
                $sweep,
                $processExit,
                $report->exitCode(),
            ));
        }

        // Without this the comparison could be two runs of one sweep: a
        // `--sweep` the command silently defaulted would leave both halves
        // measured the same way and the match would say nothing.
        if ($report->sweep() !== $sweep) {
            throw new RuntimeException(\sprintf(
                '--sweep=%s produced a report that says it was measured with sweep "%s".',
                $sweep,
                $report->sweep(),
            ));
        }

        $scope = $report->scope();
        if (($scope['complete'] ?? false) !== true) {
            throw new RuntimeException(\sprintf('--sweep=%s did not complete: %s', $sweep, var_export($scope, true)));
        }

        $analyzed = $scope['analyzed_files'] ?? null;
        if (!\is_int($analyzed) || $analyzed === 0) {
            throw new RuntimeException(\sprintf(
                '--sweep=%s analysed %s files — a comparison against that scope would prove nothing.',
                $sweep,
                var_export($analyzed, true),
            ));
        }

        if ($report->verdicts() === []) {
            throw new RuntimeException(\sprintf(
                '--sweep=%s found no directives to judge — a comparison against an empty list would ' .
                    'trivially "match" without proving anything.',
                $sweep,
            ));
        }

        // `--sweep` only changes how a `threshold` verdict is produced — a
        // `symbol` verdict is judged by what it silenced, not by re-executing a
        // rule, so `--sweep=narrow` and `--sweep=full` are guaranteed to agree
        // on every `symbol` site by construction. And within `threshold`
        // verdicts, `Unmeasured` ones never reached the rule the sweep width
        // would have affected. A report with directives but none of that kind
        // would "MATCH" while proving nothing about `--sweep` at all.
        if ($report->measuredThresholdCount() === 0) {
            throw new RuntimeException(\sprintf(
                '--sweep=%s produced zero measured @qmx-threshold verdict(s) — --sweep only affects how a ' .
                    'threshold verdict is produced, so a report with none actually exercised proves nothing.',
                $sweep,
            ));
        }
    }

    /**
     * Every field but the one that must differ by design (`sweep` itself).
     *
     * @return list<array{site: string, narrow: list<array<string, mixed>>, full: list<array<string, mixed>>}>
     */
    private static function compare(VerdictReport $narrow, VerdictReport $full): array
    {
        // `summary` is a tally derived from `directives` and deliberately not
        // compared here: it would fail with a generic message on the first
        // verdict disagreement, before the per-site loop below ever names
        // which rules disagree. If every site matches, the tallies computed
        // from them cannot disagree either.
        $describing = [
            'scope' => [$narrow->scope(), $full->scope()],
            'selection' => [$narrow->selection(), $full->selection()],
        ];

        foreach ($describing as $key => [$left, $right]) {
            if ($left !== $right) {
                throw new RuntimeException(\sprintf(
                    "narrow and full disagree on \"%s\", which describes the run rather than a verdict:\n" .
                        "narrow: %s\nfull:   %s",
                    $key,
                    json_encode($left),
                    json_encode($right),
                ));
            }
        }

        $narrowBySite = $narrow->rawVerdictsBySite();
        $fullBySite = $full->rawVerdictsBySite();

        if (array_keys($narrowBySite) !== array_keys($fullBySite)) {
            throw new RuntimeException(
                'narrow and full judged different sets of directive sites — the two sweeps disagree on ' .
                    'what there was to judge, not merely on the verdict.',
            );
        }

        $mismatches = [];

        foreach ($narrowBySite as $site => $narrowVerdicts) {
            $fullVerdicts = $fullBySite[$site];

            if ($narrowVerdicts === $fullVerdicts) {
                continue;
            }

            $mismatches[] = ['site' => $site, 'narrow' => $narrowVerdicts, 'full' => $fullVerdicts];
        }

        return $mismatches;
    }

    private static function rootPath(): string
    {
        $resolved = realpath(self::ROOT);

        if ($resolved === false) {
            throw new RuntimeException('Cannot resolve the repository root.');
        }

        return $resolved;
    }
}

exit(Harness::main());
