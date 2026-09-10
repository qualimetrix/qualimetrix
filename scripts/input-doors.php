<?php

declare(strict_types=1);

/**
 * The input-door stand: what each user-input door does when its value points
 * at nothing.
 *
 * Usage:
 *   php scripts/input-doors.php                       write the verdict snapshot
 *   php scripts/input-doors.php --check               0 fresh, 1 drift
 *   php scripts/input-doors.php --stability           repeat the M side, demand the same text
 *   php scripts/input-doors.php --before              recompute the frozen pre-cure verdicts
 *   php scripts/input-doors.php --freeze-before --product-root=<archive of the pre-cure commit>
 *   php scripts/input-doors.php --refreeze-before --reason=<why the shot had to be retaken>
 *
 * Exit codes: 0 clean, 1 a red outcome or drift, 2 a declaration the loader
 * refuses to read.
 */

namespace Qualimetrix\InputDoors;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/input-doors/Declarations.php';
require __DIR__ . '/input-doors/Normalizer.php';
require __DIR__ . '/input-doors/Classifier.php';
require __DIR__ . '/input-doors/Runner.php';
require __DIR__ . '/input-doors/Stand.php';

/**
 * @param list<string> $argv
 *
 * @return array<string, string>
 */
function parseArguments(array $argv): array
{
    $options = [];

    foreach (\array_slice($argv, 1) as $argument) {
        if (!str_starts_with($argument, '--')) {
            continue;
        }

        $halves = explode('=', substr($argument, 2), 2);
        $options[$halves[0]] = $halves[1] ?? '1';
    }

    return $options;
}

/** @return array<string, list<string>> */
function commandOptions(string $root): array
{
    $path = $root . '/docs/internal/generated/input-doors/command-options.tsv';
    $lines = file($path, \FILE_IGNORE_NEW_LINES);

    if ($lines === false) {
        throw new DeclarationError('missing ' . $path);
    }

    array_shift($lines);
    $map = [];

    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }

        [$command, $options] = array_pad(explode("\t", $line, 2), 2, '');
        $map[$command] = $options === '' ? [] : explode(',', $options);
    }

    // The application-level definition is shared by every command; the grid
    // carries it under its own pseudo-command and it declares no invariant.
    $map['(global)'] = [];

    return $map;
}

/** @param list<StandRow> $rows */
function renderVerdicts(array $rows): string
{
    $out = "surface\tcommand\tdoor\tsite\toutcome\tdecided_by\tsignal_source\tnote\n";
    $lines = [];

    foreach ($rows as $row) {
        $lines[] = implode("\t", [
            $row->row->surface,
            $row->row->command,
            $row->row->door,
            $row->row->site,
            $row->verdict->outcome,
            $row->verdict->decidedBy,
            $row->signalSource,
            str_replace(["\t", "\n"], ' ', $row->verdict->note),
        ]);
    }

    sort($lines, \SORT_STRING);

    return $out . implode("\n", $lines) . "\n";
}

/**
 * @param list<StandRow> $rows
 *
 * @return array<string, int>
 */
function summarize(array $rows): array
{
    $counts = [];

    foreach ($rows as $row) {
        $label = $row->verdict->outcome;

        if ($label === Verdict::SILENT) {
            $label .= ' (' . $row->verdict->decidedBy . ')';
        }

        $counts[$label] = ($counts[$label] ?? 0) + 1;
    }

    ksort($counts);

    return $counts;
}

$root = \dirname(__DIR__);
/** @var list<string> $argv */
$argv = $_SERVER['argv'] ?? [];
$arguments = parseArguments($argv);
$scratch = $arguments['scratch'] ?? sys_get_temp_dir() . '/qmx-input-doors';
$productRoot = $arguments['product-root'] ?? $root;

try {
    $declarations = Declarations::load($root);
    $runner = new Runner($root, $productRoot, $scratch, commandOptions($root));
    $stand = new Stand($root, $declarations, $runner);
} catch (DeclarationError $error) {
    fwrite(\STDERR, 'input-doors: ' . $error->getMessage() . "\n");

    exit(2);
}

