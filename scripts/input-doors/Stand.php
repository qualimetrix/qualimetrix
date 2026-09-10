<?php

declare(strict_types=1);

/**
 * The stand: join the grid with the probes, take the observations, and let the
 * classifier speak.
 *
 * What is frozen is the *input* of the verdict, never the verdict. The
 * classifier is edited on execution and will be edited again; a frozen verdict
 * would mean the "before" half of the pair was computed by an older rule than
 * the "after" half, and the difference would stop being a fact about the
 * product. Raw observations keep normalization, echo excision and
 * classification all recomputable, on both halves, by one rule.
 */

namespace Qualimetrix\InputDoors;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class StandRow
{
    public function __construct(
        public readonly GridRow $row,
        public readonly Verdict $verdict,
        public readonly string $observable,
        public readonly string $signalSource,
        public readonly bool $hasEmptyHit = false,
    ) {}
}

final class Stand
{
    public const string OBSERVATIONS_DIR = 'docs/internal/generated/input-doors/observations-before';
    public const string VERDICTS_PATH = 'docs/internal/generated/input-doors/verdicts.tsv';

    private readonly Normalizer $normalizer;

    public function __construct(
        private readonly string $repositoryRoot,
        private readonly Declarations $declarations,
        private readonly Runner $runner,
        private readonly ClassifierOptions $options = new ClassifierOptions(),
        ?Normalizer $normalizer = null,
    ) {
        $this->normalizer = $normalizer ?? new Normalizer($declarations->normalization);
    }

    public function normalizer(): Normalizer
    {
        return $this->normalizer;
    }

    /**
     * @return list<StandRow>
     */
    public function run(): array
    {
        $rows = [];
        $classifier = new Classifier($this->normalizer, $this->options);
        $stale = $this->declarations->staleProbes();

        foreach ($stale as $key) {
            $rows[] = new StandRow(
                new GridRow('stand', '(none)', $key, '(none)', 'probe', true, '', ''),
                new Verdict('STALE PROBE', 'stand', 'the probe names a row the grid does not carry'),
                '(none)',
                'none',
            );
        }

        foreach ($this->declarations->grid as $row) {
            if (!$row->referential) {
                $rows[] = new StandRow($row, new Verdict('NOT REFERENTIAL', 'annotation', $row->nonReferentialReason), '(none)', 'none');

                continue;
            }

            $probe = $this->declarations->probeFor($row);

            if ($probe === null) {
                $rows[] = new StandRow($row, new Verdict('NOT PROBED', 'stand', 'no probe declaration answers this row'), '(none)', 'none');

                continue;
            }

            $observable = $this->observableFor($row, $probe);

            try {
                $sides = $this->runner->observe($row, $probe, $this->declarations->commands, $observable);
                $verdict = $classifier->classify($sides, $probe, $observable);
            } catch (DeclarationError $error) {
                $verdict = new Verdict('INCOMPLETE TRIPLE', 'stand', $error->getMessage());
            }

            $rows[] = new StandRow($row, $verdict, $observable, $probe->signalSource, $probe->hasHitEmpty());
        }

        return $rows;
    }

