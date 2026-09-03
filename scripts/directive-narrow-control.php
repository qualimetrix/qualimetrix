<?php

declare(strict_types=1);

namespace QmxDirectiveNarrowControl;

use QmxDirectiveAudit\AuditReportError;
use QmxDirectiveAudit\HeterogeneityFloor;
use QmxDirectiveAudit\VerdictReport;
use QmxFindingGate\Process;
use RuntimeException;

/**
 * Proves that `--sweep=narrow` and `--sweep=full` agree, by measuring both over
 * a named tree under a named configuration.
 *
 * `DirectiveSweepScope::Narrow` re-executes only the rule a directive
 * addresses; `Full` re-executes every enabled rule. They are supposed to be
 * one answer at two prices, and the only evidence for that is running both
 * over a real tree and comparing verdict for verdict — a claim reviewed by
 * reading the two rule executors side by side proves nothing about shared
 * mutable state between them.
 *
 * **The target is an argument, and so is the configuration.** Both used to be
 * implicit: `src/` was a constant and the configuration arrived through the
 * working directory, because the product reads `qmx.yaml` from wherever it was
 * started. The second is the one worth spelling out — the same fixture judged
 * from the repository root and from its own directory produces different
 * verdicts, so a run that does not name its configuration is not reproducible
 * by anyone who reads its command line.
 *
 * **An agreement is only evidence if the population could have disagreed.**
 * Over `src/` every verdict is `Effective`, so this comparison reddens for a
 * defect that turns verdicts into `Inert` or `Unmeasured` and stays green for
 * one that turns everything into `Effective` — which is the outcome it watches
 * as normal. `--require-heterogeneity` is the floor that refuses such a
 * population, and {@see HeterogeneityFloor} says what it asks for and why.
 * `src/` is still measured, without the floor: it is the real population, and
 * the fixture is the vocabulary.
 *
 * Every non-zero exit names what would otherwise have gone unnoticed as a
 * silent "matched". Codes: `1` a verdict disagreement; `2` a population the
 * floor refuses; `3` a run that cannot be compared at all — no parseable JSON
 * from one of the sweeps, an error envelope, an exit code no completed audit
 * returns, a process exit disagreeing with the report's own, a report measured
 * with the other sweep, an incomplete scope, zero analysed files, no
 * directives, no measured threshold verdict, a disagreement about `scope` or
 * `selection`, two different sets of judged sites, or an unusable target,
 * configuration or repository root; `7` a report of a shape
 * {@see VerdictReport} cannot read, which is the audit gate's code for the same
 * event. None of them leaves here as an uncaught exception and a shell's 255,
 * which names nothing.
 *
 * The report itself is read by {@see \QmxDirectiveAudit\VerdictReport}, the
 * same reader `composer directives:audit` uses. The floor on measured
 * threshold verdicts used to be spelled out here and there in byte-identical
 * words, which is a pair that drifts apart the first time one of them is fixed.
 *
 * Not part of `composer check`: it pays both sweeps' cost on top of what
 * `directives:audit` already pays once. Run it when the audit or the sweep
 * scopes change, same as `gate:controls` and `directives:controls`.
 *
 * Usage:
 *   php scripts/directive-narrow-control.php --target=<path> --config=<path>
 *       [--require-heterogeneity] [--min-measured=<n>]
 */

require __DIR__ . '/finding-gate/Process.php';

foreach ([
    'AuditReportError',
    'MeasuredEffects',
    'AuditedVerdict',
    'VerdictReport',
    'HeterogeneityFloor',
    'EnumeratedSite',
    'SiteEnumeration',
    'Population',
] as $part) {
    require __DIR__ . '/directive-audit/' . $part . '.php';
}

/**
 * One comparison's subject: which tree, under which configuration, judged
 * against which floor.
 */
final readonly class Subject
{
    public function __construct(
        public string $target,
        public string $config,
        public bool $requireHeterogeneity,
        public int $minimumMeasured,
    ) {}

    /**
     * @param list<string> $arguments as the shell handed them over, script name included
     *
     * @throws RuntimeException on anything this script would otherwise have to guess
     */
    public static function fromArguments(array $arguments, string $root): self
    {
        $target = null;
        $config = null;
        $requireHeterogeneity = false;
        $minimumMeasured = 1;

        foreach (\array_slice($arguments, 1) as $argument) {
            if ($argument === '--require-heterogeneity') {
                $requireHeterogeneity = true;

                continue;
            }

            [$name, $value] = array_pad(explode('=', $argument, 2), 2, null);

            match ($name) {
                '--target' => $target = self::valueOf($name, $value),
                '--config' => $config = self::valueOf($name, $value),
                '--min-measured' => $minimumMeasured = self::countOf($name, self::valueOf($name, $value)),
                default => throw new RuntimeException(\sprintf('unknown argument "%s". %s', $argument, self::USAGE)),
            };
        }

        if ($target === null || $config === null) {
            // Neither is defaulted, and the configuration is why. The product
            // resolves `qmx.yaml` from the working directory, so a run without
            // `--config` would be measured under whichever configuration the
            // caller happened to stand in — including, for `src/`, the
            // repository's own, which is not the product's defaults and would
            // have changed this comparison's meaning without a word.
            throw new RuntimeException('both --target and --config are required. ' . self::USAGE);
        }

        return new self(
            self::existing($root, $target, '--target'),
            self::existing($root, $config, '--config'),
            $requireHeterogeneity,
            $minimumMeasured,
        );
    }

    public function describe(): string
    {
        return \sprintf(
            '%s under %s%s',
            $this->target,
            $this->config,
            $this->requireHeterogeneity
                ? \sprintf(' (heterogeneity required, at least %d measured)', $this->minimumMeasured)
                : '',
        );
    }

    private const string USAGE =
        'Usage: php scripts/directive-narrow-control.php --target=<path> --config=<path>'
        . ' [--require-heterogeneity] [--min-measured=<n>]';

    /** @throws RuntimeException */
    private static function valueOf(string $name, ?string $value): string
    {
        if ($value === null || $value === '') {
            throw new RuntimeException(\sprintf('%s needs a value. %s', $name, self::USAGE));
        }

        return $value;
    }

    /** @throws RuntimeException */
    private static function countOf(string $name, string $value): int
    {
        if (preg_match('/^\d+$/', $value) !== 1) {
            throw new RuntimeException(\sprintf('%s must be a whole number, got "%s".', $name, $value));
        }

        return (int) $value;
    }

    /**
     * A path the run will actually find, refused here rather than through a
     * sweep that analysed nothing and a comparison that matched trivially.
     *
     * @throws RuntimeException
     */
    private static function existing(string $root, string $path, string $name): string
    {
        if (!file_exists($root . '/' . $path)) {
            throw new RuntimeException(\sprintf('%s "%s" does not exist under %s.', $name, $path, $root));
        }

        return $path;
    }
}

