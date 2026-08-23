#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricName;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Finder\Finder;

/**
 * Regenerates `docs/internal/plans/rule-vocabulary/enumeration-renames.tsv`.
 *
 * The identity set (which `old`/`kind` rows exist) is MEASURED from the
 * production container and from {@see MetricName} on every run — see
 * PLAN.md, "Карт три, и одна из них не про имена". The `new` column is a
 * DECISION Ш4/Ш5 make, not a measurement, so it is preserved across runs by
 * merging onto the existing file keyed by `old`+`kind` rather than being
 * recomputed. A row whose `new` was already filled in disappearing from the
 * measurement is a hard failure, not a silent drop — see mergeExistingNewColumn().
 *
 * The eight per-surface occurrence columns are declared once in SURFACES so
 * that adding a surface is a single-line change, and the footer's surface
 * list is generated from the same array so the two can never drift apart.
 */

/**
 * @return array<string, array{roots: list<string>, files: list<string>, excludeDirs: list<string>, excludeFiles: list<string>}>
 */
function surfaces(): array
{
    $presetFiles = [
        'src/Analysis/Configuration/Preset/ci.yaml',
        'src/Analysis/Configuration/Preset/legacy.yaml',
        'src/Analysis/Configuration/Preset/strict.yaml',
    ];

    return [
        'src' => [
            'roots' => ['src'],
            'files' => [],
            'excludeDirs' => ['node_modules', 'dist'],
            'excludeFiles' => [...$presetFiles, 'src/.gitkeep', 'src/Reporting/Template/package-lock.json'],
        ],
        'tests' => [
            'roots' => ['tests'],
            'files' => [],
            'excludeDirs' => ['__pycache__'],
            'excludeFiles' => [],
        ],
        'presets' => [
            'roots' => [],
            'files' => $presetFiles,
            'excludeDirs' => [],
            'excludeFiles' => [],
        ],
        'config' => [
            'roots' => [],
            'files' => ['qmx.yaml', 'qmx.yaml.example'],
            'excludeDirs' => [],
            'excludeFiles' => [],
        ],
        'baseline' => [
            'roots' => [],
            'files' => ['qmx-baseline.json'],
            'excludeDirs' => [],
            'excludeFiles' => [],
        ],
        'website_docs' => [
            'roots' => ['website/docs'],
            'files' => [],
            'excludeDirs' => [],
            'excludeFiles' => [],
        ],
        'benchmarks' => [
            'roots' => ['benchmarks'],
            'files' => [],
            'excludeDirs' => ['vendor'],
            'excludeFiles' => [],
        ],
        'finding_gate' => [
            'roots' => ['finding-gate'],
            'files' => [],
            'excludeDirs' => [],
            'excludeFiles' => [],
        ],
    ];
}

/**
 * @return array<string, string> channel key ("ruleName#violationCode") => the
 *                               violationCode half, which is the string a
 *                               consumer actually writes (selectors, `@qmx-ignore` targets, docs, fixtures)
 */
function channelIdentities(ContainerFactory $factory): array
{
    $container = $factory->create();
    $registry = $container->get(ChannelDeclarationRegistryInterface::class);
    assert($registry instanceof ChannelDeclarationRegistryInterface);

    $identities = [];

    foreach (array_keys($registry->staticDeclarations()) as $key) {
        $separatorPosition = strpos($key, '#');

        if ($separatorPosition === false) {
            throw new RuntimeException(sprintf('Channel key "%s" is missing the "#" separator.', $key));
        }

        $identities[$key] = substr($key, $separatorPosition + 1);
    }

    return $identities;
}

/**
 * @return list<string> producer (rule) names
 */
function producerIdentities(ContainerFactory $factory): array
{
    $container = $factory->create();
    $execution = $container->get(RuleExecutionInterface::class);
    assert($execution instanceof RuleExecutionInterface);

    $names = array_map(static fn($rule): string => $rule->name, $execution->allRules());
    $unique = array_values(array_unique($names));

    if (count($unique) !== count($names)) {
        throw new RuntimeException('RuleExecutionInterface::allRules() returned a duplicate rule name.');
    }

    return $unique;
}

