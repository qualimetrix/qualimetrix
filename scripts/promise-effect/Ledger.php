<?php

declare(strict_types=1);

/**
 * Reading the promise ledger of stage 01 — the stand's only source of what was
 * promised.
 *
 * The ledger is frozen. This reader never writes it and never repairs a row:
 * a row that disagrees with the tree is a finding of the run, not an input to
 * be corrected.
 */

namespace Qualimetrix\PromiseEffect;

use RuntimeException;

final class LedgerError extends RuntimeException {}

/** One `kind=form` row: a promise about the form of one value on one door. */
final readonly class FormRow
{
    /**
     * @param list<string> $promisedForms
     * @param list<string> $decidedForms
     */
    public function __construct(
        public string $door,
        public string $path,
        public array $promisedForms,
        public string $nullMeans,
        public string $equivalences,
        public string $carrier,
        public string $status,
        public string $note,
        public array $decidedForms,
        public string $alias = '',
    ) {}

    public function key(): string
    {
        return 'form|' . $this->door . '|' . $this->path;
    }
}

/** One `kind=pair` row: a promise about two keys standing next to each other. */
final readonly class PairRow
{
    public function __construct(
        public string $rule,
        public string $keyA,
        public string $keyB,
        public string $sourceScope,
        public string $coexistence,
        public string $carrier,
        public string $status,
        public string $note,
        public string $kind,
    ) {}

    /**
     * `kind` is part of the key because without it nineteen `same-source`
     * pairs collapse onto thirteen: the same two keys of the same rule are a
     * row twice, once as `2-same-name-top-vs-level` and once as
     * `4-cross-level` (and once as `6-precedence-fill-in`). Their verdicts
     * agree today, which is exactly what made the collision invisible — the
     * floor and every control addressed by key could not name one of the two,
     * and a divergence between them would have been silently averaged into
     * whichever row the grid happened to write last.
     *
     * Adding `path` instead would separate eighteen of the nineteen:
     * `coupling.cbo|class.scope|scope` carries `path=(top)|class` on both
     * sides, and only `kind` tells those two apart.
     *
     * The segment goes LAST: `Population::uncovered()` reads the rule and the
     * two keys off positions 1-3, and a key that inserted a segment in front
     * of them would move the population silently.
     */
    public function key(): string
    {
        return 'pair|' . $this->rule . '|' . $this->keyA . '|' . $this->keyB . '|' . $this->sourceScope . '|' . $this->kind;
    }
}

/**
 * One `kind=composition-*` row: a promise about WHICH writer of one path wins.
 *
 * The three kinds share ten columns with every other row (the ledger's own
 * layout rule), and the meaning of columns 2-6 differs per kind:
 *
 *   composition-path   | path              | writer_low         | writer_high          | path_scope | promised_winner
 *   composition-bucket | rule              | key_low@writer_low | key_high@writer_high | level      | promised_outcome
 *   composition-triple | rule              | path@slot          | l1/l2/l3             | T1..T4     | promised_survival
 *
 * Held as one type rather than three because every consumer asks the same
 * four questions of a row — who writes low, who writes high, what is disputed,
 * what was promised — and the kind is what says how to read the answers.
 */
final readonly class CompositionRow
{
    /** @param list<string> $layers the triple's layers, low to high; empty for the other two kinds */
    public function __construct(
        public string $kind,
        public string $subject,
        public string $low,
        public string $high,
        public string $scope,
        public string $promised,
        public string $carrier,
        public string $status,
        public string $note,
        public array $layers = [],
    ) {}

    /**
     * The scope value `unrestricted` is what says the promise is about the
     * layer order and not about the one path column 2 happens to name, which
     * is what lets the framework-key observation point stand on the same row.
     * An `80-alias-restricted` row promises nothing outside the 80 alias
     * paths, and no framework key is one of them.
     */
    public function reachesEveryPath(): bool
    {
        return $this->scope === 'unrestricted';
    }

    public function key(): string
    {
        return 'composition|' . $this->kind . '|' . $this->subject . '|' . $this->low . '|' . $this->high . '|' . $this->scope;
    }
}

final class Ledger
{
    public const string PATH = 'docs/internal/plans/promise-effect/measurement/promise-ledger.tsv';

    /**
     * @param list<FormRow> $forms
     * @param list<PairRow> $pairs
     * @param list<CompositionRow> $compositions
     */
    private function __construct(
        public readonly array $forms,
        public readonly array $pairs,
        public readonly array $compositions,
        public readonly int $deferredRows,
    ) {}