final class Harness
{
    private const string ROOT = __DIR__ . '/..';

    /** The two sweeps judged the same sites differently, which is the news this script exists to carry. */
    private const int EXIT_MISMATCH = 1;

    /**
     * The two sweeps agreed over a population that could not have disagreed.
     *
     * Its own code rather than a mismatch's: nothing about the product is
     * wrong, and the repair is to the fixture, not to a sweep.
     */
    private const int EXIT_POPULATION = 2;

    /**
     * The runs cannot be compared: one of them did not complete, judged
     * nothing, disagrees with itself about its own exit code, or was never
     * started because the target, the configuration or the repository root is
     * unusable.
     *
     * A code of its own rather than the unreadable one, because the two are
     * different news: "the report is not of a shape I know" points at the
     * report's producer, "this run is not comparable" points at the run.
     */
    private const int EXIT_NOT_COMPARABLE = 3;

    /** The report is not of a shape this comparison knows how to read — the audit gate's code for the same event. */
    private const int EXIT_UNREADABLE = 7;

    /** @param list<string> $arguments */
    public static function main(array $arguments): int
    {
        try {
            return self::compareSweeps(Subject::fromArguments($arguments, self::rootPath()));
        } catch (AuditReportError $error) {
            fwrite(\STDERR, 'UNREADABLE: ' . $error->getMessage() . "\n");

            return self::EXIT_UNREADABLE;
        } catch (RuntimeException $error) {
            fwrite(\STDERR, 'NOT COMPARABLE: ' . $error->getMessage() . "\n");

            return self::EXIT_NOT_COMPARABLE;
        }
    }

    private static function compareSweeps(Subject $subject): int
    {
        fwrite(\STDOUT, \sprintf("comparing %s\n", $subject->describe()));

        [$narrow, $narrowSeconds] = self::run($subject, 'narrow');
        [$full, $fullSeconds] = self::run($subject, 'full');

        fwrite(\STDOUT, \sprintf("narrow sweep: %.1fs\n", $narrowSeconds));
        fwrite(\STDOUT, \sprintf("full sweep:   %.1fs\n", $fullSeconds));
        fwrite(\STDOUT, HeterogeneityFloor::describe($narrow));

        $mismatches = self::compare($narrow, $full);

        if ($mismatches !== []) {
            fwrite(\STDOUT, \sprintf(
                "MISMATCH: %s — %d directive verdict(s) disagree.\n",
                $subject->describe(),
                \count($mismatches),
            ));
            foreach ($mismatches as $mismatch) {
                fwrite(\STDOUT, \sprintf(
                    "  %s — narrow=%s full=%s\n",
                    $mismatch['site'],
                    json_encode($mismatch['narrow']),
                    json_encode($mismatch['full']),
                ));
            }

            return self::EXIT_MISMATCH;
        }

        // Asked after the comparison, not before it: a disagreement is a defect
        // in the product whatever the population looks like, while an agreement
        // over a population that could not disagree is a statement about the
        // population. Reporting the floor first would answer the smaller
        // question and hide the larger one.
        $shortfalls = $subject->requireHeterogeneity
            ? HeterogeneityFloor::shortfalls($narrow, $subject->minimumMeasured)
            : [];

        if ($shortfalls !== []) {
            fwrite(\STDOUT, \sprintf(
                "POPULATION: %s — the sweeps agree, and this population could not have made them disagree.\n",
                $subject->describe(),
            ));
            foreach ($shortfalls as $shortfall) {
                fwrite(\STDOUT, '  ' . $shortfall . "\n");
            }

            return self::EXIT_POPULATION;
        }

        fwrite(\STDOUT, \sprintf(
            "MATCH: %s — %d directive verdict(s) agree between narrow and full (%d of them threshold " .
                "verdicts with a measured outcome — the only ones --sweep can affect).\n",
            $subject->describe(),
            \count($narrow->verdicts()),
            $narrow->measuredThresholdCount(),
        ));

        return 0;
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
    private static function run(Subject $subject, string $sweep): array
    {
        $command = [
            'php',
            'bin/qmx',
            'directives',
            $subject->target,
            '--config=' . $subject->config,
            '--format=json',
            '--sweep=' . $sweep,
        ];

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

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];

exit(Harness::main($arguments));
