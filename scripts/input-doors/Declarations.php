<?php

declare(strict_types=1);

/**
 * Reading and refusing the handwritten half of the input-door oracle.
 *
 * Every refusal here is a case of "the declaration expresses only the simple
 * case": a field that may say `none` says it with a reason, or the loader
 * stops. A declaration that silently accepts an incomplete claim is a door to
 * a green report, which is the defect the oracle exists to detect.
 */

namespace Qualimetrix\InputDoors;

use RuntimeException;

/** One row of the generated grid: the denominator. */
final class GridRow
{
    public function __construct(
        public readonly string $surface,
        public readonly string $command,
        public readonly string $door,
        public readonly string $site,
        public readonly string $kind,
        public readonly bool $referential,
        public readonly string $nonReferentialReason,
        public readonly string $shadowedBy,
    ) {}

    public function key(): string
    {
        return $this->surface . '|' . $this->command . '|' . $this->door . '|' . $this->site;
    }

    public function probeKey(): string
    {
        return $this->surface . '|' . $this->door . '|' . $this->site;
    }
}

/** One handwritten probe declaration. Its key carries no command (§7). */
final class Probe
{
    public function __construct(
        public readonly string $surface,
        public readonly string $door,
        public readonly string $site,
        public readonly string $command,
        public readonly string $miss,
        public readonly string $hit,
        public readonly string $hitEmpty,
        public readonly string $observable,
        public readonly string $signal,
        public readonly bool $echoes,
        public readonly string $fixture,
        public readonly string $signalSource,
        public readonly string $reason,
        public readonly int $line,
    ) {}

    public function key(): string
    {
        return $this->surface . '|' . $this->door . '|' . $this->site;
    }

    public function hasHitEmpty(): bool
    {
        return $this->hitEmpty !== 'none';
    }

    public function declaresSignal(): bool
    {
        return $this->signal !== 'none';
    }
}

/** What a command needs before any door can be exercised on it. */
final class CommandProfile
{
    /** @param list<array{string, string}> $positional */
    public function __construct(
        public readonly string $command,
        public readonly string $observable,
        public readonly array $positional,
        public readonly string $fixture,
    ) {}
}

final class DeclarationError extends RuntimeException {}

final class Declarations
{
    private const array PROBE_HEADER = [
        'surface', 'door', 'site', 'command', 'miss', 'hit', 'hit_empty',
        'observable', 'signal', 'echoes', 'fixture', 'signal_source', 'reason',
    ];

    private const array COMMAND_HEADER = ['command', 'observable', 'positional', 'fixture'];

    private const array CURE_HEADER = ['surface', 'command', 'door', 'site', 'place'];

    private const array SUPPLEMENT_HEADER = ['surface', 'locator', 'kind', 'gate_owned', 'reason'];

    /**
     * @param list<GridRow> $grid
     * @param array<string, Probe> $probes keyed `surface|door|site`
     * @param array<string, Probe> $overrides keyed `surface|command|door|site`
     * @param array<string, CommandProfile> $commands
     * @param list<string> $cureSites grid keys
     * @param list<array{string, string, string, string, string}> $normalization
     */
    private function __construct(
        public readonly array $grid,
        public readonly array $probes,
        public readonly array $overrides,
        public readonly array $commands,
        public readonly array $cureSites,
        public readonly array $normalization,
    ) {}

    public static function load(string $root): self
    {
        $grid = self::readGrid($root . '/docs/internal/generated/input-doors/doors.tsv');
        [$probes, $overrides] = self::readProbes($root . '/input-doors/probes.tsv');
        $commands = self::readCommands($root . '/input-doors/command-observables.tsv');
        $cureSites = self::readCureSites($root . '/input-doors/cure-sites.tsv', $grid);
        $normalization = self::readNormalization($root);

        return new self($grid, $probes, $overrides, $commands, $cureSites, $normalization);
    }

