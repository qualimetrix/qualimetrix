<?php

declare(strict_types=1);

/**
 * Generates the input-door grid — the denominator of the silent-acceptance
 * oracle (`docs/internal/plans/silent-acceptance/01-oracle.md` §3).
 *
 * Row key is `surface|command|door|site`, and no part of it is constant: a CLI
 * door is multiplied over every command whose `InputDefinition` declares it, a
 * configuration door over every command that declares `--config`. There is no
 * `(qmx.yaml)` placeholder command, because a placeholder is verdict
 * inheritance under another name.
 *
 * The generator performs zero `bin/qmx` runs: the grid is a statement about
 * declarations, not about behaviour.
 *
 * Usage:
 *   php scripts/generate-input-door-table.php            # write
 *   php scripts/generate-input-door-table.php --check    # 0 fresh, 1 drift
 */

namespace Qualimetrix\InputDoors;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Symfony\Component\Console\Input\InputDefinition;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/input-doors-bootstrap.php';

const GRID_PATH = 'docs/internal/generated/input-doors/doors.tsv';
const ANNOTATIONS_PATH = 'input-doors/door-annotations.tsv';
const RECONCILED_PATH = 'docs/internal/plans/silent-acceptance/measurement/doors-reconciled.tsv';
const OPTIONS_PATH = 'docs/internal/generated/input-doors/command-options.tsv';

/**
 * Configuration doors are the leaf keys a user can write, not their roots:
 * `cache.dir` and `cache.enabled` are two things to get wrong, and a probe
 * needs a leaf to miss on. The plan's §3 counts 17 roots; leaf granularity
 * gives 18 and the difference is named in `input-doors/README.md`.
 *
 * DOCUMENT_ROOTS is read alongside ENTRIES on purpose: `excludeHealth` and
 * `computedMetrics` have no ENTRIES row, and a generator reading ENTRIES alone
 * loses them silently. That loss is asserted against below.
 *
 * @return list<string>
 */
function configurationDoors(): array
{
    $doors = [];

    foreach (ConfigSchema::ENTRIES as [$sourcePath]) {
        $doors[$sourcePath] = true;
    }

    foreach (ConfigSchema::DOCUMENT_ROOTS as $root) {
        $covered = false;

        foreach (array_keys($doors) as $existing) {
            if ($existing === $root || str_starts_with($existing, $root . '.')) {
                $covered = true;

                break;
            }
        }

        if (!$covered) {
            $doors[$root] = true;
        }
    }

    $names = array_keys($doors);
    sort($names, \SORT_STRING);

    // Guards the K8 breakage: a generator narrowed to ENTRIES drops these two.
    foreach ([ConfigSchema::EXCLUDE_HEALTH, ConfigSchema::COMPUTED_METRICS] as $required) {
        if (!\in_array($required, $names, true)) {
            fail(\sprintf(
                'configuration doors lost %s: DOCUMENT_ROOTS must be read alongside ENTRIES',
                $required,
            ));
        }
    }

    return $names;
}

/**
 * Doors a command declares: every argument, and every option that accepts a
 * value. A valueless flag carries nothing to miss with and is not a door.
 *
 * @return list<array{string, string}> door name and kind
 */
function doorsOf(InputDefinition $definition): array
{
    $doors = [];

    foreach ($definition->getArguments() as $argument) {
        $doors[] = [$argument->getName(), $argument->isArray() ? 'argument[]' : 'argument'];
    }

    foreach ($definition->getOptions() as $option) {
        if (!$option->acceptValue()) {
            continue;
        }

        $doors[] = ['--' . $option->getName(), $option->isArray() ? 'option[]' : 'option'];
    }

    return $doors;
}

/**
 * Handwritten half of the grid, keyed `surface|command|door`. `command = *`
 * matches every reflected command carrying that door — without it the six
 * `--rule-opt` rows would be six copies of one judgement, and a copy is where
 * they diverge. A wildcard matching nothing is as stale as an exact row
 * matching nothing.
 *
 * @return array<string, array{referential: string, reason: string, sites: list<string>, site_reason: string, shadowed_by: string, line: int}>
 */
