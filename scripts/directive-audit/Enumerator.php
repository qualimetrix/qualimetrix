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
    private const string ARTIFACT = 'directive-audit/enumeration-threshold-directives.tsv';

    private const int FAILURE = 1;

    /**
     * @param list<string> $arguments as the shell handed them over, script name included
     */
    public static function main(array $arguments): int
    {
        $check = \in_array('--check', $arguments, true);
        $write = \in_array('--write', $arguments, true);
        $positional = array_values(array_filter(
            $arguments,
            static fn(string $argument): bool => $argument !== '--check' && $argument !== '--write',
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

        if ($write) {
            return self::writeArtifact($root, $rows, $directory);
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

    /**
     * Replaces the artifact's rows with a fresh measurement, keeping its
     * hand-authored header verbatim.
     *
     * The header (the file's leading `#` lines) is the only part of the
     * artifact this script does not measure — it is prose about the method,
     * not the method's output — so a write must carry it forward rather than
     * truncate it the way a stdout redirect does.
     *
     * @param list<string> $rows
     */
    private static function writeArtifact(string $root, array $rows, string $directory): int
    {
        $path = $root . '/' . self::ARTIFACT;
        $existing = is_file($path) ? file_get_contents($path) : false;

        if ($existing === false) {
            fwrite(\STDERR, self::ARTIFACT . " is missing; there is no header left to preserve, so a write refuses.\n");

            return self::FAILURE;
        }

        $content = self::headerOf($existing) . implode('', $rows);

        $tmp = $path . '.tmp.' . getmypid();

        if (file_put_contents($tmp, $content) === false) {
            fwrite(\STDERR, \sprintf("%s: could not write %s.\n", self::ARTIFACT, $tmp));

            return self::FAILURE;
        }

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            fwrite(\STDERR, \sprintf("%s: could not rename %s to %s.\n", self::ARTIFACT, $tmp, $path));

            return self::FAILURE;
        }

        fwrite(\STDERR, \sprintf("%s: wrote %d authored site(s) from %s.\n", self::ARTIFACT, \count($rows), $directory));

        return 0;
    }

    /** The file's leading `#` lines, verbatim, followed by the newline that separates them from data. */
    private static function headerOf(string $committed): string
    {
        $header = [];

        foreach (explode("\n", $committed) as $line) {
            if (!str_starts_with($line, '#')) {
                break;
            }

            $header[] = $line;
        }

        return $header === [] ? '' : implode("\n", $header) . "\n";
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
            . " Refresh it with `php scripts/enumerate-inline-directives.php src --write`.\n",
            self::ARTIFACT,
            substr_count($tracked, "\n"),
            \count($rows),
        ));

        return self::FAILURE;
    }
}