if (isset($arguments['freeze-before']) || isset($arguments['refreeze-before'])) {
    $refreeze = isset($arguments['refreeze-before']);
    $resolved = realpath($productRoot);

    if ($resolved === false) {
        fwrite(\STDERR, "input-doors: --product-root does not exist\n");

        exit(2);
    }

    // A "before" shot taken against the working tree would silently measure the
    // cured product and freeze it as evidence of what the product used to do.
    $probe = [];
    exec(escapeshellarg(\PHP_BINARY) . ' -r ' . escapeshellarg(
        'require ' . var_export($resolved . '/vendor/autoload.php', true) . ';'
        . ' echo (new ReflectionClass(Qualimetrix\Infrastructure\Console\Command\CheckCommand::class))->getFileName();',
    ), $probe);
    $resolvedFrom = $probe[0] ?? '';

    if (!str_starts_with($resolvedFrom, $resolved . '/')) {
        fwrite(\STDERR, \sprintf(
            "input-doors: the product root's autoloader resolves to %s, outside %s — a symlinked vendor would freeze the cured tree as the pre-cure snapshot\n",
            $resolvedFrom,
            $resolved,
        ));

        exit(2);
    }

    exit($stand->freeze($root . '/' . Stand::OBSERVATIONS_DIR, $refreeze, $arguments['reason'] ?? ''));
}

if (isset($arguments['stability'])) {
    $unstable = $stand->unstableSurfaces();

    foreach ($unstable as $line) {
        fwrite(\STDERR, $line . "\n");
    }

    printf("Surface stability: %d unstable of the text observables probed.\n", \count($unstable));

    exit($unstable === [] ? 0 : 1);
}

if (isset($arguments['before'])) {
    // The echo claim describes the tree under test; on the frozen pre-cure
    // snapshot the product printed nothing to echo, and enforcing the claim
    // there hid the very silence the pair exists to show.
    $rows = $stand->before(new ClassifierOptions(echoClaimChecked: false));
    $offenders = $stand->speaksBeforeCure($rows);

    foreach (summarize($rows) as $label => $count) {
        printf("  %-34s %d\n", $label, $count);
    }

    foreach ($offenders as $offender) {
        fwrite(\STDERR, 'SPEAKS BEFORE CURE: ' . $offender . "\n");
    }

    exit($offenders === [] ? 0 : 1);
}

$rows = $stand->run();
$rendered = renderVerdicts($rows);
$cure = array_flip($declarations->cureSites);
$unguarded = [];
$configurationRows = 0;
$configurationUnobservable = 0;

foreach ($rows as $row) {
    if ($row->row->surface === 'config' && $row->row->referential) {
        ++$configurationRows;

        if ($row->verdict->outcome === Verdict::NOT_OBSERVABLE) {
            ++$configurationUnobservable;
        }
    }

    // A SPEAKS with no empty hit and no place in the cure list is guarded by
    // neither the H0 side of guard 1 nor guard 2. The plan expects none; the
    // expectation is printed and checked rather than assumed, because a rising
    // count means a third axis has appeared.
    if ($row->verdict->outcome === Verdict::SPEAKS && !$row->hasEmptyHit && !isset($cure[$row->row->key()])) {
        $unguarded[] = $row->row->key();
    }
}
$target = $root . '/' . Stand::VERDICTS_PATH;
$red = [];

foreach ($rows as $row) {
    if ($row->verdict->isRed() && $row->verdict->outcome !== 'NOT REFERENTIAL') {
        $red[] = $row->row->key() . ' -> ' . $row->verdict->outcome . ' (' . $row->verdict->note . ')';
    }
}

if (isset($arguments['check'])) {
    $current = is_file($target) ? (string) file_get_contents($target) : '';

    if ($current !== $rendered) {
        fwrite(\STDERR, "input-door verdicts are stale: run composer input-doors\n");

        exit(1);
    }
} else {
    if (!is_dir(\dirname($target))) {
        mkdir(\dirname($target), 0o775, true);
    }

    file_put_contents($target, $rendered);
}

foreach (summarize($rows) as $label => $count) {
    printf("  %-34s %d\n", $label, $count);
}

printf("  %-34s %d\n", 'product runs', $runner->runs());
printf("  %-34s %d\n", 'SPEAKS (unguarded)', \count($unguarded));
printf(
    "  %-34s %d of %d (%.1f%%)\n",
    'NOT OBSERVABLE, configuration half',
    $configurationUnobservable,
    $configurationRows,
    $configurationRows === 0 ? 0.0 : $configurationUnobservable / $configurationRows * 100,
);

foreach ($unguarded as $key) {
    printf("      unguarded: %s\n", $key);
}

foreach ($red as $line) {
    fwrite(\STDERR, $line . "\n");
}

exit($red === [] ? 0 : 1);
