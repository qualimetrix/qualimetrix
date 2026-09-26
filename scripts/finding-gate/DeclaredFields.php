<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * Fields added to or removed from one report's records, with measured values.
 *
 * The candidate's tuple is the tracked one; the reference's is that tuple
 * minus the fields added here plus the fields dropped here. For an added field
 * the derived table (`declared-fields.derived.tsv`) names every record that
 * carries it and the value it carries there, as a JSON spelling. That table
 * witnesses that the field appears where and as it did when it was measured —
 * reproducibility and reach — and not that its values are right, which the
 * step's own tests have to show. A derived row naming a field no row adds is
 * refused at load; a row whose field neither tuple changed is stale.
 *
 * @phpstan-type Record array{record:string,fields:array<string,mixed>}
 * @phpstan-type Measurement array{case:string,side:string,records:list<Record>}
 */
final class DeclaredFields
{
    public const array COLUMNS = ['change', 'report', 'field', 'reason'];

    public const array DERIVED_COLUMNS = ['report', 'field', 'case', 'record', 'value'];

    public const array REPORTS = ['json', 'metrics', 'directives'];

    public const string INDEX = 'declared-fields.tsv';

    public const string DERIVED = 'declared-fields.derived.tsv';

    public const string ADDED = 'added';

    public const string REMOVED = 'removed';

    /** @var array<string, true> */
    private array $credited = [];

    /** @var array<string, array{report:string,case:string,side:string}> */
    private array $required = [];

    /** @var array<string, Measurement> */
    private array $measurements = [];

    /**
     * @param array<string, string> $changes report and field => added|removed
     * @param list<array{report:string,field: string, case: string, record: string, value: string}> $derived
     */
    private function __construct(
        private readonly array $changes,
        private readonly array $derived,
        private readonly string $derivedText,
    ) {}

    public static function load(string $root): self
    {
        $changes = [];

        foreach (DeclarationTable::rows($root, self::INDEX, self::COLUMNS) as $index => $row) {
            DeclarationTable::oneOf(self::INDEX, $index + 1, 'change', $row['change'], [self::ADDED, self::REMOVED]);

            DeclarationTable::oneOf(self::INDEX, $index + 1, 'report', $row['report'], self::REPORTS);
            $key = $row['report'] . "\0" . $row['field'];

            if (isset($changes[$key])) {
                throw new GateError(\sprintf('%s row %d declares the field "%s" a second time.', self::INDEX, $index + 1, $row['field']));
            }

            $changes[$key] = $row['change'];
        }

        $derived = [];

        foreach (DeclarationTable::rows($root, self::DERIVED, self::DERIVED_COLUMNS) as $index => $row) {
            DeclarationTable::oneOf(self::DERIVED, $index + 1, 'report', $row['report'], self::REPORTS);
            $key = $row['report'] . "\0" . $row['field'];

            if (($changes[$key] ?? null) !== self::ADDED) {
                throw new GateError(\sprintf(
                    '%s row %d measures the field "%s", which no row of %s adds.',
                    self::DERIVED,
                    $index + 1,
                    $row['field'],
                    self::INDEX,
                ));
            }

            $derived[] = ['report' => $row['report'], 'field' => $row['field'], 'case' => $row['case'], 'record' => $row['record'], 'value' => $row['value']];
        }

        $path = $root . '/' . self::DERIVED;

        return new self($changes, $derived, is_file($path) ? Fs::read($path) : '');
    }

    public function count(): int
    {
        return \count($this->changes);
    }

