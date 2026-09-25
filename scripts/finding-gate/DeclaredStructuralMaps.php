<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * Where a step moved a key of a configuration document, for the reference to be
 * handed its case inputs in the schema it knows.
 *
 * A rename map rewrites text; a key that moved to another place in the tree, or
 * whose value changed form, is not a rename of any token. A row names the kind
 * of document, the key's path in the old schema, its path in the new one and
 * the form its value takes there, and the gate applies it mechanically to every
 * case input of that kind before the reference reads it. The reference only
 * ever receives a translation: a row cannot hand it anything the candidate's
 * input does not say. A row that moved nothing in any case input is stale.
 */
final class DeclaredStructuralMaps
{
    public const array COLUMNS = ['document', 'from', 'to', 'shape', 'reason'];

    public const string INDEX = 'declared-structural-maps.tsv';

    /** The case inputs a structural map applies to, by the option that hands them to the product. */
    public const array DOCUMENTS = ['config' => '--config', 'preset' => '--preset'];

    /** The value moves as it is. The forms a value can change into are added with the translation that applies them. */
    public const string SHAPE_SAME = 'same';

    public const array SHAPES = [self::SHAPE_SAME];

    private const string PATH = '~^[A-Za-z_][\w-]*(\.[A-Za-z_][\w-]*)*$~';

    /** @var array<string, true> */
    private array $credited = [];

    /** @param array<string, array{document: string, from: string, to: string, shape: string, reason: string}> $rows */
    private function __construct(private readonly array $rows) {}

    public static function load(string $root): self
    {
        $rows = [];

        foreach (DeclarationTable::rows($root, self::INDEX, self::COLUMNS) as $index => $row) {
            $number = $index + 1;
            DeclarationTable::oneOf(self::INDEX, $number, 'document', $row['document'], array_keys(self::DOCUMENTS));
            DeclarationTable::oneOf(self::INDEX, $number, 'shape', $row['shape'], self::SHAPES);

            foreach (['from', 'to'] as $column) {
                if (preg_match(self::PATH, $row[$column]) !== 1) {
                    throw new GateError(\sprintf(
                        '%s row %d: "%s" is "%s", which is not a dotted key path.',
                        self::INDEX,
                        $number,
                        $column,
                        $row[$column],
                    ));
                }
            }

            if ($row['from'] === $row['to'] && $row['shape'] === self::SHAPE_SAME) {
                throw new GateError(\sprintf('%s row %d moves "%s" onto itself, which is not a move.', self::INDEX, $number, $row['from']));
            }

            $key = $row['document'] . "\0" . $row['from'];

            if (isset($rows[$key])) {
                throw new GateError(\sprintf('%s row %d moves %s "%s" a second time.', self::INDEX, $number, $row['document'], $row['from']));
            }

            $rows[$key] = [
                'document' => $row['document'],
                'from' => $row['from'],
                'to' => $row['to'],
                'shape' => $row['shape'],
                'reason' => $row['reason'],
            ];
        }

        return new self($rows);
    }

    public function count(): int
    {
        return \count($this->rows);
    }

    /** @return list<array{document: string, from: string, to: string, shape: string, reason: string}> */
    public function rows(): array
    {
        return array_values($this->rows);
    }

    /** Records that this row moved a key of at least one case input. */
    public function credit(string $document, string $from): void
    {
        $this->credited[$document . "\0" . $from] = true;
    }

    /** @return list<array{scope: string, detail: string}> */
    public function stale(): array
    {
        $stale = [];

        foreach ($this->rows as $key => $row) {
            if (!isset($this->credited[$key])) {
                $stale[] = [
                    'scope' => self::INDEX,
                    'detail' => \sprintf('The move of %s "%s" to "%s"', $row['document'], $row['from'], $row['to']),
                ];
            }
        }

        return $stale;
    }
}
