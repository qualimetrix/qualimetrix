<?php

declare(strict_types=1);

namespace QmxDirectiveNarrowControl;

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
 * green regardless of whether narrow and full agree.
 *
 * Not part of `composer check`: it pays both sweeps' cost on top of what
 * `directives:audit` already pays once. Run it when the audit or the sweep
 * scopes change, same as `gate:controls` and `directives:controls`.
 */

require __DIR__ . '/finding-gate/Process.php';

final class Harness
{
    private const string ROOT = __DIR__ . '/..';

    /** @var list<string> */
    private const array TARGET = ['src/'];

    public static function main(): int
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
                \count($narrow['directives']),
                \count(self::measuredThresholdVerdicts($narrow['directives'])),
            ));

            return 0;
        }

        fwrite(\STDOUT, \sprintf("MISMATCH: %d directive verdict(s) disagree.\n", \count($mismatches)));
        foreach ($mismatches as $mismatch) {
            fwrite(\STDOUT, \sprintf(
                "  %s:%d %s — narrow=%s full=%s\n",
                $mismatch['file'],
                $mismatch['line'],
                $mismatch['target'],
                json_encode($mismatch['narrow']),
                json_encode($mismatch['full']),
            ));
        }

        return 1;
    }

    /**
     * One sweep, as the fully-decoded and validated report plus its wall time.
     *
     * Validation happens here rather than in `compare()`: a report that fails
     * these checks has nothing to compare, and folding the checks into the
     * comparison would let a caller who only reads `$mismatches` mistake "ran
     * with nothing to say" for "matched".
     *
     * @return array{0: array<string, mixed>, 1: float}
     */
    private static function run(string $sweep): array
    {
        $command = ['php', 'bin/qmx', 'directives', ...self::TARGET, '--format=json', '--sweep=' . $sweep];

        $startedAt = microtime(true);
        $result = Process::run($command, self::rootPath());
        $seconds = microtime(true) - $startedAt;

        $report = self::decode($sweep, $result);
        self::validate($sweep, $report, $result['exit']);

        return [$report, $seconds];
    }

    /**
     * @param array{stdout: string, stderr: string, exit: int} $result
     *
     * @return array<string, mixed>
     */
    private static function decode(string $sweep, array $result): array
    {
        $decoded = json_decode($result['stdout'], true);

        if (!\is_array($decoded)) {
            throw new RuntimeException(\sprintf(
                "--sweep=%s produced no parseable JSON (exit %d).\nstdout: %s\nstderr: %s",
                $sweep,
                $result['exit'],
                $result['stdout'],
                $result['stderr'],
            ));
        }

        return $decoded;
    }

    /** @param array<string, mixed> $report */
    private static function validate(string $sweep, array $report, int $processExit): void
    {
        if (isset($report['error'])) {
            throw new RuntimeException(\sprintf(
                '--sweep=%s failed before producing a report: %s',
                $sweep,
                (string) $report['error'],
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

        $exitCode = $report['exit_code'] ?? null;
        if ($exitCode !== $processExit) {
            throw new RuntimeException(\sprintf(
                '--sweep=%s: the process exit code (%d) disagrees with the report\'s own exit_code field (%s).',
                $sweep,
                $processExit,
                var_export($exitCode, true),
            ));
        }

        $scope = $report['scope'] ?? null;
        if (!\is_array($scope) || ($scope['complete'] ?? false) !== true) {
            throw new RuntimeException(\sprintf('--sweep=%s did not complete: %s', $sweep, var_export($scope, true)));
        }

        if (($scope['analyzed_files'] ?? 0) === 0) {
            throw new RuntimeException(\sprintf(
                '--sweep=%s analysed zero files — a comparison against that scope would prove nothing.',
                $sweep,
            ));
        }

        $directives = $report['directives'] ?? null;
        if (!\is_array($directives) || $directives === []) {
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
        if (self::measuredThresholdVerdicts($directives) === []) {
            throw new RuntimeException(\sprintf(
                '--sweep=%s produced zero measured @qmx-threshold verdict(s) — --sweep only affects how a ' .
                    'threshold verdict is produced, so a report with none actually exercised proves nothing.',
                $sweep,
            ));
        }
    }

    /**
     * `$directives` at the `validate()` call site is only proven to be array
     * (via `is_array()`, ahead of a JSON-decoded, string-keyed `$report`) —
     * not proven to be a list, since PHPStan cannot see past `json_decode()`
     * that `directives` was JSON-encoded from a `list`. Widening to a
     * non-list-constrained shape here describes what is actually known, it
     * does not give up on element precision.
     *
     * @param array<array<string, mixed>> $directives
     *
     * @return list<array<string, mixed>>
     */
    private static function measuredThresholdVerdicts(array $directives): array
    {
        return array_values(array_filter(
            $directives,
            static fn(array $directive): bool => ($directive['form'] ?? null) === 'threshold'
                && ($directive['effect'] ?? null) !== 'unmeasured',
        ));
    }

    /**
     * Every field but the one that must differ by design (`sweep` itself).
     *
     * @param array<string, mixed> $narrow
     * @param array<string, mixed> $full
     *
     * @return list<array{file: string, line: int, target: string, narrow: array<string, mixed>, full: array<string, mixed>}>
     */
    private static function compare(array $narrow, array $full): array
    {
        // `summary` is a tally derived from `directives` and deliberately not
        // compared here: it would fail with a generic message on the first
        // verdict disagreement, before the per-site loop below ever names
        // which rules disagree. If every site matches, the tallies computed
        // from them cannot disagree either.
        foreach (['scope', 'selection'] as $key) {
            if ($narrow[$key] !== $full[$key]) {
                throw new RuntimeException(\sprintf(
                    "narrow and full disagree on \"%s\", which describes the run rather than a verdict:\n" .
                        "narrow: %s\nfull:   %s",
                    $key,
                    json_encode($narrow[$key]),
                    json_encode($full[$key]),
                ));
            }
        }

        $narrowBySite = self::bySite($narrow['directives']);
        $fullBySite = self::bySite($full['directives']);

        if (array_keys($narrowBySite) !== array_keys($fullBySite)) {
            throw new RuntimeException(
                'narrow and full judged different sets of directive sites — the two sweeps disagree on ' .
                    'what there was to judge, not merely on the verdict.',
            );
        }

        $mismatches = [];

        foreach ($narrowBySite as $site => $narrowVerdict) {
            $fullVerdict = $fullBySite[$site];

            if ($narrowVerdict === $fullVerdict) {
                continue;
            }

            $mismatches[] = [
                'file' => (string) $narrowVerdict['file'],
                'line' => (int) $narrowVerdict['line'],
                'target' => (string) $narrowVerdict['target'],
                'narrow' => $narrowVerdict,
                'full' => $fullVerdict,
            ];
        }

        return $mismatches;
    }

    /**
     * Keyed by file:line:form:target rather than array position: both sweeps
     * walk the same discovery, so position matches too, but the site identity
     * is what a verdict is actually about and is not more expensive to key by.
     * `form` is part of the key, not just `target`: a suppression and a
     * threshold authored on the same line against the same channel are two
     * distinct sites — `DirectiveSite` carries both — and collapsing them onto
     * one key would silently drop one from the comparison on a collision.
     *
     * @param list<array<string, mixed>> $directives
     *
     * @return array<string, array<string, mixed>>
     */
    private static function bySite(array $directives): array
    {
        $bySite = [];

        foreach ($directives as $directive) {
            $key = \sprintf('%s:%d:%s:%s', $directive['file'], $directive['line'], $directive['form'], $directive['target']);
            $bySite[$key] = $directive;
        }

        return $bySite;
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