    /**
     * Guard 2. Recomputes the verdict of every cure row from the frozen
     * pre-cure observations with today's classifier. A signal that already
     * fired before the cure is not a signal: either the declaration is wrong,
     * or the row does not belong in the cure list.
     *
     * @return list<StandRow>
     */
    public function before(?ClassifierOptions $options = null): array
    {
        $rows = [];
        $classifier = new Classifier($this->normalizer, $options ?? $this->options);
        $directory = $this->repositoryRoot . '/' . self::OBSERVATIONS_DIR;

        foreach ($this->declarations->grid as $row) {
            if (!$row->referential) {
                continue;
            }

            $probe = $this->declarations->probeFor($row);

            if ($probe === null) {
                continue;
            }

            $observable = $this->observableFor($row, $probe);
            $slug = Runner::slug($row->key());
            $path = $directory . '/' . $slug;

            if (!is_dir($path)) {
                $rows[] = new StandRow($row, new Verdict('NO BEFORE', 'stand', 'no frozen observation for this row'), $observable, $probe->signalSource);

                continue;
            }

            $stored = json_decode((string) file_get_contents($path . '/shot.json'), true);
            $fingerprint = \is_array($stored) && \is_string($stored['fingerprint'] ?? null) ? $stored['fingerprint'] : '';

            if ($fingerprint !== $this->fingerprint($row, $probe, $observable)) {
                $rows[] = new StandRow(
                    $row,
                    new Verdict('BEFORE UNDER OTHER DECLARATION', 'stand', 'the frozen observation answers a different declaration or a different fixture'),
                    $observable,
                    $probe->signalSource,
                );

                continue;
            }

            $sides = [];

            foreach (['M', 'H', 'A', 'H0'] as $side) {
                $file = $path . '/' . $side . '.json';

                if (!is_file($file)) {
                    continue;
                }

                $decoded = json_decode((string) file_get_contents($file), true);

                if (!\is_array($decoded)) {
                    continue;
                }

                $sides[$side] = new Observation(
                    (int) ($decoded['exit'] ?? 0),
                    (string) ($decoded['stdout'] ?? ''),
                    (string) ($decoded['stderr'] ?? ''),
                    (bool) ($decoded['file'] ?? false),
                    (bool) ($decoded['dir'] ?? false),
                );
            }

            $rows[] = new StandRow($row, $classifier->classify($sides, $probe, $observable), $observable, $probe->signalSource, $probe->hasHitEmpty());
        }

        return $rows;
    }

    /**
     * @param list<StandRow> $before
     *
     * @return list<string> rows of the cure list whose pre-cure verdict already spoke
     */
    public function speaksBeforeCure(array $before): array
    {
        $cure = array_flip($this->declarations->cureSites);
        $offenders = [];

        foreach ($before as $row) {
            if (!isset($cure[$row->row->key()])) {
                continue;
            }

            if (\in_array($row->verdict->outcome, [Verdict::SPEAKS, Verdict::REFUSES], true)) {
                $offenders[] = $row->row->key() . ' -> ' . $row->verdict->outcome;
            }
        }

        return $offenders;
    }

    public function freeze(string $directory, bool $refreeze, string $reason): int
    {
        if (is_dir($directory) && !$refreeze) {
            fwrite(\STDERR, "the frozen observations already exist; --refreeze-before --reason=<...> overwrites them\n");

            return 1;
        }

        if ($refreeze && trim($reason) === '') {
            fwrite(\STDERR, "--refreeze-before needs --reason\n");

            return 1;
        }

        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            fwrite(\STDERR, 'cannot create ' . $directory . "\n");

            return 1;
        }

        $frozen = 0;

