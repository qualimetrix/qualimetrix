<?php

declare(strict_types=1);

/**
 * What the grid was measured from, hashed, so that staleness can be detected
 * without re-measuring.
 *
 * The expensive run costs about 200 s; `check:artifacts` cannot carry that.
 * The cheap check therefore answers a narrower question — "is this grid still
 * the grid these inputs produce?" — and the honest way to answer it is to
 * record the inputs. The stand's own code is one of them: editing the
 * classifier makes the grid stale, and a stamp that ignored the classifier
 * would call a grid fresh that today's rule would no longer produce.
 *
 * What the stamp does NOT cover is the product: `src/` moves under the stand
 * every day, and hashing it would redden the aggregate on every commit while
 * saying nothing about whether the verdicts changed. Only the expensive run
 * answers that, and it lives outside the aggregate for exactly that reason.
 * The consequence is named rather than implied: a green cheap check means the
 * grid matches its declarations, never that it matches the product.
 */

namespace Qualimetrix\PromiseEffect;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class Stamp
{
    public const string PATH = 'docs/internal/generated/promise-effect/inputs.stamp.tsv';

    private const array FILES = [
        'docs/internal/plans/promise-effect/measurement/promise-ledger.tsv',
        'promise-effect/forms.tsv',
        'promise-effect/axis-d-envelopes.tsv',
        'promise-effect/axis-d-observables.tsv',
        'promise-effect/cli-root-flags.tsv',
        'promise-effect/axis-a-hits.tsv',
        'promise-effect/witness-envelopes.tsv',
        'promise-effect/floor.tsv',
        // Read at judgement and therefore verdict-producing: a limit declared
        // or withdrawn changes what the grid says, so a grid measured before
        // the change is stale.
        'promise-effect/observability-limits.tsv',
        // Declares the axis list (02-stand.md S11): an undeclared change here
        // moves what an unqualified run measures without touching any file
        // this list already named, which is exactly the drift this stamp
        // exists to catch.
        'promise-effect/run-declaration.tsv',
        // The two magnitudes axis C writes with, and stage 01's key-pair
        // enumeration, which axis C reads for a triple's graduated partner and
        // axis E reads for its whole population. Both produce cells.
        'promise-effect/composition-magnitudes.tsv',
        // The counter-default magnitudes axes B and E fall back to: a row
        // added or withdrawn changes which value a probe writes, and therefore
        // whether the row measures anything at all.
        'promise-effect/effect-magnitudes.tsv',
        'docs/internal/plans/promise-effect/measurement/key-pairs.tsv',
        'scripts/promise-effect.php',
        'scripts/promise-effect/RunDeclaration.php',
        // Only the files that PRODUCE the grid. `Population`, `FifthSet` and
        // `Stamp` itself are read by the cheap check and cannot move a
        // verdict; hashing the whole directory would oblige a 200 s
        // regeneration every time the fifth-set printer changes a word.
        'scripts/promise-effect/Ledger.php',
        'scripts/promise-effect/Declarations.php',
        'scripts/promise-effect/InProcess.php',
        'scripts/promise-effect/ProcessProbe.php',
        'scripts/promise-effect/Classifier.php',
        'scripts/promise-effect/Limits.php',
        'scripts/promise-effect/Composition.php',
        'scripts/promise-effect/Neighbourhood.php',
        'scripts/promise-effect/Stand.php',
    ];

    private const array DIRECTORIES = [
        'promise-effect/fixtures',
    ];

    public function __construct(private readonly string $root) {}

    public function render(): string
    {
        $lines = ["input\tsha256"];

        foreach ($this->inputs() as $relative => $hash) {
            $lines[] = $relative . "\t" . $hash;
        }

        return implode("\n", $lines) . "\n";
    }

    /** @return list<string> what changed since the stamp was written */
    public function drift(): array
    {
        $absolute = $this->root . '/' . self::PATH;
        $stored = is_file($absolute) ? file($absolute, \FILE_IGNORE_NEW_LINES) : false;

        if ($stored === false) {
            return ['the input stamp has never been written: run composer promise-effect'];
        }

        $recorded = [];

        foreach (\array_slice($stored, 1) as $line) {
            if ($line === '') {
                continue;
            }

            [$relative, $hash] = array_pad(explode("\t", $line, 2), 2, '');
            $recorded[$relative] = $hash;
        }

        $drift = [];
        $current = $this->inputs();

        foreach ($current as $relative => $hash) {
            if (!isset($recorded[$relative])) {
                $drift[] = $relative . ' is new since the grid was measured';

                continue;
            }

            if ($recorded[$relative] !== $hash) {
                $drift[] = $relative . ' changed since the grid was measured';
            }
        }

        foreach (array_keys($recorded) as $relative) {
            if (!isset($current[$relative])) {
                $drift[] = $relative . ' is gone since the grid was measured';
            }
        }

        return $drift;
    }

    /** @return array<string, string> path relative to the root => sha256 of its bytes */
    private function inputs(): array
    {
        $hashes = [];

        foreach (self::FILES as $relative) {
            $absolute = $this->root . '/' . $relative;

            if (is_file($absolute)) {
                $hash = hash_file('sha256', $absolute);

                if ($hash !== false) {
                    $hashes[$relative] = $hash;
                }
            }
        }

        foreach (self::DIRECTORIES as $directory) {
            $absolute = $this->root . '/' . $directory;

            if (!is_dir($absolute)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS),
            ) as $item) {
                if (!$item instanceof SplFileInfo || !$item->isFile()) {
                    continue;
                }

                $hash = hash_file('sha256', $item->getPathname());

                if ($hash === false) {
                    continue;
                }

                $hashes[substr($item->getPathname(), \strlen($this->root) + 1)] = $hash;
            }
        }

        ksort($hashes);

        return $hashes;
    }
}
