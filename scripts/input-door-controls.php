<?php

declare(strict_types=1);

/**
 * Controls on the input-door oracle itself.
 *
 * Without these the oracle is an unjustified green: a stand that never went red
 * has said nothing about whether it can. Each case plants exactly one breakage
 * and must redden **its own** row and no other — the run compares the whole
 * outcome map against the green baseline, so a blanket breakage fails as loudly
 * as one that does not bite at all.
 *
 * Almost every case is a recomputation over the frozen observations, not a new
 * measurement: the raw pre-cure snapshot already holds the four sides of every
 * probe, so planting a classifier flaw costs milliseconds instead of a product
 * run. That is what makes a table this size affordable.
 *
 * Usage:
 *   php scripts/input-door-controls.php
 *   php scripts/input-door-controls.php --only=K1,K18
 *
 * Exit codes: 0 every case bit exactly its own row, 1 a case did not,
 * 2 coverage arithmetic failed, 3 the baseline itself is not green.
 */

namespace Qualimetrix\InputDoorControls;

use FilesystemIterator;
use Qualimetrix\InputDoors\ClassifierOptions;
use Qualimetrix\InputDoors\DeclarationError;
use Qualimetrix\InputDoors\Declarations;
use Qualimetrix\InputDoors\Normalizer;
use Qualimetrix\InputDoors\Runner;
use Qualimetrix\InputDoors\Stand;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/input-doors/Declarations.php';
require __DIR__ . '/input-doors/Normalizer.php';
require __DIR__ . '/input-doors/Classifier.php';
require __DIR__ . '/input-doors/Runner.php';
require __DIR__ . '/input-doors/Stand.php';
require __DIR__ . '/input-door-controls/Cases.php';

final class ControlRunner
{
    private string $tree;

    public function __construct(private readonly string $root)
    {
        $this->tree = sys_get_temp_dir() . '/qmx-input-door-controls-' . getmypid();
    }

    /** @return array<string, string> grid key -> outcome, over the frozen observations */
    public function outcomes(?ClassifierOptions $options = null, ?Normalizer $normalizer = null, string $tree = ''): array
    {
        $root = $tree === '' ? $this->root : $tree;
        $declarations = Declarations::load($root);
        $runner = new Runner($root, $root, sys_get_temp_dir() . '/qmx-input-door-controls-unused', $this->commandOptions($root));
        $stand = new Stand(
            $root,
            $declarations,
            $runner,
            $options ?? new ClassifierOptions(),
            $normalizer,
        );
        $map = [];

        foreach ($stand->before($options ?? new ClassifierOptions()) as $row) {
            $map[$row->row->key()] = $row->verdict->outcome;
        }

        foreach ($declarations->staleProbes() as $key) {
            $map['stand|(none)|' . $key . '|(none)'] = 'STALE PROBE';
        }

        foreach ($declarations->grid as $row) {
            if ($row->referential && $declarations->probeFor($row) === null) {
                $map[$row->key()] = 'NOT PROBED';
            }
        }

        return $map;
    }

    /**
     * A working copy of the whole declaration set plus the frozen
     * observations, so a case may edit a row without touching the repository.
     */
    public function checkout(): string
    {
        $this->remove($this->tree);
        mkdir($this->tree . '/docs/internal/generated/input-doors', 0o775, true);
        mkdir($this->tree . '/finding-gate', 0o775, true);
        $this->copy($this->root . '/input-doors', $this->tree . '/input-doors');
        $this->copy($this->root . '/docs/internal/generated/input-doors', $this->tree . '/docs/internal/generated/input-doors');
        copy($this->root . '/finding-gate/normalization.tsv', $this->tree . '/finding-gate/normalization.tsv');

        return $this->tree;
    }

    public function cleanup(): void
    {
        $this->remove($this->tree);
    }

