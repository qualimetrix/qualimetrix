<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\FormatOptionKeys;

use RuntimeException;

/**
 * Runs `bin/qmx check` for one scenario and one format, and refuses anything
 * that is not an observation.
 *
 * Two refusals matter more than they look.
 *
 * The run is pinned to the fixture: `-c <the fixture's own qmx.yaml>` and a
 * working directory inside the fixture. Measured on this tree, the same
 * fixture analysed from the repository root without `-c` produced 38 findings
 * instead of 1 — 37 of them about layers that exist only in the project's own
 * dogfooding config. Without the pin, "the observed key set" would be a
 * property of the repository rather than of the fixture, which is the one
 * thing this guard may not let happen.
 *
 * And the payload is checked, not just the exit code: `qmx check` prints a
 * valid report even when a file failed to parse, so reading stdout and
 * stopping there would let a broken fixture silently narrow what the guard
 * sees. The cache is per process and keyed by the whole invocation; a cache on
 * disk here would be a green without a run.
 */
final class OutputFormatObservation
{
    /** @var array<string, string> */
    private static array $cache = [];

    public static function raw(OutputFormatScenario $scenario, string $format, string ...$extraArgs): string
    {
        $command = array_values([
            \PHP_BINARY,
            self::repositoryRoot() . '/bin/qmx',
            'check',
            'src',
            '-c',
            'qmx.yaml',
            '--format=' . $format,
            '--workers=0',
            '--no-cache',
            '--no-ansi',
            '--no-progress',
            ...$scenario->args,
            ...$extraArgs,
        ]);

        $key = $scenario->name . '|' . implode(' ', \array_slice($command, 2));

        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        [$stdout, $stderr, $exit] = self::run($command, $scenario->path());

        if ($exit !== $scenario->expectedExit) {
            throw new RuntimeException(\sprintf(
                "Scenario \"%s\" in format %s exited %d, expected %d.\n%s",
                $scenario->name,
                $format,
                $exit,
                $scenario->expectedExit,
                $stderr,
            ));
        }

        if (trim($stdout) === '' && $format !== 'github') {
            throw new RuntimeException(\sprintf(
                'Scenario "%s" in format %s produced no output; an empty payload is not an observation.',
                $scenario->name,
                $format,
            ));
        }

        return self::$cache[$key] = $stdout;
    }

    /**
     * What the product says when it refuses, which is where it enumerates what
     * it would have accepted.
     *
     * A refusal is a deliberate observation, not a failure: the product's own
     * list of valid formats is a more reliable witness than a list copied into
     * a test, which cannot notice a format being added.
     */
    public static function refusal(OutputFormatScenario $scenario, string $format): string
    {
        $command = [
            \PHP_BINARY,
            self::repositoryRoot() . '/bin/qmx',
            'check',
            'src',
            '-c',
            'qmx.yaml',
            '--format=' . $format,
            '--workers=0',
            '--no-cache',
            '--no-ansi',
            '--no-progress',
        ];

        [, $stderr, $exit] = self::run($command, $scenario->path());

        if ($exit !== $scenario->expectedExit) {
            throw new RuntimeException(\sprintf(
                'Expected the product to refuse format "%s" with exit %d; it exited %d.',
                $format,
                $scenario->expectedExit,
                $exit,
            ));
        }

        return $stderr;
    }

    /**
     * The same run, decoded, with the scenario's coverage expectation enforced.
     *
     * @return array<string, mixed>
     */
    public static function json(OutputFormatScenario $scenario, string $format, string ...$extraArgs): array
    {
        $decoded = json_decode(self::raw($scenario, $format, ...$extraArgs), true);

        if (!\is_array($decoded)) {
            throw new RuntimeException(\sprintf(
                'Scenario "%s" in format %s did not produce a JSON document.',
                $scenario->name,
                $format,
            ));
        }

        /** @var array<string, mixed> $decoded */
        self::assertCoverage($scenario, $format, $decoded);

        return $decoded;
    }

    public static function repositoryRoot(): string
    {
        return \dirname(__DIR__, 2);
    }

    /**
     * A run that analysed something other than what the scenario declares is a
     * smaller observation wearing the same shape.
     *
     * @param array<string, mixed> $report
     */
    private static function assertCoverage(OutputFormatScenario $scenario, string $format, array $report): void
    {
        $coverage = $report['coverage'] ?? null;

        if (!\is_array($coverage)) {
            // Only the formats that publish a `coverage` object can be checked
            // here; the others are bound to their own projection elsewhere.
            return;
        }

        foreach ($scenario->expectedCoverage as $field => $expected) {
            $actual = $coverage[$field] ?? null;

            if ($actual !== $expected) {
                throw new RuntimeException(\sprintf(
                    'Scenario "%s" in format %s reported coverage.%s = %s, expected %s.',
                    $scenario->name,
                    $format,
                    $field,
                    var_export($actual, true),
                    var_export($expected, true),
                ));
            }
        }
    }

    /**
     * @param list<string> $command
     *
     * @return array{string, string, int}
     */
    private static function run(array $command, string $cwd): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);

        if ($process === false) {
            throw new RuntimeException(\sprintf('Could not run %s in %s.', implode(' ', $command), $cwd));
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        array_map(fclose(...), $pipes);

        return [$stdout, $stderr, proc_close($process)];
    }
}
