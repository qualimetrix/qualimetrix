<?php

declare(strict_types=1);

namespace QmxFindingGate;

use JsonException;

/**
 * Findings a step withdrew from, or introduced into, one report of one case.
 *
 * The key is the whole record as that report publishes it, in its canonical
 * one-line JSON spelling, never an identity: the gate's identity is shared by
 * twenty findings of the corpus, and a message is what later steps rewrite.
 * A withdrawn record is spelled as the reference publishes it after the maps
 * translated it and normalization ran; an introduced one as the candidate
 * publishes it. Rows are a multiset: k identical rows withdraw exactly k
 * instances of that record, and a row nothing claimed is stale.
 *
 * A row of the `json` report must carry exactly that side's compared fields —
 * the reference's differ from the candidate's by the fields `declared-fields.tsv`
 * adds and drops — so a partial record cannot match "whatever has these keys".
 */
final class DeclaredRecords
{
    public const array COLUMNS = ['change', 'case', 'report', 'record', 'reason'];

    public const string INDEX = 'declared-records.tsv';

    public const string WITHDRAWN = 'withdrawn';

    public const string INTRODUCED = 'introduced';

    public const array REPORTS = ['json', 'suppressed'];

    /** What distinguishes a record of the suppressed report from a finding. */
    private const array SUPPRESSED_KEYS = ['mechanism', 'suppressor'];

    private const int CANONICAL = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR;

    /** @var array<int, true> */
    private array $claimed = [];

    /** @param list<array{change: string, case: string, report: string, record: string, reason: string}> $rows */
    private function __construct(private readonly array $rows) {}

    /**
     * @param list<string> $candidateFields the fields an introduced `json` record carries
     * @param list<string> $referenceFields the fields a withdrawn `json` record carries
     */
    public static function load(string $root, array $candidateFields, array $referenceFields): self
    {
        $rows = [];

        foreach (DeclarationTable::rows($root, self::INDEX, self::COLUMNS) as $index => $row) {
            $number = $index + 1;
            DeclarationTable::oneOf(self::INDEX, $number, 'change', $row['change'], [self::WITHDRAWN, self::INTRODUCED]);
            DeclarationTable::oneOf(self::INDEX, $number, 'report', $row['report'], self::REPORTS);
            $record = self::decoded($row['record'], $number);

            if (self::canonical($record) !== $row['record']) {
                throw new GateError(\sprintf(
                    '%s row %d spells its record otherwise than canonically (%s). A record is matched by equality,'
                    . ' so a spelling nothing publishes could never match.',
                    self::INDEX,
                    $number,
                    self::canonical($record),
                ));
            }

            $missing = $row['report'] === 'json'
                ? self::keyDifference(array_keys($record), $row['change'] === self::WITHDRAWN ? $referenceFields : $candidateFields)
                : array_values(array_diff(self::SUPPRESSED_KEYS, array_keys($record)));

            if ($missing !== []) {
                throw new GateError(\sprintf(
                    '%s row %d is not a whole %s record: %s. A partial record would match every record that shares'
                    . ' the fields it names.',
                    self::INDEX,
                    $number,
                    $row['report'],
                    implode('; ', $missing),
                ));
            }

            $rows[] = [
                'change' => $row['change'],
                'case' => $row['case'],
                'report' => $row['report'],
                'record' => $row['record'],
                'reason' => $row['reason'],
            ];
        }

        return new self($rows);
    }

    /**
     * The one spelling a row may carry for a decoded record.
     *
     * @param array<string, mixed> $record
     */
    public static function canonical(array $record): string
    {
        return json_encode($record, self::CANONICAL);
    }

    public function count(): int
    {
        return \count($this->rows);
    }

    /**
     * Claims one row not yet claimed that declares exactly this change of this
     * record, and says whether there was one.
     */
    public function claim(string $change, string $case, string $report, string $record): bool
    {
        foreach ($this->rows as $index => $row) {
            if (!isset($this->claimed[$index]) && $row['change'] === $change && $row['case'] === $case
                && $row['report'] === $report && $row['record'] === $record
            ) {
                $this->claimed[$index] = true;

                return true;
            }
        }

        return false;
    }

    /** @return list<array{scope: string, detail: string}> */
    public function stale(): array
    {
        $stale = [];

        foreach ($this->rows as $index => $row) {
            if (!isset($this->claimed[$index])) {
                $stale[] = [
                    'scope' => 'case:' . $row['case'] . '|format:' . $row['report'],
                    'detail' => \sprintf('The %s record %s', $row['change'], $row['record']),
                ];
            }
        }

        return $stale;
    }

    /** @return array<string, mixed> */
    private static function decoded(string $record, int $number): array
    {
        try {
            $decoded = json_decode($record, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new GateError(\sprintf('%s row %d carries a record that is not JSON (%s).', self::INDEX, $number, $error->getMessage()));
        }

        if (!\is_array($decoded) || array_is_list($decoded)) {
            throw new GateError(\sprintf('%s row %d carries a record that is not a JSON object.', self::INDEX, $number));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param list<string> $keys
     * @param list<string> $fields
     *
     * @return list<string>
     */
    private static function keyDifference(array $keys, array $fields): array
    {
        $problems = [];
        $missing = array_diff($fields, $keys);
        $extra = array_diff($keys, $fields);

        if ($missing !== []) {
            $problems[] = 'it lacks ' . implode(', ', $missing);
        }

        if ($extra !== []) {
            $problems[] = 'it carries ' . implode(', ', $extra) . ', which the side does not compare';
        }

        return $problems;
    }
}