    public static function load(string $root): self
    {
        $lines = file($root . '/' . self::PATH, \FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new LedgerError('cannot read ' . self::PATH);
        }

        $forms = [];
        $pairs = [];
        $compositions = [];
        $deferred = 0;
        $seenHeader = false;

        foreach ($lines as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $cells = explode("\t", $line);

            if (!$seenHeader) {
                $seenHeader = true;

                if (($cells[0] ?? '') !== 'kind') {
                    throw new LedgerError('the ledger does not start with its schema header');
                }

                continue;
            }

            $cells = array_pad($cells, 10, '');
            $kind = $cells[0];

            if ($kind === 'deferred') {
                ++$deferred;

                continue;
            }

            // Stage 01's axis-C promise rows (`docs/internal/plans/source-composition/01-promise.md`):
            // who wins between two or three writers of one path. Recognised by
            // name rather than by "anything unmatched", so a genuine typo in
            // `kind` stays the `LedgerError` below.
            if ($kind === 'composition-path' || $kind === 'composition-triple' || $kind === 'composition-bucket') {
                $compositions[] = new CompositionRow(
                    kind: $kind,
                    subject: $cells[1],
                    low: $cells[2],
                    high: $cells[3],
                    scope: $cells[4],
                    promised: $cells[5],
                    carrier: $cells[6],
                    status: $cells[7],
                    note: $cells[8],
                    layers: $kind === 'composition-triple' ? self::layers($cells[3]) : [],
                );

                continue;
            }

            if ($kind === 'form') {
                $path = $cells[2];
                $alias = '';

                // The cli-alias door writes its own flag, and the ledger keeps
                // both halves in one cell: `--flag -> rules.<rule>.<option>`.
                if (str_contains($path, ' -> ')) {
                    [$alias, $path] = explode(' -> ', $path, 2);
                }

                $forms[] = new FormRow(
                    door: $cells[1],
                    path: $path,
                    promisedForms: self::list($cells[3]),
                    nullMeans: $cells[4],
                    equivalences: $cells[5],
                    carrier: $cells[6],
                    status: $cells[7],
                    note: $cells[8],
                    decidedForms: self::list($cells[9]),
                    alias: $alias,
                );

                continue;
            }

            if ($kind === 'pair') {
                $pairs[] = new PairRow(
                    rule: $cells[1],
                    keyA: $cells[2],
                    keyB: $cells[3],
                    sourceScope: $cells[4],
                    coexistence: $cells[5],
                    carrier: $cells[6],
                    status: $cells[7],
                    note: $cells[8],
                    kind: self::pairKind($cells[1], $cells[2], $cells[3], $cells[8]),
                );

                continue;
            }

            throw new LedgerError('unknown row kind "' . $kind . '"');
        }

        return new self($forms, $pairs, $compositions, $deferred);
    }

    /**
     * The three layers of a `composition-triple` row, low to high, out of the
     * `l1/l2/l3` cell the ledger's own layout rule packs them into.
     *
     * Fail-closed on anything but three: the probe writes one document per
     * layer, and a row naming two or four would silently become a different
     * experiment from the one the denominator counted.
     *
     * @return list<string>
     */
    private static function layers(string $cell): array
    {
        $layers = array_values(array_map(trim(...), explode('/', $cell)));

        if (\count($layers) !== 3) {
            throw new LedgerError('a composition-triple row names ' . \count($layers) . ' layers in "' . $cell . '", and a triple has three');
        }

        return $layers;
    }

    /**
     * The pair row's kind, read out of the note's trailing `kind=...; path=...`
     * chunk rather than out of a column of its own: `key-pairs.tsv` is the
     * frozen product of stage 01 and this reader never writes it.
     *
     * Fail-closed. A pair row without a kind would silently rejoin the
     * collision this segment exists to split, and the reader cannot tell that
     * from a row whose kind it simply failed to parse.
     */
    private static function pairKind(string $rule, string $keyA, string $keyB, string $note): string
    {
        if (preg_match('/\bkind=([^;|]+)/', $note, $match) !== 1) {
            throw new LedgerError(
                'the pair row ' . $rule . '|' . $keyA . '|' . $keyB . ' carries no `kind=` in its note, '
                . 'so it cannot be told apart from a sibling row of the same two keys',
            );
        }

        return trim($match[1]);
    }

    /** @return list<string> */
    private static function list(string $cell): array
    {
        $cell = trim($cell);

        if ($cell === '') {
            return [];
        }

        return array_values(array_filter(array_map(trim(...), explode(',', $cell)), static fn(string $v): bool => $v !== ''));
    }
}
