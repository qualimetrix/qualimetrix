<?php

declare(strict_types=1);

/**
 * Lists the authored `@qmx-threshold` sites of a directory tree as TSV.
 *
 * Faithful to the product rather than to a grep: only `T_DOC_COMMENT` tokens are
 * read, backtick-delimited regions are stripped exactly as the product strips
 * them (AGENTS.md §8), and what remains is matched with
 * {@see \Qualimetrix\Analysis\Policy\Inline\Contract\ThresholdOverrideExtractor}'s
 * own pattern — kept in sync by copy, because the extractor's pattern is private
 * and this script deliberately does not boot the analysis pipeline.
 * `ThresholdDirectivePatternSyncTest` guards the copy against drift.
 *
 * Usage: php scripts/enumerate-inline-directives.php [directory]
 */
const DIRECTIVE_PATTERN = '/@qmx-threshold\s+([\w.*#:-]+)(?:[ \t]+([^\n\r]*))?/';

const ARTIFACT = 'docs/internal/plans/rule-vocabulary/enumeration-threshold-directives.tsv';

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];
$check = in_array('--check', $arguments, true);
$positional = array_values(array_filter($arguments, static fn(string $argument): bool => $argument !== '--check'));

$root = getcwd();

if ($root === false) {
    fwrite(STDERR, "the working directory is unreadable, so no path can be made relative to it\n");

    exit(1);
}
$directory = $positional[1] ?? 'src';
$rows = [];

/** @var SplFileInfo $file */
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory)) as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $source = file_get_contents($file->getPathname());

    if ($source === false) {
        fwrite(STDERR, sprintf("unreadable: %s\n", $file->getPathname()));

        continue;
    }

    $relative = substr($file->getPathname(), strlen($root) + 1);

    foreach (token_get_all($source) as $token) {
        if (!is_array($token) || $token[0] !== T_DOC_COMMENT) {
            continue;
        }

        $text = preg_replace('/`[^`]*`/', '', $token[1]);

        foreach (explode("\n", (string) $text) as $offset => $line) {
            if (preg_match(DIRECTIVE_PATTERN, $line, $match) === 1) {
                $rows[] = [$relative, (string) ($token[2] + $offset), $match[1], trim($match[2] ?? '')];
            }
        }
    }
}

usort($rows, static fn(array $a, array $b): int => [$a[2], $a[0], (int) $a[1]] <=> [$b[2], $b[0], (int) $b[1]]);

$measured = '';

foreach ($rows as $row) {
    $measured .= implode("\t", $row) . "\n";
}

if (!$check) {
    echo $measured;
    fwrite(STDERR, sprintf("%d authored sites in %s\n", count($rows), $directory));

    exit(0);
}

$committed = is_file($root . '/' . ARTIFACT) ? file_get_contents($root . '/' . ARTIFACT) : false;

if ($committed === false) {
    fwrite(STDERR, ARTIFACT . " is missing; regenerate it.\n");

    exit(1);
}

$tracked = implode("\n", array_values(array_filter(
    explode("\n", $committed),
    static fn(string $line): bool => $line !== '' && !str_starts_with($line, '#') && !str_starts_with($line, "file\t"),
))) . "\n";

if ($tracked === $measured) {
    fwrite(STDERR, sprintf("%s: up to date (%d authored sites).\n", ARTIFACT, count($rows)));

    exit(0);
}

fwrite(STDERR, sprintf(
    "%s is stale: it lists %d authored site(s) and a fresh measurement finds %d."
    . " Refresh it with `php scripts/enumerate-inline-directives.php src`.\n",
    ARTIFACT,
    substr_count($tracked, "\n"),
    count($rows),
));

exit(1);
