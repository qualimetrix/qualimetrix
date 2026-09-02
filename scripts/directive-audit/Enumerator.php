<?php

declare(strict_types=1);

namespace QmxDirectiveAudit;

use RuntimeException;

/**
 * `composer enumeration:directives` — the authored population as TSV, and the
 * freshness check over the tracked copy of it.
 *
 * The scan itself is {@see ThresholdDirectiveScan}; what lives here is the
 * command around it: where to look, how to order the rows, and what to say when
 * the committed table no longer matches a fresh measurement.
 *
 * Separated from the script for the same reason the gate was: a file that runs
 * on include cannot be called from a test, and the measure this command prints
 * is one half of an agreement the test suite has to be able to assert.
 */
final class Enumerator
{
    private const string ARTIFACT = 'docs/internal/plans/rule-vocabulary/enumeration-threshold-directives.tsv';

    private const int FAILURE = 1;

    /**
     * @param list<string> $arguments as the shell handed them over, script name included
     */
    public static function main(array $arguments): int
    {
        $check = \in_array('--check', $arguments, true);
        $positional = array_values(array_filter(
            $arguments,
            static fn(string $argument): bool => $argument !== '--check',
        ));

        $root = getcwd();

        if ($root === false) {
            fwrite(\STDERR, "the working directory is unreadable, so no path can be made relative to it\n");

            return self::FAILURE;
        }

        $directory = $positional[1] ?? 'src';

        try {
            $rows = self::rowsOf(ThresholdDirectiveScan::overTree($root, $directory));
        } catch (RuntimeException $error) {
            // A file the scan could not read used to be a warning on stderr and
            // a scan that carried on: a hole in the population, announced in a
            // stream nothing checks, in the one measurement whose job is to
            // have no holes.
            fwrite(\STDERR, $error->getMessage() . "\n");

            return self::FAILURE;
        }

        if (!$check) {
            fwrite(\STDOUT, implode('', $rows));
            fwrite(\STDERR, \sprintf("%d authored sites in %s\n", \count($rows), $directory));

            return 0;
        }

        return self::compareWithArtifact($root, $rows);
    }

    /**
     * @param list<EnumeratedSite> $sites
     *
     * @return list<string> one TSV line each, ordered by target, then file, then line
     */
    private static function rowsOf(array $sites): array
    {
        usort(
            $sites,
            static fn(EnumeratedSite $a, EnumeratedSite $b): int
                => [$a->target, $a->file, $a->line] <=> [$b->target, $b->file, $b->line],
        );

        return array_map(
            static fn(EnumeratedSite $site): string => \sprintf(
                "%s\t%d\t%s\t%s\n",
                $site->file,
                $site->line,
                $site->target,
                $site->values,
            ),
            $sites,
        );
    }

    /** @param list<string> $rows */
    private static function compareWithArtifact(string $root, array $rows): int
    {
        $committed = is_file($root . '/' . self::ARTIFACT) ? file_get_contents($root . '/' . self::ARTIFACT) : false;

        if ($committed === false) {
            fwrite(\STDERR, self::ARTIFACT . " is missing; regenerate it.\n");

            return self::FAILURE;
        }

        $tracked = implode("\n", array_values(array_filter(
            explode("\n", $committed),
            static fn(string $line): bool => $line !== ''
                && !str_starts_with($line, '#')
                && !str_starts_with($line, "file\t"),
        ))) . "\n";
        $measured = implode('', $rows);

        if ($tracked === $measured) {
            fwrite(\STDERR, \sprintf("%s: up to date (%d authored sites).\n", self::ARTIFACT, \count($rows)));

            return 0;
        }

        fwrite(\STDERR, \sprintf(
            "%s is stale: it lists %d authored site(s) and a fresh measurement finds %d."
            . " Refresh it with `php scripts/enumerate-inline-directives.php src`.\n",
            self::ARTIFACT,
            substr_count($tracked, "\n"),
            \count($rows),
        ));

        return self::FAILURE;
    }
}
