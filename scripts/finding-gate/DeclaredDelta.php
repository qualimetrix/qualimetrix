<?php

declare(strict_types=1);

namespace QmxFindingGate;

/** Intent-scoped structural differences; reasons belong to intentions and survive measurement. */
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
                    '%s declares "%s" with no reason. A structural intention requires its explanation before measurement.',
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

        foreach (array_keys($entries) as $surface) {
            if (str_contains($surface, '|') && isset($entries[Surfaces::surfaceClass($surface)])) {
                throw new GateError('A structural intention overlaps a full surface and its surface class: ' . $surface);
            }
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
        $this->consulted[$this->intentOf($surfaceKey)] = true;

        return $this->entries[$this->intentOf($surfaceKey)]['diff'] ?? null;
    }

    public function fileOf(string $surfaceKey): string
    {
        return $this->entries[$this->intentOf($surfaceKey)]['file'] ?? self::INDEX;
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
     * Writes measured intentions, preserving reasons and prior contents of
     * intentions this partial derivation could not measure.
     *
     * @param array<string, string> $diffs surface key => unified diff
     *
     * @return list<string> what was written, for the run to print
     */
    public function rewrite(array $diffs): array
    {
        $measured = [];
        foreach ($diffs as $surface => $diff) {
            $intent = $this->intentOf($surface);
            if (!isset($this->entries[$intent])) {
                throw new GateError('A derivation cannot write an unannounced surface: ' . $surface);
            }
            if (isset($measured[$intent]) && $measured[$intent] !== $diff) {
                throw new GateError('Cases of one surface class measured different structural differences.');
            }
            $measured[$intent] = $diff;
        }
        foreach ($this->entries as $surface => $entry) {
            if (!isset($measured[$surface])) {
                $measured[$surface] = $entry['diff'];
            }
        }
        $diffs = $measured;
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

    public function intentOf(string $surfaceKey): string
    {
        if (isset($this->entries[$surfaceKey])) {
            return $surfaceKey;
        }
        $surface = str_contains($surfaceKey, '|') ? Surfaces::surfaceClass($surfaceKey) : $surfaceKey;
        return isset($this->entries[$surface]) ? $surface : $surfaceKey;
    }

    private function reasonFor(string $surface, string $diff): string
    {
        return $this->entries[$surface]['reason'];
    }

    private static function slug(string $surfaceKey): string
    {
        $slug = preg_replace('~[^A-Za-z0-9]+~', '-', $surfaceKey);

        return trim($slug ?? $surfaceKey, '-');
    }
}