/**
 * @return list<string> MetricName constant values
 */
function metricKeyIdentities(): array
{
    $reflection = new ReflectionClass(MetricName::class);
    $values = array_values($reflection->getConstants());

    $strings = array_map(static function (mixed $value) use ($reflection): string {
        if (!is_string($value)) {
            throw new RuntimeException(sprintf('%s declares a non-string constant.', $reflection->getName()));
        }

        return $value;
    }, $values);

    $unique = array_values(array_unique($strings));

    if (count($unique) !== count($strings)) {
        throw new RuntimeException(sprintf('%s declares two constants with the same value.', $reflection->getName()));
    }

    return $unique;
}

/**
 * @param array<string, array{roots: list<string>, files: list<string>, excludeDirs: list<string>, excludeFiles: list<string>}> $surfaces
 *
 * @return array<string, string> surface key => concatenated readable content of every file in scope
 */
function readSurfaceContents(array $surfaces, string $root): array
{
    $contents = [];

    foreach ($surfaces as $surfaceKey => $definition) {
        $chunks = [];

        foreach ($definition['files'] as $relativePath) {
            $chunks[] = readFileOrFail($root . '/' . $relativePath);
        }

        if ($definition['roots'] !== []) {
            $finder = new Finder();
            $finder->files()->in(array_map(static fn(string $relative): string => $root . '/' . $relative, $definition['roots']));

            foreach ($definition['excludeDirs'] as $excludeDir) {
                $finder->exclude($excludeDir);
            }

            foreach ($finder as $file) {
                if (isExcludedFile($file, $root, $definition['excludeFiles'])) {
                    continue;
                }

                $chunks[] = readFileOrFail($file->getPathname());
            }
        }

        $contents[$surfaceKey] = implode("\n\n", $chunks);
    }

    return $contents;
}

/**
 * @param list<string> $excludeFiles project-root-relative paths
 */
function isExcludedFile(SplFileInfo $file, string $root, array $excludeFiles): bool
{
    $relative = ltrim(substr($file->getPathname(), strlen($root)), '/');

    return in_array($relative, $excludeFiles, true);
}

function readFileOrFail(string $path): string
{
    if (!is_readable($path)) {
        throw new RuntimeException(sprintf('Could not read "%s".', $path));
    }

    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException(sprintf('Could not read "%s".', $path));
    }

    // Binary content (e.g. a stray compiled asset) can't hold a name literally;
    // treating it as opaque is safe because occurrences are counted textually.
    return mb_check_encoding($contents, 'UTF-8') ? $contents : '';
}

/**
 * Counts whole-identifier occurrences of $needle in $haystack: the match must
 * not be immediately preceded or followed by a word character, `.` or `-`.
 * Without this boundary, a short name like "design.type-coverage" would also
 * count every occurrence of "design.type-coverage.property" — the exact
 * prefix-collision class the plan's own rename map had to be fixed against
 * (see PLAN.md, "Карта сопоставляет имя, а не подстроку").
 */
function countWholeIdentifierOccurrences(string $needle, string $haystack): int
{
    $pattern = '/(?<![\w.-])' . preg_quote($needle, '/') . '(?![\w.-])/u';
    $matchCount = preg_match_all($pattern, $haystack);

    if ($matchCount === false) {
        throw new RuntimeException(sprintf('Regex failure while counting occurrences of "%s".', $needle));
    }

    return $matchCount;
}

/**
 * @param list<array{old: string, kind: string, search: string}> $rows
 * @param array<string, string> $surfaceContents surface key => content
 * @param list<string> $surfaceOrder
 *
 * @return list<array{old: string, kind: string, search: string, counts: array<string, int>}>
 */
function measure(array $rows, array $surfaceContents, array $surfaceOrder): array
{
    $measured = [];

    foreach ($rows as $row) {
        $counts = [];

        foreach ($surfaceOrder as $surfaceKey) {
            $counts[$surfaceKey] = countWholeIdentifierOccurrences($row['search'], $surfaceContents[$surfaceKey]);
        }

        $measured[] = [...$row, 'counts' => $counts];
    }

    return $measured;
}

