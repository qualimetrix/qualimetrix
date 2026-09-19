<?php

declare(strict_types=1);

/**
 * Holds the two witnesses against each other: the declarations read out of the
 * container (`declared-options.tsv`) and the allowed set the shipped binary
 * names in its refusal (`runtime-refusal.tsv`).
 *
 * The three framework keys are added to the declared side at depth 1 and only
 * there, because no options class declares them — the factory takes them out of
 * the user's config before `fromArray()` ever sees it.
 *
 *     php docs/internal/plans/rules-listing/measurement/check-agreement.php
 *
 * Exit 0 when every pair agrees, 1 on the first disagreement.
 */

const FRAMEWORK_KEYS = ['suppress-namespace-channels', 'suppress-namespaces', 'suppress-paths'];

$declared = [];

foreach (array_slice(file(__DIR__ . '/declared-options.tsv', FILE_IGNORE_NEW_LINES), 1) as $line) {
    [$rule, $level, $option] = explode("\t", $line);
    $declared[$rule . '|' . $level][] = $option;
}

$disagreements = 0;
$pairs = 0;

foreach (array_slice(file(__DIR__ . '/runtime-refusal.tsv', FILE_IGNORE_NEW_LINES), 1) as $line) {
    [$rule, $level, $options] = explode("\t", $line);
    ++$pairs;

    $runtime = array_map(trim(...), explode(',', $options));
    sort($runtime);

    $expected = $declared[$rule . '|' . $level] ?? [];

    if ($level === '-') {
        $expected = array_merge($expected, FRAMEWORK_KEYS);
    }

    sort($expected);

    if ($runtime === $expected) {
        continue;
    }

    ++$disagreements;
    printf(
        "DISAGREEMENT %s [%s]\n  runtime:  %s\n  declared: %s\n",
        $rule,
        $level,
        implode(', ', $runtime),
        implode(', ', $expected),
    );
}

printf("%d pairs compared, %d disagreements\n", $pairs, $disagreements);

exit($disagreements === 0 ? 0 : 1);