        foreach ($this->declarations->grid as $row) {
            if (!$row->referential) {
                continue;
            }

            $probe = $this->declarations->probeFor($row);

            if ($probe === null) {
                continue;
            }

            $observable = $this->observableFor($row, $probe);
            $sides = $this->runner->observe($row, $probe, $this->declarations->commands, $observable);
            $path = $directory . '/' . Runner::slug($row->key());

            if (!is_dir($path) && !mkdir($path, 0o775, true) && !is_dir($path)) {
                fwrite(\STDERR, 'cannot create ' . $path . "\n");

                return 1;
            }

            foreach ($sides as $side => $observation) {
                file_put_contents($path . '/' . $side . '.json', json_encode([
                    'exit' => $observation->exit,
                    'stdout' => $observation->stdout,
                    'stderr' => $observation->stderr,
                    'file' => $observation->fileExists,
                    'dir' => $observation->dirExists,
                ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
            }

            file_put_contents($path . '/shot.json', json_encode([
                'key' => $row->key(),
                'fingerprint' => $this->fingerprint($row, $probe, $observable),
                'reason' => $reason,
            ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
            ++$frozen;
        }

        printf("Froze %d rows into %s.\n", $frozen, $directory);

        return 0;
    }

    /**
     * Guard 4. Repeats the M side of every text observable and demands the
     * same text after normalization. A surface normalized incompletely reads
     * as observable for a reason that has nothing to do with the door.
     *
     * @return list<string>
     */
    public function unstableSurfaces(): array
    {
        $classifier = new Classifier($this->normalizer, $this->options);
        $unstable = [];
        $seen = [];

        foreach ($this->declarations->grid as $row) {
            if (!$row->referential) {
                continue;
            }

            $probe = $this->declarations->probeFor($row);

            if ($probe === null) {
                continue;
            }

            $observable = $this->observableFor($row, $probe);

            if (Classifier::observableKind($observable) !== 'text') {
                continue;
            }

            $profile = $this->declarations->commands[$row->command] ?? null;

            if ($profile === null || isset($seen[$observable . '|' . $probe->key()])) {
                continue;
            }

            $seen[$observable . '|' . $probe->key()] = true;
            $first = $this->runner->observe($row, $probe, $this->declarations->commands, $observable)['M'];
            $second = $this->runner->repeatMiss($row, $probe, $profile, $observable);
            $normalizer = $this->normalizer;
            $normalize = static fn(Observation $o): string => $classifier->excise(
                $normalizer->normalize($observable, $o->stdout) . "\n" . $o->stderr,
                $probe->miss,
                $probe,
            );

            if ($normalize($first) !== $normalize($second)) {
                $unstable[] = 'UNSTABLE SURFACE: ' . $observable . ' under ' . $row->key();
            }
        }

        return $unstable;
    }

    public function observableFor(GridRow $row, Probe $probe): string
    {
        if ($probe->observable !== '') {
            return $probe->observable;
        }

        return $this->declarations->commands[$row->command]->observable ?? 'stdout';
    }

    /**
     * Guard 6. The fields that decide *what was shot*, plus the content of the
     * fixture. A guard keyed on the row alone survives an edit to `observable`
     * or `miss` and recomputes "before" from someone else's shot — the very
     * disease the freeze exists to cure.
     */
    public function fingerprint(GridRow $row, Probe $probe, string $observable): string
    {
        $profile = $this->declarations->commands[$row->command] ?? null;
        $fixture = $probe->fixture !== '' ? $probe->fixture : ($profile->fixture ?? '');
        $positional = '';

        foreach ($profile->positional ?? [] as [$name, $default]) {
            $positional .= $name . '=' . $default . ';';
        }

        return hash('sha256', implode("\0", [
            $row->key(),
            $row->shadowedBy,
            $observable,
            $probe->miss,
            $probe->hit,
            $probe->hitEmpty,
            $probe->command,
            $fixture,
            $this->fixtureHash($fixture),
            Runner::prepareSteps($this->repositoryRoot . '/input-doors/fixtures/' . $fixture),
            $positional,
        ]));
    }

    private function fixtureHash(string $fixture): string
    {
        $directory = $this->repositoryRoot . '/input-doors/fixtures/' . $fixture;

        if (!is_dir($directory)) {
            return 'missing';
        }

        $entries = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || !$item->isFile()) {
                continue;
            }

            $relative = substr($item->getPathname(), \strlen($directory));

            // Run residue, not fixture content: the product writes its AST
            // cache into the working directory, and a cache left behind by an
            // ad-hoc run would otherwise invalidate every frozen shot.
            if (str_contains($relative, '/.qmx-cache/')) {
                continue;
            }

            $entries[] = $relative . ':' . hash_file('sha256', $item->getPathname());
        }

        sort($entries, \SORT_STRING);

        return hash('sha256', implode("\n", $entries));
    }
}