function readAnnotations(string $root): array
{
    $path = $root . '/' . ANNOTATIONS_PATH;

    if (!is_file($path)) {
        fail('missing ' . ANNOTATIONS_PATH);
    }

    $lines = file($path, \FILE_IGNORE_NEW_LINES);

    if ($lines === false || $lines === []) {
        fail(ANNOTATIONS_PATH . ' is empty');
    }

    $header = explode("\t", array_shift($lines));
    $expected = ['surface', 'command', 'door', 'referential', 'reason', 'sites', 'site_reason', 'shadowed_by'];

    if ($header !== $expected) {
        fail(ANNOTATIONS_PATH . ' header must be: ' . implode(' ', $expected));
    }

    $rows = [];

    foreach ($lines as $index => $line) {
        if (trim($line) === '' || str_starts_with($line, '#')) {
            continue;
        }

        $cells = explode("\t", $line);

        if (\count($cells) !== \count($expected)) {
            fail(\sprintf('%s line %d: expected %d columns, got %d', ANNOTATIONS_PATH, $index + 2, \count($expected), \count($cells)));
        }

        [$surface, $command, $door, $referential, $reason, $sites, $siteReason, $shadowedBy] = $cells;
        $key = $surface . '|' . $command . '|' . $door;

        if (isset($rows[$key])) {
            fail(\sprintf('%s line %d: duplicate annotation for %s', ANNOTATIONS_PATH, $index + 2, $key));
        }

        if ($referential !== 'yes' && $referential !== 'no') {
            fail(\sprintf('%s line %d: referential must be yes or no', ANNOTATIONS_PATH, $index + 2));
        }

        if ($referential === 'no' && trim($reason) === '') {
            fail(\sprintf('%s line %d: referential=no without a reason (%s)', ANNOTATIONS_PATH, $index + 2, $key));
        }

        $siteList = $sites === '' ? ['whole-value'] : explode(',', $sites);

        // An explicit single-site declaration is a claim that the value has one
        // matching site inside it, and that claim needs a reason. An absent
        // annotation row is the default, not a claim.
        if ($sites !== '' && \count($siteList) === 1 && trim($siteReason) === '') {
            fail(\sprintf('%s line %d: a single declared site needs a reason (%s)', ANNOTATIONS_PATH, $index + 2, $key));
        }

        $rows[$key] = [
            'referential' => $referential,
            'reason' => $reason,
            'sites' => $siteList,
            'site_reason' => $siteReason,
            'shadowed_by' => $shadowedBy,
            'line' => $index + 2,
        ];
    }

    return $rows;
}

/**
 * Doors the reconciliation measurement calls split across several matching
 * sites. Under-declaring these is `SITES UNDERDECLARED`: the grid may be wider
 * than the measurement, never narrower.
 *
 * @return array<string, int> `surface|command|door` -> measured site count
 */
function measuredSiteCounts(string $root): array
{
    $path = $root . '/' . RECONCILED_PATH;

    if (!is_file($path)) {
        fail('missing ' . RECONCILED_PATH);
    }

    $lines = file($path, \FILE_IGNORE_NEW_LINES);

    if ($lines === false) {
        fail('unreadable ' . RECONCILED_PATH);
    }

    array_shift($lines);
    $counts = [];

    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }

        $cells = explode("\t", $line);

        if (\count($cells) < 9) {
            continue;
        }

        $verdict = $cells[8];

        if (!str_starts_with($verdict, 'SPLIT:')) {
            continue;
        }

        // "SPLIT: a; b" names two sites, "SPLIT: a; b; c" three.
        // The measurement carried configuration verdicts on a `(qmx.yaml)`
        // placeholder command; the grid has no such command, so the claim is
        // read as one about the door on every configuration command.
        $command = $cells[0] === 'config' ? '*' : $cells[1];
        $counts[$cells[0] . '|' . $command . '|' . $cells[2]] =
            substr_count($verdict, ';') + 1;
    }

    return $counts;
}

function fail(string $message): never
{
    fwrite(\STDERR, 'input-door grid: ' . $message . "\n");

    exit(1);
}