    /**
     * The fields the reference compares, given the candidate's.
     *
     * @param list<string> $candidateFields
     *
     * @return list<string>
     */
    public function referenceFields(string $report, array $candidateFields): array
    {
        $changes = $this->changes($report);
        $fields = [];

        foreach ($candidateFields as $field) {
            if (($changes[$field] ?? null) !== self::ADDED) {
                $fields[] = $field;
            }
        }

        foreach ($changes as $field => $change) {
            if ($change === self::REMOVED && !\in_array($field, $fields, true)) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /** @return array<string, string> field => added|removed */
    public function changes(string $report): array
    {
        self::assertReport($report);
        $changes = [];
        foreach ($this->changes as $key => $change) {
            [$owner, $field] = explode("\0", $key, 2);
            if ($owner === $report) {
                $changes[$field] = $change;
            }
        }
        return $changes;
    }

    /** @return list<array{report:string,field: string, case: string, record: string, value: string}> */
    public function derived(string $report): array
    {
        self::assertReport($report);
        return array_values(array_filter($this->derived, static fn(array $row): bool => $row['report'] === $report));
    }

    public function derivedText(): string
    {
        return $this->derivedText;
    }

    /** Records that the two sides' tuples do differ by this field as declared. */
    public function credit(string $report, string $field): void
    {
        self::assertReport($report);
        $key = $report . "\0" . $field;
        if (!isset($this->changes[$key])) {
            throw new GateError('Cannot credit an undeclared field.');
        }
        $this->credited[$key] = true;
    }

    /** @return list<array{scope: string, detail: string}> */
    public function stale(): array
    {
        $stale = [];

        foreach ($this->changes as $key => $change) {
            [$report, $field] = explode("\0", $key, 2);
            if (!isset($this->credited[$key])) {
                $stale[] = ['scope' => self::INDEX, 'detail' => \sprintf('The %s %s field "%s"', $change, $report, $field)];
            }
        }

        return $stale;
    }

    public function requireMeasurements(string $report, string $case, string $side): void
    {
        $key = self::measurementKey($report, $case, $side);
        if (isset($this->required[$key])) {
            throw new GateError('A record publication was registered twice: ' . $key);
        }
        $this->required[$key] = ['report' => $report, 'case' => $case, 'side' => $side];
    }

    /** @param list<Record> $records */
    public function supply(string $report, string $case, string $side, array $records): void
    {
        $key = self::measurementKey($report, $case, $side);
        if (!isset($this->required[$key])) {
            throw new GateError('An unregistered record publication was supplied: ' . $key);
        }
        if (isset($this->measurements[$key])) {
            throw new GateError('A record publication was supplied twice: ' . $key);
        }
        if (!array_is_list($records)) {
            throw new GateError('A record publication must preserve its multiset as a list.');
        }
        foreach ($records as $record) {
            if (!\is_array($record) || array_keys($record) !== ['record', 'fields']
                || !\is_string($record['record']) || $record['record'] === '' || !\is_array($record['fields'])
                || array_is_list($record['fields'])) {
                throw new GateError('A publication requires a record key and its complete fields object.');
            }
            foreach (array_keys($record['fields']) as $field) {
                if (!\is_string($field) || $field === '') {
                    throw new GateError('Published record fields must have nonempty string names.');
                }
            }
        }
        $this->measurements[$key] = ['case' => $case, 'side' => $side, 'records' => $records];
    }

    /** @return list<Measurement> */
    public function measurements(string $report): array
    {
        self::assertReport($report);
        $measurements = [];
        foreach ($this->required as $key => $publication) {
            if ($publication['report'] !== $report) {
                continue;
            }
            if (!isset($this->measurements[$key])) {
                throw new GateError('A required record publication was not supplied: ' . $key);
            }
            $measurements[] = $this->measurements[$key];
        }
        if ($measurements === [] && $this->changes($report) !== []) {
            throw new GateError('No record publications were registered for declared fields of ' . $report);
        }
        return $measurements;
    }

    private static function measurementKey(string $report, string $case, string $side): string
    {
        self::assertReport($report);
        if ($case === '' || $case === '*' || !\in_array($side, ['candidate', 'reference'], true)) {
            throw new GateError('A record publication requires a concrete case and candidate or reference side.');
        }
        return $report . "\0" . $case . "\0" . $side;
    }

    private static function assertReport(string $report): void
    {
        if (!\in_array($report, self::REPORTS, true)) {
            throw new GateError('Unknown field report: ' . $report);
        }
    }
}
