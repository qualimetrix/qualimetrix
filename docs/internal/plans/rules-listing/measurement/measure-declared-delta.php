<?php

declare(strict_types=1);

/**
 * How large the `bin/qmx rules` declared delta would be, counted in the unit
 * the gate actually uses.
 *
 * `ExactDiff::changedLineCount()` sums BOTH sides of every hunk, and with
 * `ANCHOR = 4` a run of fewer than four identical lines does not split a hunk —
 * so context between two nearby edits is counted as change. A count of
 * added-and-removed lines is therefore not the gate's count, and reading one as
 * the other under-reports by more than half here.
 *
 * The "after" listing is a PREDICTION built from `declared-options.tsv` and the
 * format the plan specifies, not a product measurement: no code implements it
 * yet. This instrument is therefore **pre-implementation guidance only**.
 *
 * Once the command actually prints its options, this script would read that
 * listing as its "before" and insert the option lines a second time, measuring
 * a doubled listing against the real one and reporting a smaller delta than the
 * change really has. It refuses to run in that state rather than answering
 * wrongly. After the change exists, the gate's own derive is the measurement:
 * it reports `delta-too-large` or it does not.
 *
 *     php docs/internal/plans/rules-listing/measurement/measure-declared-delta.php
 */

use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;

$repositoryRoot = dirname(__DIR__, 5);
require $repositoryRoot . '/vendor/autoload.php';

spl_autoload_register(static function (string $class) use ($repositoryRoot): void {
    $short = substr($class, strrpos($class, '\\') + 1);
    $path = $repositoryRoot . '/scripts/finding-gate/' . $short . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});

exec(escapeshellarg($repositoryRoot . '/bin/qmx') . ' rules', $beforeLines, $status);

if ($status !== 0) {
    fwrite(STDERR, "bin/qmx rules exited {$status}\n");

    exit(1);
}

foreach ($beforeLines as $line) {
    if (str_starts_with($line, '    options')) {
        fwrite(STDERR, <<<'TEXT'
            This is a pre-implementation predictor and the listing already prints its
            options, so its "before" is no longer before: it would insert the option
            lines a second time and report a delta smaller than the real one.
            Ask the gate instead: composer gate -- --reference=<predecessor> --derive-declared-delta.

            TEXT);

        exit(1);
    }
}

$here = [];
$atLevel = [];

foreach (array_slice(file(__DIR__ . '/declared-options.tsv', FILE_IGNORE_NEW_LINES), 1) as $line) {
    [$rule, $level, $option, $kind] = explode("\t", $line);

    if ($level === '-') {
        if ($kind === 'option') {
            $here[$rule][] = $option;
        }
    } else {
        $atLevel[$rule][$level][] = $option;
    }
}

$kebab = static fn(string $target): string => implode('.', array_map(
    static fn(string $part): string => ConfigKeySpelling::rewriteLike(ConfigKeySpelling::normalize($part), 'a-b'),
    explode('.', $target),
));

/**
 * @param list<string> $lines
 * @return list<string>
 */
$render = static function (array $lines, bool $withOptions, bool $withLevels, bool $withFooter) use (
    $here, $atLevel, $kebab
): array {
    $out = [];
    $rule = null;
    $flushed = [];

    $flush = static function () use (&$out, &$rule, &$flushed, $here, $atLevel, $withOptions, $withLevels): void {
        if ($rule === null || isset($flushed[$rule])) {
            return;
        }

        $flushed[$rule] = true;

        if ($withOptions && isset($here[$rule])) {
            $out[] = '    options: ' . implode(', ', $here[$rule]);
        }

        if ($withLevels) {
            foreach ($atLevel[$rule] ?? [] as $level => $keys) {
                $out[] = "    options at {$level}: " . implode(', ', $keys);
            }
        }
    };

    foreach ($lines as $line) {
        if (preg_match('/^  (\S+)\s{2,}\S/', $line, $match)) {
            $flush();
            $rule = $match[1];
            $out[] = $line;

            continue;
        }

        if (preg_match('/^    --(\S+) \(--rule-opt=([^:]+):([^=]+)=\.\.\.\)$/', $line, $match)) {
            $flush();
            $out[] = "    --{$match[1]} (--rule-opt={$match[2]}:{$kebab($match[3])}=...)";

            continue;
        }

        if ($line !== '' && !str_starts_with($line, '    ')) {
            $flush();
        }

        if ($withFooter && str_starts_with($line, 'Usage: bin/qmx check --disable-rule')) {
            $out[] = 'Every rule also takes: suppress-paths, suppress-namespaces, suppress-namespace-channels';
            $out[] = '';
        }

        $out[] = $line;
    }

    $flush();

    return $out;
};

$before = implode("\n", $beforeLines) . "\n";
$aliasesOnly = implode("\n", $render($beforeLines, false, false, false)) . "\n";
$afterLines = $render($beforeLines, true, true, true);
$after = implode("\n", $afterLines) . "\n";

// The same two halves in the other order: the options first, the alias
// spelling afterwards. Which half lands first decides which landing carries
// the larger delta, and the limit is judged per landing, not per round.
$authoredAliases = array_values(array_filter(
    $beforeLines,
    static fn(string $line): bool => str_starts_with($line, '    --'),
));

$next = 0;
$optionsOnly = implode("\n", array_map(
    static function (string $line) use ($authoredAliases, &$next): string {
        return str_starts_with($line, '    --') ? $authoredAliases[$next++] : $line;
    },
    $afterLines,
)) . "\n";

$count = static fn(string $left, string $right): int => QmxFindingGate\ExactDiff::between(
    $left,
    $right,
    'reference',
    'candidate',
)->changedLineCount();

printf("limit (DeclaredDelta::MAX_CHANGED_LINES) = %d\n", QmxFindingGate\DeclaredDelta::MAX_CHANGED_LINES);
printf("one step   before -> full                = %d\n", $count($before, $after));
printf("\norder A    before -> alias spelling      = %d\n", $count($before, $aliasesOnly));
printf("           alias spelling -> full        = %d\n", $count($aliasesOnly, $after));
printf("\norder B    before -> options + footer    = %d\n", $count($before, $optionsOnly));
printf("           options -> alias spelling     = %d\n", $count($optionsOnly, $after));