    /**
     * The probe that answers a grid row: the per-command override first, then
     * the command-free row multiplied across commands.
     */
    public function probeFor(GridRow $row): ?Probe
    {
        return $this->overrides[$row->key()] ?? $this->probes[$row->probeKey()] ?? null;
    }

    /** @return list<string> probe keys carrying no grid row */
    public function staleProbes(): array
    {
        $live = [];

        foreach ($this->grid as $row) {
            $live[$row->probeKey()] = true;
            $live[$row->key()] = true;
        }

        $stale = [];

        foreach (array_keys($this->probes) as $key) {
            if (!isset($live[$key])) {
                $stale[] = $key;
            }
        }

        foreach (array_keys($this->overrides) as $key) {
            if (!isset($live[$key])) {
                $stale[] = $key;
            }
        }

        sort($stale, \SORT_STRING);

        return $stale;
    }

    /** @return list<GridRow> */
    private static function readGrid(string $path): array
    {
        $rows = [];

        foreach (self::readTsv($path, ['surface', 'command', 'door', 'site', 'kind', 'referential', 'non_referential_reason', 'shadowed_by']) as [$cells]) {
            $rows[] = new GridRow(
                $cells[0],
                $cells[1],
                $cells[2],
                $cells[3],
                $cells[4],
                $cells[5] === 'yes',
                $cells[6],
                $cells[7],
            );
        }

        return $rows;
    }

    /** @return array{array<string, Probe>, array<string, Probe>} */
    private static function readProbes(string $path): array
    {
        $probes = [];
        $overrides = [];

        foreach (self::readTsv($path, self::PROBE_HEADER) as [$cells, $line]) {
            [$surface, $door, $site, $command, $miss, $hit, $hitEmpty, $observable, $signal, $echoes, $fixture, $signalSource, $reason] = $cells;
            $where = \sprintf('probes.tsv line %d (%s|%s|%s)', $line, $surface, $door, $site);

            if ($hit === '') {
                throw new DeclarationError($where . ': a probe without a hit is not a probe');
            }

            if ($miss === '') {
                throw new DeclarationError($where . ': miss is empty');
            }

            if ($signal === 'none' && trim($reason) === '') {
                throw new DeclarationError($where . ': signal=none without a reason is a door to a green report');
            }

            if ($hitEmpty === 'none' && trim($reason) === '') {
                throw new DeclarationError($where . ': hit_empty=none without a reason');
            }

            if ($command !== '*' && trim($reason) === '') {
                throw new DeclarationError($where . ': a per-command override without a reason');
            }

            if ($echoes !== 'yes' && $echoes !== 'no') {
                throw new DeclarationError($where . ': echoes must be yes or no');
            }

            if (!\in_array($signalSource, ['dictionary', 'observation', 'none'], true) && !str_starts_with($signalSource, 'cure:')) {
                throw new DeclarationError($where . ': signal_source must be dictionary, observation, none or cure:<sha>');
            }

            $probe = new Probe($surface, $door, $site, $command, $miss, $hit, $hitEmpty, $observable, $signal, $echoes === 'yes', $fixture, $signalSource, $reason, $line);

            if ($command === '*') {
                if (isset($probes[$probe->key()])) {
                    throw new DeclarationError($where . ': duplicate probe');
                }

                $probes[$probe->key()] = $probe;

                continue;
            }

            $overrideKey = $surface . '|' . $command . '|' . $door . '|' . $site;

            if (isset($overrides[$overrideKey])) {
                throw new DeclarationError($where . ': duplicate override');
            }

            $overrides[$overrideKey] = $probe;
        }

        return [$probes, $overrides];
    }

