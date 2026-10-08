<?php

declare(strict_types=1);

namespace QmxFindingGate;

use JsonException;

/**
 * Fields added to or removed from one named report publication, with measured values.
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
 * @phpstan-type Measurement array{case:string,view:string,side:string,records:list<Record>}
 */
final class DeclaredFields
{
    public const array COLUMNS = ['change', 'report', 'view', 'field', 'reason'];

    public const array DERIVED_COLUMNS = ['report', 'view', 'field', 'case', 'record', 'value'];

    public const array REPORTS = ['json', 'metrics', 'directives', 'json-document'];

    public const string INDEX = 'declared-fields.tsv';

    public const string DERIVED = 'declared-fields.derived.tsv';

    public const string ADDED = 'added';

    public const string REMOVED = 'removed';

    /** @var array<string, true> */
    private array $credited = [];

    /** @var array<string, array{report:string,case:string,view:string,side:string}> */
    private array $required = [];

    /** @var array<string, Measurement> */
    private array $measurements = [];

    /**
     * @param array<string, string> $changes report, view and field => added|removed
     * @param list<array{report:string,view:string,field:string,case:string,record:string,value:string}> $derived
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

            self::assertView($row['report'], $row['view']);
            $key = $row['report'] . "\0" . $row['view'] . "\0" . $row['field'];

            if (isset($changes[$key])) {
                throw new GateError(\sprintf('%s row %d declares the field "%s" a second time.', self::INDEX, $index + 1, $row['field']));
            }

            $changes[$key] = $row['change'];
        }

        $derived = [];

        foreach (DeclarationTable::rows($root, self::DERIVED, self::DERIVED_COLUMNS) as $index => $row) {
            self::assertView($row['report'], $row['view']);
            $key = $row['report'] . "\0" . $row['view'] . "\0" . $row['field'];

            if (($changes[$key] ?? null) !== self::ADDED) {
                throw new GateError(\sprintf(
                    '%s row %d measures the field "%s", which no row of %s adds.',
                    self::DERIVED,
                    $index + 1,
                    $row['field'],
                    self::INDEX,
                ));
            }

            try {
                $value = json_decode($row['value'], false, 512, \JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                throw new GateError(self::DERIVED . ' has a non-JSON field value: ' . $error->getMessage());
            }
            if (json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR) !== $row['value']) {
                throw new GateError(self::DERIVED . ' requires canonical JSON field values.');
            }
            $derived[] = ['report' => $row['report'], 'view' => $row['view'], 'field' => $row['field'], 'case' => $row['case'], 'record' => $row['record'], 'value' => $row['value']];
        }

        $path = $root . '/' . self::DERIVED;

        return new self($changes, $derived, is_file($path) ? Fs::read($path) : '');
    }

    public function trialCopy(): self
    {
        $copy = clone $this;
        $copy->credited = [];
        return $copy;
    }

    public function registerRequired(Corpus $corpus, CapturePlan $plan): void
    {
        $cases = [];
        foreach ($corpus->cases as $case) {
            $cases['case:' . $case->id] = $case;
        }
        foreach (self::REPORTS as $report) {
            foreach ($this->views($report) as $view) {
                if ($report === 'json' && $view === 'ranking') {
                    foreach ($corpus->cases as $case) {
                        foreach (['candidate', 'reference'] as $side) {
                            if (!CaseOutcome::applies(CaseOutcome::CHECK_RECORDS, CaseOutcome::of($case, $side))) {
                                continue;
                            }
                            foreach ($plan->rankingInvocations() as $source) {
                                $key = Surfaces::key($source['scope'], $source['surface']);
                                if ($source['scope'] === 'case:' . $case->id && $plan->requiredOn($key, $side)) {
                                    $this->requireMeasurements('json', $case->id, 'ranking', $side);
                                    break;
                                }
                            }
                        }
                    }
                    continue;
                }
                foreach ($plan->invocations() as $invocation) {
                    if (($invocation['surface'] !== $view && ($report !== 'json-document' || $invocation['outputFileKind'] !== $view)) || !str_starts_with($invocation['scope'], 'case:')) {
                        continue;
                    }
                    $key = Surfaces::key($invocation['scope'], $invocation['surface']);
                    foreach (['candidate', 'reference'] as $side) {
                        if ($plan->requiredOn($key, $side)
                            && CaseOutcome::applies(CaseOutcome::CHECK_RECORDS, CaseOutcome::of($cases[$invocation['scope']], $side))) {
                            $this->requireMeasurements($report, substr($invocation['scope'], 5), $view, $side);
                        }
                    }
                }
            }
        }
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
    public function referenceFields(string $report, string $view, array $candidateFields): array
    {
        $changes = $this->changes($report, $view);
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
    public function changes(string $report, string $view): array
    {
        self::assertView($report, $view);
        $changes = [];
        foreach ($this->changes as $key => $change) {
            [$owner, $publication, $field] = explode("\0", $key, 3);
            if ($owner === $report && $publication === $view) {
                $changes[$field] = $change;
            }
        }
        return $changes;
    }

    /** @return list<array{report:string,view:string,field:string,case:string,record:string,value:string}> */
    public function derived(string $report, string $view): array
    {
        self::assertView($report, $view);
        return array_values(array_filter($this->derived, static fn(array $row): bool => $row['report'] === $report && $row['view'] === $view));
    }

