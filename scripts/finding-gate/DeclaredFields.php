<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * Fields a step added to or dropped from every published finding, which makes
 * the compared tuple a property of each side rather than one list.
 *
 * The candidate's tuple is the tracked one; the reference's is that tuple
 * minus the fields added here plus the fields dropped here. For an added field
 * the derived table (`declared-fields.derived.tsv`) names every record that
 * carries it and the value it carries there, as a JSON spelling. That table
 * witnesses that the field appears where and as it did when it was measured —
 * reproducibility and reach — and not that its values are right, which the
 * step's own tests have to show. A derived row naming a field no row adds is
 * refused at load; a row whose field neither tuple changed is stale.
 */
final class DeclaredFields
{
    public const array COLUMNS = ['change', 'field', 'reason'];

    public const array DERIVED_COLUMNS = ['field', 'case', 'record', 'value'];

    public const string INDEX = 'declared-fields.tsv';

    public const string DERIVED = 'declared-fields.derived.tsv';

    public const string ADDED = 'added';

    public const string REMOVED = 'removed';

    /** @var array<string, true> */
    private array $credited = [];

    /**
     * @param array<string, string> $changes field => added|removed
     * @param list<array{field: string, case: string, record: string, value: string}> $derived
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

            if (isset($changes[$row['field']])) {
                throw new GateError(\sprintf('%s row %d declares the field "%s" a second time.', self::INDEX, $index + 1, $row['field']));
            }

            $changes[$row['field']] = $row['change'];
        }

        $derived = [];
        $seen = [];

        foreach (DeclarationTable::rows($root, self::DERIVED, self::DERIVED_COLUMNS) as $index => $row) {
            if (($changes[$row['field']] ?? null) !== self::ADDED) {
                throw new GateError(\sprintf(
                    '%s row %d measures the field "%s", which no row of %s adds.',
                    self::DERIVED,
                    $index + 1,
                    $row['field'],
                    self::INDEX,
                ));
            }

            DeclarationTable::once(
                self::DERIVED,
                $index + 1,
                $row['field'] . "\0" . $row['case'] . "\0" . $row['record'],
                \sprintf('the field "%s" of one record of %s', $row['field'], $row['case']),
                $seen,
            );

            $derived[] = ['field' => $row['field'], 'case' => $row['case'], 'record' => $row['record'], 'value' => $row['value']];
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
    public function referenceFields(array $candidateFields): array
    {
        $fields = [];

        foreach ($candidateFields as $field) {
            if (($this->changes[$field] ?? null) !== self::ADDED) {
                $fields[] = $field;
            }
        }

        foreach ($this->changes as $field => $change) {
            if ($change === self::REMOVED && !\in_array($field, $fields, true)) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /** @return array<string, string> field => added|removed */
    public function changes(): array
    {
        return $this->changes;
    }

    /** @return list<array{field: string, case: string, record: string, value: string}> */
    public function derived(): array
    {
        return $this->derived;
    }

    public function derivedText(): string
    {
        return $this->derivedText;
    }

    /** Records that the two sides' tuples do differ by this field as declared. */
    public function credit(string $field): void
    {
        $this->credited[$field] = true;
    }

    /** @return list<array{scope: string, detail: string}> */
    public function stale(): array
    {
        $stale = [];

        foreach ($this->changes as $field => $change) {
            if (!isset($this->credited[$field])) {
                $stale[] = ['scope' => self::INDEX, 'detail' => \sprintf('The %s field "%s"', $change, $field)];
            }
        }

        return $stale;
    }
}