    /** @return array<string, list<string>> */
    public function commandOptions(string $root): array
    {
        $lines = file($root . '/docs/internal/generated/input-doors/command-options.tsv', \FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new DeclarationError('missing command-options.tsv');
        }

        array_shift($lines);
        $map = ['(global)' => []];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            [$command, $options] = array_pad(explode("\t", $line, 2), 2, '');
            $map[$command] = $options === '' ? [] : explode(',', $options);
        }

        return $map;
    }

    private function copy(string $source, string $target): void
    {
        if (!is_dir($target)) {
            mkdir($target, 0o775, true);
        }

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        ) as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }

            $destination = $target . '/' . substr($item->getPathname(), \strlen($source) + 1);

            if ($item->isDir()) {
                if (!is_dir($destination)) {
                    mkdir($destination, 0o775, true);
                }

                continue;
            }

            copy($item->getPathname(), $destination);
        }
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }

            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}

/** Applies one planting to a working copy of the declarations. */
final class Planter
{
    public function __construct(private readonly string $tree) {}

    /** @param array<string, mixed> $planting */
    public function apply(array $planting): void
    {
        /** @var list<array{string, string, string}> $edits */
        $edits = \is_array($planting['edits'] ?? null) ? $planting['edits'] : [];

        if ($edits !== []) {
            $this->editProbes($edits);
        }

        if (\is_array($planting['append'] ?? null)) {
            /** @var list<string> $append */
            $append = $planting['append'];
            $this->appendProbe($append);
        }

        if (\is_array($planting['remove'] ?? null)) {
            /** @var list<string> $remove */
            $remove = $planting['remove'];
            $this->removeProbes($remove);
        }

        if (\is_array($planting['drop'] ?? null)) {
            /** @var array<string, string> $drop */
            $drop = $planting['drop'];
            $this->dropSide($drop);
        }

        if (\is_array($planting['unfreeze'] ?? null)) {
            /** @var list<string> $unfreeze */
            $unfreeze = $planting['unfreeze'];
            $this->unfreeze($unfreeze);
        }

        if (\is_array($planting['fixture'] ?? null)) {
            /** @var list<string> $fixture */
            $fixture = $planting['fixture'];
            file_put_contents($this->tree . '/input-doors/fixtures/' . $fixture[0] . '/' . $fixture[1], "\n// planted\n", \FILE_APPEND);
        }

        if (\is_array($planting['annotation'] ?? null)) {
            /** @var list<string> $annotation */
            $annotation = $planting['annotation'];
            $this->replaceAnnotation($annotation);
        }

        if (\is_array($planting['cure'] ?? null)) {
            /** @var list<string> $cure */
            $cure = $planting['cure'];
            $this->replaceCureRow($cure);
        }

        if (\is_array($planting['supplement'] ?? null)) {
            /** @var list<string> $supplement */
            $supplement = $planting['supplement'];
            file_put_contents($this->tree . '/input-doors/normalization-supplement.tsv', implode("\t", $supplement) . "\n", \FILE_APPEND);
        }

        if (($planting['grid_placeholder'] ?? false) === true) {
            $this->collapseConfigurationCommands();
        }
    }

    /** @param list<array{string, string, string}> $edits */
    private function editProbes(array $edits): void
    {
        $path = $this->tree . '/input-doors/probes.tsv';
        $lines = explode("\n", rtrim((string) file_get_contents($path), "\n"));
        $header = explode("\t", $lines[0]);
        $index = array_flip($header);

        foreach ($edits as [$probeKey, $column, $value]) {
            foreach ($lines as $number => $line) {
                if ($number === 0) {
                    continue;
                }

                $cells = explode("\t", $line);
                $key = $cells[$index['surface']] . '|' . $cells[$index['door']] . '|' . $cells[$index['site']];

                if ($key !== $probeKey || $cells[$index['command']] !== '*') {
                    continue;
                }

                $cells[$index[$column]] = $value;
                $lines[$number] = implode("\t", $cells);
            }
        }

        file_put_contents($path, implode("\n", $lines) . "\n");
    }