    public function derivedText(): string
    {
        return $this->derivedText;
    }

    /** Records that the two sides' tuples do differ by this field as declared. */
    public function credit(string $report, string $view, string $field): void
    {
        self::assertView($report, $view);
        $key = $report . "\0" . $view . "\0" . $field;
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
            [$report, $view, $field] = explode("\0", $key, 3);
            if (!isset($this->credited[$key])) {
                $stale[] = ['scope' => self::INDEX, 'detail' => \sprintf('The %s %s/%s field "%s"', $change, $report, $view, $field)];
            }
        }

        return $stale;
    }

    public function requireMeasurements(string $report, string $case, string $view, string $side): void
    {
        $key = self::measurementKey($report, $case, $view, $side);
        if (isset($this->required[$key])) {
            throw new GateError('A record publication was registered twice: ' . $key);
        }
        $this->required[$key] = ['report' => $report, 'case' => $case, 'view' => $view, 'side' => $side];
    }

    /** @return list<array{report:string,case:string,view:string,side:string,supplied:bool}> */
    public function requiredPublications(string $case): array
    {
        $references = [];
        foreach ($this->required as $key => $publication) {
            if ($publication['case'] !== $case || $this->changes($publication['report'], $publication['view']) === []) {
                continue;
            }
            $references[] = [...$publication, 'supplied' => isset($this->measurements[$key])];
        }
        return $references;
    }

    /** @param list<Record> $records */
    public function supply(string $report, string $case, string $view, string $side, array $records): void
    {
        $key = self::measurementKey($report, $case, $view, $side);
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
        $this->measurements[$key] = ['case' => $case, 'view' => $view, 'side' => $side, 'records' => $records];
    }

    /** @return list<Measurement> */
    public function measurements(string $report, ?PublicationForms $forms = null): array
    {
        self::assertReport($report);
        $measurements = [];
        $outsideViews = [];
        $activeViews = [];
        foreach ($this->required as $key => $publication) {
            if ($publication['report'] !== $report) {
                continue;
            }
            if ($forms?->schemaPair($publication['case'], $publication['view']) === false) {
                $outsideViews[$publication['view']] = true;
                continue;
            }
            $activeViews[$publication['view']] = true;
            if (!isset($this->measurements[$key])) {
                throw new GateError('A required record publication was not supplied: ' . $key);
            }
            $measurements[] = $this->measurements[$key];
        }
        foreach ($this->views($report) as $view) {
            if (isset($outsideViews[$view]) && !isset($activeViews[$view])) {
                continue;
            }
            if (array_filter($measurements, static fn(array $measurement): bool => $measurement['view'] === $view) === []) {
                throw new GateError('No record publications were registered for declared fields of ' . $report . '/' . $view);
            }
        }
        return $measurements;
    }

    /** @return list<string> */
    public function views(string $report): array
    {
        self::assertReport($report);
        $views = [];
        foreach (array_keys($this->changes) as $key) {
            [$owner, $view] = explode("\0", $key, 3);
            if ($owner === $report && !\in_array($view, $views, true)) {
                $views[] = $view;
            }
        }
        return $views;
    }

    private static function assertView(string $report, string $view): void
    {
        self::assertReport($report);
        ReportViews::assertFieldsView($report, $view);
    }

    private static function measurementKey(string $report, string $case, string $view, string $side): string
    {
        self::assertView($report, $view);
        if ($case === '' || $case === '*' || !\in_array($side, ['candidate', 'reference'], true)) {
            throw new GateError('A record publication requires a concrete case and candidate or reference side.');
        }
        return $report . "\0" . $case . "\0" . $view . "\0" . $side;
    }

    private static function assertReport(string $report): void
    {
        if (!\in_array($report, self::REPORTS, true)) {
            throw new GateError('Unknown field report: ' . $report);
        }
    }
}
