<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * The third kind of declaration: what a step changed that is not a rename.
 *
 * A map says "this name became that name"; normalization says "this field is not
 * compared". Neither can state that splitting one rule turns one aggregate group
 * into three, or adds rows to the rule inventory. That is a structural change to
 * a surface, and it is declared as the exact diff of that surface — index row
 * plus a file holding the whole unified diff, produced by
 * `--derive-declared-delta` rather than written by hand.
 *
 * Four properties keep this from becoming a rubber stamp, and they live in Gate
 * because three of them need the measured diff: the computed diff must equal the
 * declared one byte for byte (`delta-mismatch`), a declaration on a surface that
 * turned out to match is stale (`delta-stale`), a diff past the size limit is
 * refused so the pressure stays on declaring another map row rather than
 * dropping in a blob (`delta-too-large`), and a diff line may not change a field
 * the equivalence tuple compares unless a declared split already explains that
 * record (`delta-overreach`).
 *
 * The `reason` column is the one thing a run cannot produce, so a re-derivation
 * carries an existing reason over — and only while the diff it was written
 * against is unchanged. A surface that is new, or whose diff moved, gets `?`,
 * and loading refuses `?`: an undeclared reason is not a declaration, and a
 * sentence inherited by a diff it was never written for is worse than none.
 */
final class DeclaredDelta
{
    public const COLUMNS = ['surface', 'file', 'reason'];

    public const INDEX = 'declared-delta.tsv';

    public const DIRECTORY = 'declared-delta';

    /** A diff bigger than this is a blob, not a declaration. */
    public const MAX_CHANGED_LINES = 200;

    /** @var array<string, array{file: string, reason: string, diff: string}> */
    private array $entries;

    /** @var array<string, true> */
    private array $consulted = [];

    /** @param array<string, array{file: string, reason: string, diff: string}> $entries */
    private function __construct(private readonly string $root, array $entries)
    {
        $this->entries = $entries;
    }

    /** @param string $root the `finding-gate` directory of the candidate tree */
    public static function load(string $root): self
    {
        $index = $root . '/' . self::INDEX;

        if (!is_file($index)) {
            return new self($root, []);
        }

        $entries = [];

        foreach (Tsv::rows($index, self::COLUMNS) as $row) {
            $surface = $row['surface'];

            if (isset($entries[$surface])) {
                throw new GateError(\sprintf('%s declares surface "%s" twice.', self::INDEX, $surface));
            }

            if ($row['reason'] === '' || $row['reason'] === '?') {
                throw new GateError(\sprintf(
                    '%s declares "%s" with no reason. A derived row carries "?" until someone says why the surface'
                    . ' changed structurally; that sentence is the declaration.',
                    self::INDEX,
                    $surface,
                ));
            }

            $path = $root . '/' . $row['file'];

            if (!is_file($path)) {
                throw new GateError(\sprintf('%s names "%s" for surface "%s", which does not exist.', self::INDEX, $row['file'], $surface));
            }

            $diff = Fs::read($path);

            if (trim($diff) === '') {
                throw new GateError(\sprintf('%s is empty, so it declares no delta for surface "%s".', $row['file'], $surface));
            }

            $entries[$surface] = ['file' => $row['file'], 'reason' => $row['reason'], 'diff' => $diff];
        }

        return new self($root, $entries);
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    public function count(): int
    {
        return \count($this->entries);
    }

    public function totalBytes(): int
    {
        $bytes = 0;

        foreach ($this->entries as $entry) {
            $bytes += \strlen($entry['diff']);
        }

        return $bytes;
    }

    /** @return list<string> */
    public function surfaces(): array
    {
        return array_keys($this->entries);
    }

    /** The declared diff for a surface that turned out to differ, or null. */
    public function claim(string $surfaceKey): ?string
    {
        $this->consulted[$surfaceKey] = true;

        return $this->entries[$surfaceKey]['diff'] ?? null;
    }

    public function fileOf(string $surfaceKey): string
    {
        return $this->entries[$surfaceKey]['file'] ?? self::INDEX;
    }

    /**
     * Declared surfaces the run found equal, i.e. declarations of a change that
     * did not happen — the same lie as a stale map row or a stale normalization
     * rule, and it fails the same way.
     *
     * @return list<string>
     */
    public function staleSurfaces(): array
    {
        return array_values(array_diff(array_keys($this->entries), array_keys($this->consulted)));
    }

    /**
     * Writes the index and one file per surface, preserving the reasons already
     * recorded against a surface that still differs.
     *
     * @param array<string, string> $diffs surface key => unified diff
     *
     * @return list<string> what was written, for the run to print
     */
    public function rewrite(array $diffs): array
    {
        $directory = $this->root . '/' . self::DIRECTORY;
        Fs::removeRecursively($directory);
        ksort($diffs);
        $rows = [];
        $written = [];

        foreach ($diffs as $surface => $diff) {
            $file = self::DIRECTORY . '/' . self::slug($surface) . '.diff';
            Fs::write($this->root . '/' . $file, $diff);
            $rows[] = [$surface, $file, $this->reasonFor($surface, $diff)];
            $written[] = $file;
        }

        Fs::write($this->root . '/' . self::INDEX, Tsv::render(self::COLUMNS, $rows));
        $written[] = self::INDEX;

        return $written;
    }

    /**
     * The sentence already written against this surface — but only while the
     * diff it explains is the same diff.
     *
     * Carry-over used to be keyed on the surface alone, so a later step that
     * changed `case:design|format:json` structurally for an entirely different
     * reason inherited this step's sentence and nothing noticed: loading refuses
     * `?`, not a sentence that has stopped being true. The reason is the one
     * thing a run cannot measure, which is exactly why it must not be allowed to
     * outlive the measurement it was written for.
     */
    private function reasonFor(string $surface, string $diff): string
    {
        $existing = $this->entries[$surface] ?? null;

        if ($existing === null || $existing['diff'] !== $diff) {
            return '?';
        }

        return $existing['reason'];
    }

    private static function slug(string $surfaceKey): string
    {
        $slug = preg_replace('~[^A-Za-z0-9]+~', '-', $surfaceKey);

        return trim($slug ?? $surfaceKey, '-');
    }
}
