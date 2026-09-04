<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * The tab-separated form every tracked declaration is written in.
 *
 * A declaration is matched against a measurement by exact equality, so a field
 * that carries invisible whitespace does not fire — and the refusal that follows
 * names staleness, which is the wrong defect. A stray `\r` from an editor, or a
 * space typed after a value when transcribing a measured pair, would otherwise
 * be diagnosed as "you declared a move nothing performed". So both are refused
 * where they can still be pointed at: at the line that carries them.
 */
final class Tsv
{
    /**
     * @param list<string> $columns
     *
     * @return list<array<string, string>>
     */
    public static function rows(string $path, array $columns): array
    {
        $lines = explode("\n", Fs::read($path));
        $header = array_shift($lines);

        if (trim((string) $header) !== implode("\t", $columns)) {
            throw new GateError(\sprintf('%s must start with the header "%s".', $path, implode(' | ', $columns)));
        }

        $rows = [];

        foreach ($lines as $number => $line) {
            $line = rtrim($line, "\r");

            if (trim($line) === '' || str_starts_with($line, '#')) {
                continue;
            }

            $fields = explode("\t", $line);

            if (\count($fields) !== \count($columns)) {
                throw new GateError(\sprintf('%s line %d has %d field(s), expected %d.', $path, $number + 2, \count($fields), \count($columns)));
            }

            foreach ($fields as $index => $field) {
                if ($field !== trim($field)) {
                    throw new GateError(\sprintf(
                        '%s line %d has whitespace around the "%s" field. A declaration is matched by exact equality,'
                        . ' so a value with an invisible edge fires nowhere and is reported as a declaration of'
                        . ' something that did not happen.',
                        $path,
                        $number + 2,
                        $columns[$index],
                    ));
                }
            }

            $rows[] = array_combine($columns, $fields);
        }

        return $rows;
    }

    /**
     * @param list<string> $columns
     * @param list<list<string>> $rows
     */
    public static function render(array $columns, array $rows): string
    {
        $lines = [implode("\t", $columns)];

        foreach ($rows as $row) {
            $lines[] = implode("\t", $row);
        }

        return implode("\n", $lines) . "\n";
    }
}
