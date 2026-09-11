<?php

declare(strict_types=1);

/**
 * The observability limit: where the stand cannot put the row's question to
 * the product at all, declared in `promise-effect/observability-limits.tsv`.
 *
 * Two shapes of that, and they are different failures. A CLI door has no
 * syntax for `string-number`, `list` or `map` — argv is text, `7331` is the
 * only spelling of a number there, and quoting it writes six characters — so a
 * verdict on such a cell is about the stand's spelling, not about the form the
 * row is written on. And a key whose values are paths, namespaces or channel
 * selectors answers about the DOMAIN of the canonical magnitude, not about its
 * form: `suppress-paths: 7331` excludes nothing because 7331 is not a file.
 *
 * Read at JUDGEMENT, never at writing. That is the whole point: re-spelling
 * the probe would be a change of input, the frozen half is nailed to
 * `6a833ab8` and cannot be re-measured, and the 238 cells being argued about
 * would stop comparing across the pair. A rule consulted by the classifier
 * applies to both halves by construction.
 *
 * The guard that keeps this from being a silencer is {@see self::conflicts()}:
 * a limit covering a cell whose unrestricted verdict is `OK` is refused, on
 * both halves. `OK` means the effect was distinguishable and the producer
 * reachable, and a door that cannot express a form cannot have carried a
 * lawful effect through it — so such a declaration is a deleted measurement,
 * which is the one mistake this table could make.
 */

namespace Qualimetrix\PromiseEffect;

final readonly class LimitRow
{
    public function __construct(
        public string $kind,
        public string $door,
        public string $key,
        public string $form,
        public string $reason,
    ) {}

    public function covers(string $door, string $path, string $form): bool
    {
        if ($this->form !== $form) {
            return false;
        }

        if ($this->door !== '*' && $this->door !== $door) {
            return false;
        }

        return $this->key === '*' || $this->key === self::leafOf($path);
    }

    /**
     * The option leaf a path ends with — the unit the limit is keyed on, for
     * the same reason `axis-a-hits.tsv` is keyed on it: the three framework
     * keys repeat under every producer, and an enumeration of 162 paths would
     * be four statements written 162 times.
     */
    private static function leafOf(string $path): string
    {
        return str_contains($path, '.') ? substr($path, (int) strrpos($path, '.') + 1) : $path;
    }
}

final class Limits
{
    private const string PATH = 'promise-effect/observability-limits.tsv';

    /** @param list<LimitRow> $rows */
    private function __construct(public readonly array $rows) {}

    public static function load(string $root): self
    {
        $lines = file($root . '/' . self::PATH, \FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new LedgerError('cannot read ' . self::PATH);
        }

        $rows = [];
        $header = false;

        foreach ($lines as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!$header) {
                $header = true;

                continue;
            }

            $cells = array_pad(explode("\t", $line), 5, '');
            $rows[] = new LimitRow($cells[0], $cells[1], $cells[2], $cells[3], $cells[4]);
        }

        return new self($rows);
    }

    /** The declared reason this cell cannot be asked, or null when it can. */
    public function reasonFor(string $door, string $path, string $form): ?string
    {
        foreach ($this->rows as $row) {
            if ($row->covers($door, $path, $form)) {
                return $row->reason;
            }
        }

        return null;
    }

    /**
     * Cells a limit covers that would have read `OK` without it — a limit
     * declared over a working observation, which deletes evidence instead of
     * admitting blindness.
     *
     * @param list<array{string, string}> $unrestricted cell key, the verdict it carries without the limit
     *
     * @return list<string>
     */
    public function conflicts(array $unrestricted): array
    {
        $conflicts = [];

        foreach ($unrestricted as [$key, $verdict]) {
            if ($verdict === Verdict::OK) {
                $conflicts[] = $key . ': a declared observability limit covers a cell whose effect the stand DOES observe';
            }
        }

        return $conflicts;
    }
}