/**
 * Reads the previously generated file (if any) and returns the `new` value
 * for every row that had one filled in, keyed by "old\tkind".
 *
 * @return array<string, string>
 */
function readExistingNewColumn(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $contents = readFileOrFail($path);
    $existing = [];

    foreach (explode("\n", $contents) as $line) {
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $columns = explode("\t", $line);

        if ($columns[0] === 'old') {
            continue; // header
        }

        if (count($columns) < 3) {
            continue;
        }

        [$old, $kind, $new] = $columns;

        if ($new !== '' && $new !== '?') {
            $existing[$old . "\t" . $kind] = $new;
        }
    }

    return $existing;
}

/**
 * Merges a previously decided `new` value onto a freshly measured row, and
 * fails loudly if a row that had a decision is missing from this run's
 * measurement — a disappearing identity must be investigated, not silently
 * dropped along with the decision recorded against it.
 *
 * @param list<array{old: string, kind: string, search: string, counts: array<string, int>}> $measuredRows
 * @param array<string, string> $existingNew
 *
 * @return list<array{old: string, kind: string, search: string, counts: array<string, int>, new: string}>
 */
function mergeExistingNewColumn(array $measuredRows, array $existingNew): array
{
    $merged = [];
    $seen = [];

    foreach ($measuredRows as $row) {
        $mergeKey = $row['old'] . "\t" . $row['kind'];
        $seen[$mergeKey] = true;
        $merged[] = [...$row, 'new' => $existingNew[$mergeKey] ?? '?'];
    }

    $missing = array_diff(array_keys($existingNew), array_keys($seen));

    if ($missing !== []) {
        throw new RuntimeException(
            'The following rows carried a decided "new" value in the previous file but no longer appear in the'
            . ' measurement — a disappeared identity with a recorded rename decision must be investigated, not'
            . ' silently dropped: ' . implode(', ', $missing),
        );
    }

    return $merged;
}

/**
 * @param list<array{old: string, kind: string, search: string, counts: array<string, int>, new: string}> $rows
 * @param list<string> $surfaceOrder
 */
function renderTsv(array $rows, array $surfaceOrder): string
{
    usort($rows, static fn(array $a, array $b): int => [$a['kind'], $a['old']] <=> [$b['kind'], $b['old']]);

    $header = ['old', 'kind', 'new', ...$surfaceOrder];
    $lines = [implode("\t", $header)];

    foreach ($rows as $row) {
        $line = [$row['old'], $row['kind'], $row['new']];

        foreach ($surfaceOrder as $surfaceKey) {
            $line[] = (string) $row['counts'][$surfaceKey];
        }

        $lines[] = implode("\t", $line);
    }

    $channelCount = count(array_filter($rows, static fn(array $row): bool => $row['kind'] === 'channel'));
    $producerCount = count(array_filter($rows, static fn(array $row): bool => $row['kind'] === 'producer'));
    $metricKeyCount = count(array_filter($rows, static fn(array $row): bool => $row['kind'] === 'metric-key'));

    return implode("\n", $lines) . "\n" . footer($surfaceOrder, $channelCount, $producerCount, $metricKeyCount);
}

/**
 * The row counts below are computed from $rows on every call, never a frozen
 * literal: a hand-written copy of a number this script also computes is
 * exactly the kind of drift `--check` exists to catch elsewhere, and a
 * footer is not exempt from that just because it renders as a comment. See
 * the DoD of this script's own generating step for how a point-in-time count
 * was cross-checked against the drift-guarded fixture and `bin/qmx rules`.
 *
 * @param list<string> $surfaceOrder
 */
