<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * The second source of permission `delta-overreach` consults, and the only one
 * that can license a move of a field no rename performs.
 *
 * A declared split licenses the moves its explained records produced, and it
 * knows how to produce moves of three fields only — `channel`, `rule` and
 * `code`, the fields a rename of a channel key rewrites. So a step that changes
 * the *text* of a finding — the "did you mean" list of a diagnostic, say — moves
 * `message` inside a record nothing renamed, and until this file existed no
 * declaration of any kind could state that: not because the change was
 * dangerous, but because there was no list for it.
 *
 * `declared-field-moves.tsv` (`surface`, `field`, `from`, `to`, `reason`) is
 * that list, and every column of the key is load-bearing:
 *
 * - **The key is the whole quadruple, exact.** No prefix, no pattern, no "any
 *   value of this field". A row licenses one pair of values on one surface, and
 *   a line that moves the same field between any other pair is refused exactly
 *   as before. A looser key would be `normalization` — "this field is not
 *   compared" — entering through the back door, and normalization is forbidden
 *   from reaching a compared field for the reason this whole vocabulary exists.
 * - **A row fires on equality, never on containment.** The neighbouring probe
 *   harness has already paid for a substring comparison once; a `from` that is
 *   a substring of what the run measured licenses a move nobody declared.
 * - **A row nothing fired is a lie.** A declared pair no diff line produced is
 *   `field-move-stale`, the same class of defect as `map-stale` and
 *   `delta-stale`: a statement about a change that did not happen.
 * - **The row is not a waiver of the declared delta.** It removes one wall and
 *   no other: the surface still has to carry a declared delta, that diff is
 *   still compared byte for byte (`delta-mismatch`) and still refused past the
 *   size limit (`delta-too-large`). What this licenses is a move *inside* a
 *   diff that was already measured and already declared.
 *
 * Unlike the diff files, these rows are typed rather than derived — the pair
 * they name is printed verbatim in the `delta-overreach` failure the run they
 * explain produced, so what a hand writes here is a transcription of a
 * measurement, and a mistranscription is `field-move-stale` rather than a
 * silent widening.
 */
final class DeclaredFieldMoves
{
    public const COLUMNS = ['surface', 'field', 'from', 'to', 'reason'];

    public const INDEX = 'declared-field-moves.tsv';

    /** @var array<string, array{surface: string, field: string, from: string, to: string, reason: string}> */
    private array $rows;

    /** @var array<string, true> keys of the rows a diff line actually used */
    private array $credited = [];

    /** @param array<string, array{surface: string, field: string, from: string, to: string, reason: string}> $rows */
    private function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    /** @param string $root the `finding-gate` directory of the candidate tree */
    public static function load(string $root): self
    {
        $index = $root . '/' . self::INDEX;

        if (!is_file($index)) {
            return new self([]);
        }

        $rows = [];

        foreach (Tsv::rows($index, self::COLUMNS) as $row) {
            if ($row['surface'] === '' || $row['field'] === '') {
                throw new GateError(\sprintf(
                    '%s has a row naming no %s. A row licenses one move on one surface; one that names neither'
                    . ' licenses whatever a diff happens to contain.',
                    self::INDEX,
                    $row['surface'] === '' ? 'surface' : 'field',
                ));
            }

            if ($row['from'] === $row['to']) {
                throw new GateError(\sprintf(
                    '%s declares "%s" moving from a value to itself on "%s", which is not a move.',
                    self::INDEX,
                    $row['field'],
                    $row['surface'],
                ));
            }

            $key = self::key($row['surface'], $row['field'], $row['from'], $row['to']);

            if (isset($rows[$key])) {
                throw new GateError(\sprintf(
                    '%s declares the same move of "%s" on "%s" twice.',
                    self::INDEX,
                    $row['field'],
                    $row['surface'],
                ));
            }

            if ($row['reason'] === '' || $row['reason'] === '?') {
                throw new GateError(\sprintf(
                    '%s licenses a move of "%s" on "%s" with no reason. Why a compared field moved is the whole'
                    . ' declaration; the pair alone only restates what the run measured.',
                    self::INDEX,
                    $row['field'],
                    $row['surface'],
                ));
            }

            $rows[$key] = [
                'surface' => $row['surface'],
                'field' => $row['field'],
                'from' => $row['from'],
                'to' => $row['to'],
                'reason' => $row['reason'],
            ];
        }

        return new self($rows);
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    public function count(): int
    {
        return \count($this->rows);
    }

    /**
     * Whether a declared row licenses this exact move on this exact surface,
     * crediting the row that did.
     */
    public function allows(string $surface, string $field, string $from, string $to): bool
    {
        $key = self::key($surface, $field, $from, $to);

        if (!isset($this->rows[$key])) {
            return false;
        }

        $this->credited[$key] = true;

        return true;
    }

    /**
     * Declared moves no diff line performed: the same lie as a stale map row.
     *
     * Reported against the surface the row names rather than against the row's
     * own text, so the failure's scope is the same kind of thing every other
     * declaration failure's scope is — a surface a control can pin to.
     *
     * @return list<array{surface: string, move: string}>
     */
    public function staleMoves(): array
    {
        $stale = [];

        foreach ($this->rows as $key => $row) {
            if (isset($this->credited[$key])) {
                continue;
            }

            $stale[] = [
                'surface' => $row['surface'],
                'move' => \sprintf('"%s" ("%s" -> "%s")', $row['field'], $row['from'], $row['to']),
            ];
        }

        return $stale;
    }

    private static function key(string $surface, string $field, string $from, string $to): string
    {
        return $surface . "\0" . $field . "\0" . $from . "\0" . $to;
    }
}
