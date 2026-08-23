<?php

declare(strict_types=1);

namespace QmxFindingGate;

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
            if (trim($line) === '' || str_starts_with($line, '#')) {
                continue;
            }

            $fields = explode("\t", $line);

            if (\count($fields) !== \count($columns)) {
                throw new GateError(\sprintf('%s line %d has %d field(s), expected %d.', $path, $number + 2, \count($fields), \count($columns)));
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
