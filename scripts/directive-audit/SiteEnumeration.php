<?php

declare(strict_types=1);

namespace QmxDirectiveAudit;

/**
 * The enumeration's TSV, read strictly.
 *
 * Four columns, and the fourth is the authored threshold values — where a tab
 * is a legal character, because the values group of the extraction pattern
 * takes everything up to the line break. So the split is bounded at four and a
 * tab past that boundary belongs to the values; "exactly four columns" as a
 * refusal would reject a legal row.
 *
 * A row short of four columns used to be no refusal at all: the reader indexed
 * past the end of the array, which is an `E_WARNING` and an empty target, and a
 * silently empty target is a population entry that matches nothing.
 */
final class SiteEnumeration
{
    /**
     * @throws AuditReportError
     *
     * @return list<EnumeratedSite>
     */
    public static function fromTsv(string $tsv): array
    {
        $sites = [];

        foreach (explode("\n", $tsv) as $offset => $line) {
            if ($line === '') {
                continue;
            }

            [$file, $number, $target, $values] = self::columnsOf($line, $offset + 1);

            if (preg_match('/^\d+$/', $number) !== 1) {
                throw new AuditReportError(\sprintf(
                    'Enumeration line %d names "%s" as its line number.',
                    $offset + 1,
                    $number,
                ));
            }

            if ($target === '') {
                throw new AuditReportError(\sprintf('Enumeration line %d addresses nothing.', $offset + 1));
            }

            $sites[] = new EnumeratedSite($file, (int) $number, $target, $values);
        }

        return $sites;
    }

    /**
     * @throws AuditReportError
     *
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private static function columnsOf(string $line, int $number): array
    {
        $columns = explode("\t", $line, 4);

        if (\count($columns) !== 4) {
            throw new AuditReportError(\sprintf(
                'Enumeration line %d has %d column(s), expected file, line, target and values.',
                $number,
                \count($columns),
            ));
        }

        return [$columns[0], $columns[1], $columns[2], $columns[3]];
    }
}