    /** @return array<string, CommandProfile> */
    private static function readCommands(string $path): array
    {
        $commands = [];

        foreach (self::readTsv($path, self::COMMAND_HEADER) as [$cells, $line]) {
            [$command, $observable, $positional, $fixture] = $cells;
            $parsed = [];

            if ($positional !== '') {
                foreach (explode(',', $positional) as $pair) {
                    $halves = explode('=', $pair, 2);

                    if (\count($halves) !== 2) {
                        throw new DeclarationError(\sprintf('command-observables.tsv line %d: positional must be name=value', $line));
                    }

                    $parsed[] = [$halves[0], $halves[1]];
                }
            }

            $commands[$command] = new CommandProfile($command, $observable, $parsed, $fixture);
        }

        return $commands;
    }

    /**
     * @param list<GridRow> $grid
     *
     * @return list<string>
     */
    private static function readCureSites(string $path, array $grid): array
    {
        $live = [];

        foreach ($grid as $row) {
            $live[$row->key()] = true;
        }

        $keys = [];

        foreach (self::readTsv($path, self::CURE_HEADER) as [$cells, $line]) {
            $key = $cells[0] . '|' . $cells[1] . '|' . $cells[2] . '|' . $cells[3];

            if (!isset($live[$key])) {
                throw new DeclarationError(\sprintf('STALE CURE SITE: cure-sites.tsv line %d names %s, which the grid does not carry', $line, $key));
            }

            $keys[] = $key;
        }

        if (\count($keys) !== 15) {
            throw new DeclarationError(\sprintf('cure-sites.tsv must carry exactly the 15 rows of the cure table, found %d', \count($keys)));
        }

        return $keys;
    }

    /**
     * The gate's normalization list is the single authority over the surfaces
     * the gate produces; the supplement adds the surfaces it does not own, and
     * may override one it does only with `gate_owned=yes` plus a reason.
     *
     * @return list<array{string, string, string, string, string}> surface, locator, kind, owner, reason
     */
    private static function readNormalization(string $root): array
    {
        $rows = [];

        foreach (self::readTsv($root . '/finding-gate/normalization.tsv', ['surface', 'locator', 'kind', 'reason']) as [$cells]) {
            $rows[] = [$cells[0], $cells[1], $cells[2], 'gate', $cells[3]];
        }

        $gateSurfaces = [];

        foreach ($rows as $row) {
            $gateSurfaces[$row[0]] = true;
        }

        foreach (self::readTsv($root . '/input-doors/normalization-supplement.tsv', self::SUPPLEMENT_HEADER) as [$cells, $line]) {
            [$surface, $locator, $kind, $gateOwned, $reason] = $cells;

            if (isset($gateSurfaces[$surface])) {
                if ($gateOwned !== 'yes' || !str_contains($reason, '§')) {
                    throw new DeclarationError(\sprintf(
                        'normalization-supplement.tsv line %d: %s is a gate-owned surface; overriding it needs gate_owned=yes plus a reason citing the measurement',
                        $line,
                        $surface,
                    ));
                }
            }

            $rows[] = [$surface, $locator, $kind, $gateOwned === 'yes' ? 'supplement-override' : 'supplement', $reason];
        }

        return $rows;
    }

    /**
     * @param list<string> $header
     *
     * @return list<array{list<string>, int}>
     */
    private static function readTsv(string $path, array $header): array
    {
        if (!is_file($path)) {
            throw new DeclarationError('missing ' . $path);
        }

        $lines = file($path, \FILE_IGNORE_NEW_LINES);

        if ($lines === false || $lines === []) {
            throw new DeclarationError('empty ' . $path);
        }

        $actual = explode("\t", array_shift($lines));

        if ($actual !== $header) {
            throw new DeclarationError(\sprintf('%s header must be: %s', $path, implode(' ', $header)));
        }

        $rows = [];

        foreach ($lines as $index => $line) {
            if (trim($line) === '' || str_starts_with($line, '#')) {
                continue;
            }

            $cells = explode("\t", $line);

            if (\count($cells) !== \count($header)) {
                throw new DeclarationError(\sprintf('%s line %d: expected %d columns, got %d', $path, $index + 2, \count($header), \count($cells)));
            }

            $rows[] = [$cells, $index + 2];
        }

        return $rows;
    }
}