/** @return list<array{string, string, string, string, string, string, string, string}> */
function buildGrid(string $root): array
{
    $application = application();
    /** @var array<string, array{referential: string, reason: string, sites: list<string>, site_reason: string, shadowed_by: string, line: int}> $annotations */
    $annotations = readAnnotations($root);
    $measured = measuredSiteCounts($root);
    $used = [];
    $rows = [];

    /** @var list<array{string, string, string, string}> $declarations surface, command, door, kind */
    $declarations = [];

    foreach (doorsOf($application->getDefinition()) as [$door, $kind]) {
        $declarations[] = ['cli', '(global)', $door, $kind];
    }

    $commands = array_keys($application->all());
    sort($commands, \SORT_STRING);
    $configCommands = [];

    foreach ($commands as $name) {
        $definition = $application->get($name)->getDefinition();

        foreach (doorsOf($definition) as [$door, $kind]) {
            $declarations[] = ['cli', $name, $door, $kind];

            if ($door === '--config') {
                $configCommands[$name] = true;
            }
        }
    }

    $configCommandNames = array_keys($configCommands);
    sort($configCommandNames, \SORT_STRING);

    if ($configCommandNames === []) {
        fail('no command declares --config: the configuration half of the grid would inherit');
    }

    foreach (configurationDoors() as $door) {
        foreach ($configCommandNames as $command) {
            $declarations[] = ['config', $command, $door, 'config-key'];
        }
    }

    foreach ($declarations as [$surface, $command, $door, $kind]) {
        $exact = $surface . '|' . $command . '|' . $door;
        $wildcard = $surface . '|*|' . $door;
        $annotation = null;

        if (isset($annotations[$exact])) {
            $annotation = $annotations[$exact];
            $used[$exact] = true;
        } elseif (isset($annotations[$wildcard])) {
            $annotation = $annotations[$wildcard];
            $used[$wildcard] = true;
        }

        if ($annotation === null) {
            $annotation = [
                'referential' => 'yes',
                'reason' => '',
                'sites' => ['whole-value'],
                'site_reason' => '',
                'shadowed_by' => '',
            ];
        }

        $measuredCount = $measured[$exact] ?? $measured[$wildcard] ?? null;

        if ($measuredCount !== null && \count($annotation['sites']) < $measuredCount) {
            fail(\sprintf(
                'SITES UNDERDECLARED: %s declares %d site(s), doors-reconciled.tsv names %d',
                $exact,
                \count($annotation['sites']),
                $measuredCount,
            ));
        }

        foreach ($annotation['sites'] as $site) {
            $rows[] = [
                $surface,
                $command,
                $door,
                $site,
                $kind,
                $annotation['referential'],
                $annotation['reason'],
                $annotation['shadowed_by'],
            ];
        }
    }

    foreach ($annotations as $key => $annotation) {
        if (($used[$key] ?? false) === true) {
            continue;
        }

        fail(\sprintf('STALE ANNOTATION: %s (line %d) matches no declared door', $key, $annotation['line']));
    }

    usort(
        $rows,
        static fn(array $a, array $b): int => [$a[0], $a[1], $a[2], $a[3]] <=> [$b[0], $b[1], $b[2], $b[3]],
    );

    return $rows;
}

/**
 * Every option each command declares, valueless flags included. The grid holds
 * doors only, and a valueless flag is not a door — but the stand's invariants
 * (`--no-cache`) are valueless, and applying one to a command that does not
 * declare it turns the whole probe into a Symfony refusal.
 */
function renderCommandOptions(): string
{
    $application = application();
    $rows = [];
    $commands = array_keys($application->all());
    sort($commands, \SORT_STRING);

    foreach ($commands as $name) {
        $options = [];

        foreach ($application->get($name)->getDefinition()->getOptions() as $option) {
            $options[] = '--' . $option->getName();
        }

        sort($options, \SORT_STRING);
        $rows[] = $name . "\t" . implode(',', $options);
    }

    return "command\toptions\n" . implode("\n", $rows) . "\n";
}

function render(string $root): string
{
    $out = "surface\tcommand\tdoor\tsite\tkind\treferential\tnon_referential_reason\tshadowed_by\n";

    foreach (buildGrid($root) as $row) {
        $out .= implode("\t", $row) . "\n";
    }

    return $out;
}

$arguments = $_SERVER['argv'] ?? [];
$root = \dirname(__DIR__);
$check = \in_array('--check', $arguments, true);

// The control stand runs the generator over a working copy of the declarations;
// without an explicit root it would read the repository's own and prove nothing.
foreach ($arguments as $argument) {
    if (\is_string($argument) && str_starts_with($argument, '--root=')) {
        $root = substr($argument, 7);
    }
}
$artifacts = [
    GRID_PATH => render($root),
    OPTIONS_PATH => renderCommandOptions(),
];

foreach ($artifacts as $path => $rendered) {
    $target = $root . '/' . $path;

    if ($check) {
        $current = is_file($target) ? (string) file_get_contents($target) : '';

        if ($current !== $rendered) {
            fwrite(\STDERR, $path . " is stale: run composer input-doors:grid\n");

            exit(1);
        }

        printf("Checked %s: %d rows.\n", $path, substr_count($rendered, "\n") - 1);

        continue;
    }

    if (!is_dir(\dirname($target))) {
        mkdir(\dirname($target), 0o775, true);
    }

    file_put_contents($target, $rendered);
    printf("Wrote %s: %d rows.\n", $path, substr_count($rendered, "\n") - 1);
}
