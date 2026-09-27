<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * How a derive run writes what a form measured: every form the same way.
 *
 * A derived row is written only under an intent somebody declared — the row
 * names its intent, and a row whose intent is not declared is refused rather
 * than written, which is what keeps a derivation from licensing a change
 * nobody asked for. Rows are sorted, so the same measurement is always the same
 * bytes and a run can hold the table to a fresh measurement byte for byte. A
 * row that carries a reason keeps it only while the row is unchanged; a new or
 * changed row gets `?`, which loading refuses until someone says why.
 */
final class DerivedTable
{
    public const string UNEXPLAINED = '?';

    /**
     * @param list<string> $columns
     * @param list<string> $intentColumns the columns that together name the intent a row was derived under
     * @param list<string> $intents the declared intents, each its intent columns joined by a tab
     * @param list<list<string>> $rows
     */
    public static function render(array $columns, array $intentColumns, array $intents, array $rows): string
    {
        $positions = [];

        foreach ($intentColumns as $column) {
            $position = array_search($column, $columns, true);

            if (!\is_int($position)) {
                throw new GateError(\sprintf('"%s" is no column of the derived table.', $column));
            }

            $positions[] = $position;
        }

        foreach ($rows as $row) {
            if (\count($row) !== \count($columns)) {
                throw new GateError(\sprintf('A derived row has %d field(s), expected %d.', \count($row), \count($columns)));
            }

            $intent = implode("\t", array_map(static fn(int $position): string => $row[$position], $positions));

            if (!\in_array($intent, $intents, true)) {
                throw new GateError(\sprintf(
                    'A derivation measured a change under "%s", which no intent declares, and may not write it. The'
                    . ' change stays undeclared and the run that measured it judges it.',
                    str_replace("\t", ' / ', $intent),
                ));
            }

            foreach ($row as $field) {
                if (str_contains($field, "\t") || str_contains($field, "\n")) {
                    throw new GateError('A derived field carries a tab or a line break, which the table cannot hold.');
                }
            }
        }

        usort($rows, static fn(array $a, array $b): int => $a <=> $b);

        return Tsv::render($columns, $rows);
    }

    /**
     * Writes a rendered table under `finding-gate/`, or removes it when it has
     * no row, so an unused form leaves no file behind.
     *
     * @return list<string> what was written or removed
     */
    public static function write(string $root, string $file, string $rendered, int $rowCount): array
    {
        $path = $root . '/' . $file;

        if ($rowCount === 0) {
            if (!is_file($path)) {
                return [];
            }

            Fs::removeRecursively($path);

            return [$file . ' (removed)'];
        }

        Fs::write($path, $rendered);

        return [$file];
    }

    /**
     * The reason already written against a row — but only while the row it
     * explains is the same row.
     *
     * @param array<string, array{row: string, reason: string}> $existing key => the row as tracked and its reason
     */
    public static function reasonFor(array $existing, string $key, string $measuredRow): string
    {
        $tracked = $existing[$key] ?? null;

        return $tracked === null || $tracked['row'] !== $measuredRow ? self::UNEXPLAINED : $tracked['reason'];
    }
}
