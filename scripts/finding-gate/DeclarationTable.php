<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * The refusals every declaration table shares, so each form states only its own.
 *
 * A table that does not exist declares nothing. A row whose `reason` is empty
 * or `?` is refused: the reason is the one thing a run cannot produce, and a
 * derive run writes `?` exactly so that loading stops until someone has said
 * why.
 */
final class DeclarationTable
{
    /**
     * @param string $root the `finding-gate` directory of the candidate tree
     * @param list<string> $columns
     *
     * @return list<array<string, string>>
     */
    public static function rows(string $root, string $index, array $columns): array
    {
        $path = $root . '/' . $index;

        if (!is_file($path)) {
            return [];
        }

        $rows = Tsv::rows($path, $columns);

        foreach ($rows as $number => $row) {
            if (isset($row['reason']) && ($row['reason'] === '' || $row['reason'] === '?')) {
                throw new GateError(\sprintf(
                    '%s row %d has no reason. A derived row carries "?" until someone says why the change was made;'
                    . ' that sentence is the declaration.',
                    $index,
                    $number + 1,
                ));
            }

            foreach ($row as $column => $value) {
                if ($value === '' && $column !== 'reason') {
                    throw new GateError(\sprintf('%s row %d leaves "%s" empty.', $index, $number + 1, $column));
                }
            }
        }

        return $rows;
    }

    /** @param list<string> $allowed */
    public static function oneOf(string $index, int $row, string $column, string $value, array $allowed): void
    {
        if (!\in_array($value, $allowed, true)) {
            throw new GateError(\sprintf(
                '%s row %d: "%s" is "%s", and may only be one of %s.',
                $index,
                $row,
                $column,
                $value,
                implode(', ', $allowed),
            ));
        }
    }

    /**
     * Refuses a key declared twice, where a second row could only restate the
     * first or contradict it.
     *
     * @param array<string, true> $seen
     */
    public static function once(string $index, int $row, string $key, string $what, array &$seen): void
    {
        if (isset($seen[$key])) {
            throw new GateError(\sprintf('%s row %d declares %s a second time.', $index, $row, $what));
        }

        $seen[$key] = true;
    }
}
