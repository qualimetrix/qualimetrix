<?php

declare(strict_types=1);

namespace QmxFindingGate;

/** Exact case and surface intentions for residuals no semantic form expresses. */
final class DeclaredExactSurfaces
{
    public const INDEX = 'declared-exact-surfaces.tsv';
    public const DIRECTORY = 'declared-exact-surfaces';
    public const COLUMNS = ['case', 'surface', 'file', 'reason'];

    /** @var array<string,array{case:string,surface:string,file:string,reason:string,diff:string}> */
    private array $entries;

    /** @var array<string,true> */
    private array $claimed = [];

    /** @param array<string,array{case:string,surface:string,file:string,reason:string,diff:string}> $entries */
    private function __construct(private readonly string $root, array $entries)
    {
        $this->entries = $entries;
    }

    public static function load(string $root, DeclaredDelta $ordinary): self
    {
        $path = $root . '/' . self::INDEX;
        if (!is_file($path)) {
            return new self($root, []);
        }
        $entries = [];
        foreach (Tsv::rows($path, self::COLUMNS) as $row) {
            $case = $row['case'];
            $surface = $row['surface'];
            if ($case === '' || $case === '*' || str_contains($case, '|') || $surface === '' || str_contains($surface, '|')) {
                throw new GateError('An exact surface intention requires one concrete case and surface.');
            }
            $key = 'case:' . $case . '|' . $surface;
            if (isset($entries[$key]) || $ordinary->hasSurfaceIntention($key)) {
                throw new GateError('An exact surface intention overlaps another declaration: ' . $key);
            }
            if ($row['reason'] === '' || $row['reason'] === '?') {
                throw new GateError('An exact surface intention has no explanation: ' . $key);
            }
            $file = $row['file'];
            if (!str_starts_with($file, self::DIRECTORY . '/') || str_contains($file, '..') || !is_file($root . '/' . $file)) {
                throw new GateError('An exact surface intention has no measured file: ' . $key);
            }
            $diff = Fs::read($root . '/' . $file);
            if ($diff === '') {
                throw new GateError('An exact surface intention has an empty measurement: ' . $key);
            }
            $entries[$key] = ['case' => $case, 'surface' => $surface, 'file' => $file, 'reason' => $row['reason'], 'diff' => $diff];
        }
        return new self($root, $entries);
    }

    public function has(string $key): bool
    {
        return isset($this->entries[$key]);
    }

    public function count(): int
    {
        return \count($this->entries);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->entries);
    }

    public function declared(string $key): string
    {
        return $this->entries[$key]['diff'] ?? throw new GateError('The exact surface has no declared measurement: ' . $key);
    }

    public function claim(string $key): void
    {
        if (!isset($this->entries[$key])) {
            throw new GateError('The exact surface has no intention: ' . $key);
        }
        $this->claimed[$key] = true;
    }

    /** @return list<string> */
    public function stale(): array
    {
        return array_values(array_diff(array_keys($this->entries), array_keys($this->claimed)));
    }

    /** @param array<string,string> $measured
     * @return list<string>
     */
    public function rewrite(array $measured): array
    {
        $rows = [];
        $written = [];
        ksort($this->entries);
        foreach ($this->entries as $key => $entry) {
            $diff = $measured[$key] ?? $entry['diff'];
            $file = self::DIRECTORY . '/' . md5($key) . '.diff';
            Fs::write($this->root . '/' . $file, $diff);
            $rows[] = [$entry['case'], $entry['surface'], $file, $entry['reason']];
            $written[] = $file;
        }
        Fs::write($this->root . '/' . self::INDEX, Tsv::render(self::COLUMNS, $rows));
        $written[] = self::INDEX;
        return $written;
    }
}
