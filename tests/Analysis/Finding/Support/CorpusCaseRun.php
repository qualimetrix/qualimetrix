<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Support;

use PHPUnit\Framework\Assert;
use RuntimeException;

/**
 * Runs the external corpus in `finding-gate/cases/` and hands back one
 * report, for the guards that check a declaration against what the product
 * is *observed* doing rather than against what it says about itself.
 *
 * It is shared rather than copied because two such guards now exist —
 * {@see \Qualimetrix\Tests\Analysis\Finding\Integration\ChannelLevelDeclarationDriftTest}
 * for the levels a channel reports at and
 * {@see \Qualimetrix\Tests\Analysis\Finding\Integration\ChannelJudgedMetricDriftTest}
 * for the metric its magnitude comes from — and both need the same three
 * things: the case definitions, a run of `bin/qmx` under a case's own
 * configuration, and the refusal below to treat a partial run as an
 * observation. What each guard reads out of the report is its own business
 * and stays in its own class; nothing here knows about channels.
 *
 * Sharing the *runner* does not make the two guards one witness: they read
 * different fields of different reports and compare them against different
 * declarations.
 */
final class CorpusCaseRun
{
    /** Exit codes at or above this one mean the analysis did not happen, not that it found something. */
    private const int EXIT_CANNOT_ANALYSE = 3;

    /**
     * Every corpus case, keyed by its directory.
     *
     * @return array<string, array<string, mixed>> case directory => case definition
     */
    public static function cases(): array
    {
        $root = self::repositoryRoot() . '/finding-gate/cases';
        $directories = glob($root . '/*', \GLOB_ONLYDIR);

        if ($directories === false || $directories === []) {
            throw new RuntimeException(\sprintf('No corpus cases under %s.', $root));
        }

        $cases = [];

        foreach ($directories as $directory) {
            $definition = file_get_contents($directory . '/case.json');

            if ($definition === false) {
                throw new RuntimeException(\sprintf('Corpus case %s has no case.json.', $directory));
            }

            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($definition, true, flags: \JSON_THROW_ON_ERROR);
            $cases[$directory] = $decoded;
        }

        return $cases;
    }

    /**
     * The findings a case emits, from a `--format=json` run.
     *
     * @param array<string, mixed> $case
     *
     * @return list<array<string, mixed>>
     */
    public static function findings(string $directory, array $case): array
    {
        $report = self::report($directory, $case, 'json');
        $violations = $report['violations'] ?? null;

        Assert::assertIsArray($violations, \sprintf('The corpus case in %s produced no violation list.', $directory));

        $meta = $report['violationsMeta'] ?? null;
        Assert::assertIsArray($meta, \sprintf('The corpus case in %s reported no violation metadata.', $directory));
        Assert::assertFalse(
            $meta['truncated'] ?? true,
            \sprintf('The corpus case in %s truncated its finding list; the observation is incomplete.', $directory),
        );

        /** @var list<array<string, mixed>> */
        return array_values($violations);
    }

    /**
     * Every metric the same case collected, from a `--format=metrics` run —
     * the raw catalog values, before any rule published one as a finding's
     * magnitude.
     *
     * @param array<string, mixed> $case
     *
     * @return list<array<string, mixed>> one entry per symbol: `type`, `name`, `metrics`
     */
    public static function metrics(string $directory, array $case): array
    {
        $report = self::report($directory, $case, 'metrics');
        $symbols = $report['symbols'] ?? null;

        Assert::assertIsArray($symbols, \sprintf('The corpus case in %s exported no metrics.', $directory));

        /** @var list<array<string, mixed>> */
        return array_values($symbols);
    }

    public static function repositoryRoot(): string
    {
        return \dirname(__DIR__, 4);
    }

    /**
     * @param array<string, mixed> $case
     */
    public static function stringField(array $case, string $field): string
    {
        $value = $case[$field] ?? null;

        if (!\is_string($value)) {
            throw new RuntimeException(\sprintf('Corpus case is missing the string field "%s".', $field));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $case
     *
     * @return array<string, mixed>
     */
    private static function report(string $directory, array $case, string $format): array
    {
        /** @var list<string> $paths */
        $paths = $case['paths'] ?? [];
        /** @var list<string> $extra */
        $extra = $case['args'] ?? [];

        $command = array_merge(
            [\PHP_BINARY, self::repositoryRoot() . '/bin/qmx', 'check'],
            $paths,
            [
                '-c',
                self::stringField($case, 'config'),
                '--format=' . $format,
                '--workers=0',
                '--no-cache',
                '--no-ansi',
                '--fail-on=none',
            ],
            $extra,
        );

        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $directory,
        );

        if ($process === false) {
            throw new RuntimeException(\sprintf('Could not run the corpus case in %s.', $directory));
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        array_map(fclose(...), $pipes);
        $exit = proc_close($process);

        $decoded = json_decode((string) $stdout, true);

        if (!\is_array($decoded)) {
            throw new RuntimeException(\sprintf(
                "The corpus case in %s produced no %s report (exit %d).\n%s",
                $directory,
                $format,
                $exit,
                (string) $stderr,
            ));
        }

        /** @var array<string, mixed> $decoded */
        self::assertObservationIsComplete($decoded, $directory, $exit, (string) $stderr);

        return $decoded;
    }

    /**
     * A run that analysed less than the whole case is not an observation — it
     * is a smaller observation wearing the same shape.
     *
     * `qmx check` prints a valid report even when a file failed to parse, and
     * reports the shortfall in its own `coverage` section rather than by
     * withholding the document. Reading the payload and stopping there would
     * let a broken fixture quietly narrow what a guard sees, which is the
     * failure mode those guards exist to rule out. The exit code is checked
     * too, but it cannot carry this alone: two of the cases exit 2 on a
     * healthy run because they report configuration errors on purpose.
     *
     * @param array<string, mixed> $report
     */
    private static function assertObservationIsComplete(array $report, string $directory, int $exit, string $stderr): void
    {
        $coverage = $report['coverage'] ?? null;

        Assert::assertIsArray($coverage, \sprintf('The corpus case in %s reported no coverage section.', $directory));

        Assert::assertLessThan(
            self::EXIT_CANNOT_ANALYSE,
            $exit,
            \sprintf("The corpus case in %s could not be analysed (exit %d).\n%s", $directory, $exit, $stderr),
        );
        Assert::assertTrue(
            $coverage['complete'] ?? false,
            \sprintf('The corpus case in %s did not analyse every discovered file: %s', $directory, json_encode($coverage)),
        );
        Assert::assertSame(
            0,
            $coverage['failed'] ?? null,
            \sprintf('The corpus case in %s failed on %s.', $directory, json_encode($coverage['failures'] ?? null)),
        );
    }
}