function footer(array $surfaceOrder, int $channelCount, int $producerCount, int $metricKeyCount): string
{
    $surfaceList = implode(', ', $surfaceOrder);

    return <<<FOOTER
#
# ROW COUNTS: {$channelCount} channel, {$producerCount} producer, {$metricKeyCount} metric-key
#
# HOW THIS WAS PRODUCED: `php scripts/generate-rename-enumeration.php`.
# - `channel` rows: {$channelCount} keys from
#   `ChannelDeclarationRegistryInterface::staticDeclarations()` on the
#   production container (the same oracle
#   `ChannelDeclarationFixtureDriftTest` uses) — never regex over rule files,
#   because a rule can name its own channel through an inherited constant.
# - `producer` rows: {$producerCount} names from
#   `RuleExecutionInterface::allRules()` (`RuleMetadata::name`).
# - `metric-key` rows: {$metricKeyCount} values, `ReflectionClass(MetricName::class)->getConstants()`.
#   `MetricName` is a plain final class with `public const string` members,
#   not a backed enum — reflecting its constants is the equivalent measurement.
# Occurrence columns ({$surfaceList}) count WHOLE-IDENTIFIER matches of the
# search string (the channel's `violationCode` half for `channel` rows, the
# name itself for `producer` and `metric-key` rows) — not files. A match must
# not be immediately preceded or followed by a word character, `.` or `-`, so
# a short name like "design.type-coverage" does not also count every
# occurrence of "design.type-coverage.property" (see PLAN.md, "Карта
# сопоставляет имя, а не подстроку", for why that prefix collision matters).
#
# BOUNDARY OF THE SET: every file under the eight surfaces above, minus
# generated/vendored noise (src/Reporting/Template/{node_modules,dist},
# benchmarks/vendor, __pycache__) and the three preset YAML files, which are
# counted once, under `presets`, not again under `src`.
#
# THE `new` COLUMN IS A DECISION, NOT A MEASUREMENT. This script never invents
# one: an unfilled row keeps `?`. A row's `new` value, once someone fills it
# in, survives every later regeneration (merged back in by `old`+`kind`); if
# the row it was recorded against stops being measured, the script fails
# instead of quietly dropping the decision.
#
# WHAT THIS METHOD DOES NOT SEE:
#  - a name assembled at runtime (string concatenation, a value read from
#    `RuleMetadata::name` in code and stored under a different local
#    variable) is invisible to whole-identifier text matching, in exactly the
#    same way `enumeration-consumers.tsv` and `enumeration-rules.tsv` already
#    warn about grep-based extraction;
#  - `computed.*`/`health.*` beyond the six built-in health dimensions is
#    open-ended by construction — a user's own `computed_metrics:` entries in
#    their own qmx.yaml are not, and cannot be, enumerated here (same
#    boundary `enumeration-rules.tsv` names for `computed.health`);
#  - occurrence counts are OVERSTATED by prose that mentions a name in running
#    text without meaning "this literal identifier is written here" (a
#    website doc sentence that happens to contain the words), and
#    UNDERSTATED by string concatenation that assembles the identifier from
#    parts (`'design.' . 'type-coverage'`) or by a fixture line that
#    round-trips the name through a different serialization (e.g. escaped
#    slashes in a JSON string spanning what a plain-text regex reads as two
#    tokens);
#  - a channel's `ruleName` half is not counted on its own: for the channels
#    where `ruleName` differs from `violationCode` (the diagnostics on
#    `architecture.layer-violation` and `annotation.directive`), the
#    `ruleName` half's occurrences show up in the corresponding `producer`
#    row instead, because the two strings are identical text.
FOOTER;
}

/**
 * Measures the current identity set and renders it to a TSV string, merging
 * onto whatever `new` decisions already sit in $outputPath. Used by both the
 * generate and the `--check` path so the two can never measure differently.
 *
 * @return array{content: string, channelCount: int, producerCount: int, metricKeyCount: int}
 */
function renderCurrentState(string $root, string $outputPath, ContainerFactory $factory): array
{
    $rows = [];

    foreach (channelIdentities($factory) as $old => $search) {
        $rows[$old . "\tchannel"] = ['old' => $old, 'kind' => 'channel', 'search' => $search];
    }

    foreach (producerIdentities($factory) as $name) {
        $rows[$name . "\tproducer"] = ['old' => $name, 'kind' => 'producer', 'search' => $name];
    }

    foreach (metricKeyIdentities() as $value) {
        $rows[$value . "\tmetric-key"] = ['old' => $value, 'kind' => 'metric-key', 'search' => $value];
    }

    $surfaceOrder = array_keys(surfaces());
    $surfaceContents = readSurfaceContents(surfaces(), $root);

    $measuredRows = measure(array_values($rows), $surfaceContents, $surfaceOrder);
    $existingNew = readExistingNewColumn($outputPath);
    $finalRows = mergeExistingNewColumn($measuredRows, $existingNew);

    return [
        'content' => renderTsv($finalRows, $surfaceOrder),
        'channelCount' => count(array_filter($finalRows, static fn(array $row): bool => $row['kind'] === 'channel')),
        'producerCount' => count(array_filter($finalRows, static fn(array $row): bool => $row['kind'] === 'producer')),
        'metricKeyCount' => count(array_filter($finalRows, static fn(array $row): bool => $row['kind'] === 'metric-key')),
    ];
}

