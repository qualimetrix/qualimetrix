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

final class Ledger
{
    public const string PATH = 'docs/internal/plans/promise-effect/measurement/promise-ledger.tsv';

    /**
     * @param list<FormRow> $forms
     * @param list<PairRow> $pairs
     */
    private function __construct(
        public readonly array $forms,
        public readonly array $pairs,
        public readonly int $deferredRows,
        public readonly int $compositionRows,
    ) {}

    public static function load(string $root): self
    {
        $lines = file($root . '/' . self::PATH, \FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            throw new LedgerError('cannot read ' . self::PATH);
        }

        $forms = [];
        $pairs = [];
        $deferred = 0;
        $composition = 0;
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
            // who wins between two or three writers of one path. Counted so
            // the reader does not silently drop what it does not judge, and
            // skipped rather than parsed into `$forms`/`$pairs`: axis C's
            // probe and its own verdicts are a later package's input, not
            // this stand's (02-stand.md's explicit boundary). Recognising the
            // three kinds by name, rather than skipping anything unmatched,
            // keeps a genuine typo in `kind` a `LedgerError` below.
            if ($kind === 'composition-path' || $kind === 'composition-triple' || $kind === 'composition-bucket') {
                ++$composition;

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

        return new self($forms, $pairs, $deferred, $composition);
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