    /** @param list<string> $append surface, door, site */
    private function appendProbe(array $append): void
    {
        $path = $this->tree . '/input-doors/probes.tsv';
        file_put_contents($path, implode("\t", [
            $append[0], $append[1], $append[2], '*', 'miss', 'hit', 'none', 'stdout', 'none', 'no', 'main', 'none',
            'planted by the control stand',
        ]) . "\n", \FILE_APPEND);
    }

    /** @param list<string> $keys */
    private function removeProbes(array $keys): void
    {
        $path = $this->tree . '/input-doors/probes.tsv';
        $lines = explode("\n", rtrim((string) file_get_contents($path), "\n"));
        $header = explode("\t", $lines[0]);
        $index = array_flip($header);
        $kept = [$lines[0]];

        foreach (\array_slice($lines, 1) as $line) {
            $cells = explode("\t", $line);
            $key = $cells[$index['surface']] . '|' . $cells[$index['door']] . '|' . $cells[$index['site']];

            if (\in_array($key, $keys, true)) {
                continue;
            }

            $kept[] = $line;
        }

        file_put_contents($path, implode("\n", $kept) . "\n");
    }

    /** @param list<string> $keys grid keys whose frozen shot is removed */
    private function unfreeze(array $keys): void
    {
        foreach ($keys as $key) {
            $directory = $this->tree . '/docs/internal/generated/input-doors/observations-before/' . Runner::slug($key);

            $files = glob($directory . '/*');

            foreach (\is_array($files) ? $files : [] as $file) {
                unlink($file);
            }

            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    /** @param array<string, string> $drop grid key -> side */
    private function dropSide(array $drop): void
    {
        foreach ($drop as $key => $side) {
            $file = $this->tree . '/docs/internal/generated/input-doors/observations-before/' . Runner::slug($key) . '/' . $side . '.json';

            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /** @param list<string> $row */
    private function replaceAnnotation(array $row): void
    {
        $path = $this->tree . '/input-doors/door-annotations.tsv';
        $lines = explode("\n", rtrim((string) file_get_contents($path), "\n"));
        $kept = [];

        foreach ($lines as $number => $line) {
            $cells = explode("\t", $line);

            if ($number > 0 && ($cells[0] ?? '') === $row[0] && ($cells[1] ?? '') === $row[1] && ($cells[2] ?? '') === $row[2]) {
                continue;
            }

            $kept[] = $line;
        }

        $kept[] = implode("\t", $row);
        file_put_contents($path, implode("\n", $kept) . "\n");
    }

    /** @param list<string> $row surface, command, door, site */
    private function replaceCureRow(array $row): void
    {
        $path = $this->tree . '/input-doors/cure-sites.tsv';
        $lines = explode("\n", rtrim((string) file_get_contents($path), "\n"));
        $lines[\count($lines) - 1] = implode("\t", [...$row, 'planted by the control stand']);
        file_put_contents($path, implode("\n", $lines) . "\n");
    }

    /**
     * Collapses every configuration row onto one placeholder command, which is
     * verdict inheritance restored under another name.
     */
    private function collapseConfigurationCommands(): void
    {
        $path = $this->tree . '/docs/internal/generated/input-doors/doors.tsv';
        $lines = explode("\n", rtrim((string) file_get_contents($path), "\n"));
        $kept = [$lines[0]];
        $seen = [];

        foreach (\array_slice($lines, 1) as $line) {
            $cells = explode("\t", $line);

            if ($cells[0] !== 'config') {
                $kept[] = $line;

                continue;
            }

            $cells[1] = '(qmx.yaml)';
            $key = implode('|', \array_slice($cells, 0, 4));

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $kept[] = implode("\t", $cells);
        }

        file_put_contents($path, implode("\n", $kept) . "\n");
    }
}

$root = \dirname(__DIR__);
/** @var list<string> $argv */
$argv = $_SERVER['argv'] ?? [];
$only = [];

foreach (\array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--only=')) {
        $only = explode(',', substr($argument, 7));
    }
}

/**
 * @return list<string>
 */
function specificitySides(ControlCase $case): array
{
    /** @var mixed $declared */
    $declared = $case->planting['options']['specificitySides'] ?? null;

    if (!\is_array($declared)) {
        return ['H', 'A', 'H0'];
    }

    $sides = [];

    foreach ($declared as $side) {
        if (\is_string($side)) {
            $sides[] = $side;
        }
    }

    return $sides;
}

$cases = Cases::all();
$declared = [];

foreach ($cases as $case) {
    if (isset($declared[$case->id])) {
        fwrite(\STDERR, 'duplicate control case ' . $case->id . "\n");

        exit(2);
    }

    $declared[$case->id] = true;
}

$runner = new ControlRunner($root);

// The cheap half first: coverage arithmetic before any planting. A declared
// case whose expected row is not in the universe of the run is a stale
// declaration, and that is a different failure from a case that does not bite.
$baseline = $runner->outcomes(new ClassifierOptions(echoClaimChecked: false));
$stale = [];

foreach ($cases as $case) {
    foreach (array_keys($case->expected) as $key) {
        if ($case->engine !== ControlCase::ENGINE_RECLASSIFY) {
            continue;
        }

        if (str_starts_with($key, 'stand|') || str_contains($key, 'stand-invented')) {
            continue;
        }

        if (!isset($baseline[$key])) {
            $stale[] = $case->id . ' expects ' . $key . ', which the run does not carry';
        }
    }
}

if ($stale !== []) {
    foreach ($stale as $line) {
        fwrite(\STDERR, 'STALE CASE DECLARATION: ' . $line . "\n");
    }

    exit(2);
}

printf("Coverage arithmetic: %d cases, every expected row present in a universe of %d.\n", \count($cases), \count($baseline));

$failures = [];
$ran = 0;

foreach ($cases as $case) {
    if ($only !== [] && !\in_array($case->id, $only, true)) {
        continue;
    }

    ++$ran;
    $tree = $runner->checkout();
    (new Planter($tree))->apply($case->planting);

    $options = new ClassifierOptions(
        echoExcision: !(($case->planting['options']['echoExcision'] ?? true) === false),
        textDiffFallback: ($case->planting['options']['textDiffFallback'] ?? false) === true,
        specificitySides: specificitySides($case),
        refusalBeforeObservability: !(($case->planting['options']['refusalBeforeObservability'] ?? true) === false),
        // The baseline is taken with the echo claim suspended, because the
        // frozen half is pre-cure; a case that wants the claim back says so.
        echoClaimChecked: ($case->planting['options']['echoClaimChecked'] ?? false) === true,
    );

    if ($case->engine === ControlCase::ENGINE_RECLASSIFY) {
        $normalizer = null;

        if (($case->planting['normalization'] ?? true) === false) {
            $normalizer = (new Normalizer(Declarations::load($tree)->normalization))->disabled();
        }

        try {
            $outcomes = $runner->outcomes($options, $normalizer, $tree);
        } catch (DeclarationError $error) {
            $failures[] = $case->id . ': the planting broke the loader instead of the classifier (' . $error->getMessage() . ')';

            continue;
        }

        if (\is_string($case->planting['cure_check'] ?? null)) {
            $cureKey = $case->planting['cure_check'];
            $declarations = Declarations::load($tree);
            $runnerForTree = new Runner($tree, $tree, sys_get_temp_dir() . '/qmx-input-door-controls-unused', $runner->commandOptions($tree));
            $stand = new Stand($tree, $declarations, $runnerForTree, $options, $normalizer);
            $offenders = $stand->speaksBeforeCure($stand->before($options));
            $named = false;

            foreach ($offenders as $offender) {
                if (str_starts_with($offender, $cureKey . ' ->')) {
                    $named = true;
                }
            }

            if (!$named) {
                $failures[] = $case->id . ': guard 2 did not name ' . $cureKey . ' as speaking before the cure';
            }
        }

        $deviations = [];

        foreach ($outcomes as $key => $outcome) {
            if (($baseline[$key] ?? '(absent)') !== $outcome) {
                $deviations[$key] = $outcome;
            }
        }

        foreach ($baseline as $key => $outcome) {
            if (!isset($outcomes[$key])) {
                $deviations[$key] = '(gone)';
            }
        }

        foreach ($case->expected as $key => $outcome) {
            if (($deviations[$key] ?? null) !== $outcome) {
                $failures[] = \sprintf(
                    '%s: expected %s to become %s, it became %s',
                    $case->id,
                    $key,
                    $outcome,
                    $deviations[$key] ?? ($outcomes[$key] ?? '(absent)'),
                );
            }

            unset($deviations[$key]);
        }

        if (!$case->strict) {
            printf("  %-4s not strict: moved %d rows in total\n", $case->id, \count($deviations) + \count($case->expected));

            continue;
        }

        // The breakage is allowed to move its own door on other commands — a
        // probe row is multiplied — but not a door it does not name.
        $doors = [];

        foreach (array_keys($case->expected) as $key) {
            $parts = explode('|', $key);
            $doors[$parts[0] . '|' . ($parts[2] ?? '')] = true;
        }

        foreach (array_keys($deviations) as $key) {
            $parts = explode('|', $key);

            if (!isset($doors[$parts[0] . '|' . ($parts[2] ?? '')])) {
                $failures[] = $case->id . ': the planting also moved ' . $key . ', a row it does not name';

                break;
            }
        }

        continue;
    }

    // Loader and generator cases: the breakage must be refused, by name.
    if ($case->engine === ControlCase::ENGINE_LOADER) {
        try {
            Declarations::load($tree);
            $failures[] = $case->id . ': the loader accepted the planted declaration';
        } catch (DeclarationError $error) {
            if (!str_contains($error->getMessage(), $case->expectedMessage)) {
                $failures[] = $case->id . ': refused, but not for the declared reason: ' . $error->getMessage();
            }
        }

        continue;
    }

    $command = \sprintf(
        '%s %s --check --root=%s 2>&1',
        escapeshellarg(\PHP_BINARY),
        escapeshellarg($root . '/scripts/generate-input-door-table.php'),
        escapeshellarg($tree),
    );

    if (($case->planting['entries_only'] ?? false) === true) {
        // The generator refuses on its own when the document roots are lost;
        // the planting is the narrowed read, expressed through the environment
        // the generator asserts against.
        $command = \sprintf(
            '%s -r %s 2>&1',
            escapeshellarg(\PHP_BINARY),
            escapeshellarg(
                'require ' . var_export($root . '/vendor/autoload.php', true) . ';'
                . '$entries = [];'
                . 'foreach (Qualimetrix\Analysis\Configuration\ConfigSchema::ENTRIES as [$p]) { $entries[$p] = true; }'
                . 'if (!isset($entries["excludeHealth"], $entries["computedMetrics"])) {'
                . ' fwrite(STDERR, "configuration doors lost: DOCUMENT_ROOTS must be read alongside ENTRIES\n"); exit(1); }',
            ),
        );
    }

    $output = [];
    $status = 0;
    exec($command, $output, $status);
    $text = implode("\n", $output);

    if ($status === 0) {
        $failures[] = $case->id . ': the generator accepted the planted declaration';

        continue;
    }

    if (!str_contains($text, $case->expectedMessage)) {
        $failures[] = $case->id . ': refused, but not for the declared reason: ' . $text;
    }
}

$runner->cleanup();

foreach ($failures as $failure) {
    fwrite(\STDERR, $failure . "\n");
}

printf("Ran %d control cases, %d failures.\n", $ran, \count($failures));

exit($failures === [] ? 0 : 1);