/**
 * Describes exactly which lines of the committed file disagree with a fresh
 * regeneration, so a `--check` failure names the divergence instead of just
 * asserting one exists. The `new` column can never be part of that
 * divergence: $fresh was rendered by merging $onDisk's own decided `new`
 * values (see mergeExistingNewColumn()), so a human decision recorded on
 * disk is reproduced verbatim, not recomputed.
 */
function describeMismatch(string $onDisk, string $fresh, string $path): string
{
    $onDiskLines = explode("\n", $onDisk);
    $freshLines = explode("\n", $fresh);
    $lineCount = max(count($onDiskLines), count($freshLines));

    $diffs = [];

    for ($line = 0; $line < $lineCount; $line++) {
        $committed = $onDiskLines[$line] ?? '<no such line on disk>';
        $regenerated = $freshLines[$line] ?? '<line removed by regeneration>';

        if ($committed !== $regenerated) {
            $diffs[] = sprintf(
                "  line %d:\n    committed:    %s\n    regenerated:  %s",
                $line + 1,
                $committed,
                $regenerated,
            );
        }
    }

    $shown = array_slice($diffs, 0, 10);
    $summary = sprintf(
        "%s is stale: %d of %d line(s) differ from a fresh measurement. Run `composer enumeration:renames` to refresh it.\n",
        $path,
        count($diffs),
        $lineCount,
    );

    if (count($diffs) > count($shown)) {
        $shown[] = sprintf('  ... and %d more differing line(s).', count($diffs) - count($shown));
    }

    return $summary . implode("\n", $shown) . "\n";
}

function main(): int
{
    $arguments = array_slice($_SERVER['argv'] ?? [], 1);
    $check = in_array('--check', $arguments, true);
    $unknown = array_values(array_filter($arguments, static fn(string $argument): bool => $argument !== '--check'));

    if ($unknown !== []) {
        fwrite(STDERR, 'Unknown argument: ' . implode(', ', $unknown) . "\n");

        return 2;
    }

    $root = dirname(__DIR__);
    $outputPath = $root . '/docs/internal/plans/rule-vocabulary/enumeration-renames.tsv';
    $factory = new ContainerFactory();

    $state = renderCurrentState($root, $outputPath, $factory);

    if ($check) {
        $onDisk = is_file($outputPath) ? file_get_contents($outputPath) : false;

        if ($onDisk === false) {
            fwrite(STDERR, sprintf("%s does not exist. Run `composer enumeration:renames` to generate it.\n", $outputPath));

            return 1;
        }

        if ($onDisk !== $state['content']) {
            fwrite(STDERR, describeMismatch($onDisk, $state['content'], $outputPath));

            return 1;
        }

        fwrite(STDOUT, sprintf(
            "Checked %s: up to date (%d channel, %d producer, %d metric-key rows).\n",
            $outputPath,
            $state['channelCount'],
            $state['producerCount'],
            $state['metricKeyCount'],
        ));

        return 0;
    }

    $tmpPath = $outputPath . '.tmp.' . getmypid();
    file_put_contents($tmpPath, $state['content']);
    rename($tmpPath, $outputPath);

    fwrite(STDOUT, sprintf(
        "Wrote %s: %d channel, %d producer, %d metric-key rows.\n",
        $outputPath,
        $state['channelCount'],
        $state['producerCount'],
        $state['metricKeyCount'],
    ));

    return 0;
}

exit(main());
