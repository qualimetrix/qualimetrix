<?php

declare(strict_types=1);

namespace QmxFindingGate;

use Symfony\Component\Yaml\Yaml;
use Throwable;

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
        $key = $document . "\0" . $from;
        if (!isset($this->rows[$key])) {
            throw new GateError('An unknown structural map row cannot be credited.');
        }
        $this->credited[$key] = true;
    }

    /** @return array<string,int> */
    public function firedRows(): array
    {
        return array_fill_keys(array_keys($this->credited), 1);
    }

    /** @param array<string,int> $hits */
    public function creditRowsFiredElsewhere(array $hits): void
    {
        foreach ($hits as $key => $count) {
            if (!isset($this->rows[$key]) || !\is_int($count) || $count < 1) {
                throw new GateError('A worker credited an unknown or invalid structural map row: ' . $key);
            }
            $this->credited[$key] = true;
        }
    }

    public function reverseDocument(string $document, string $text): string
    {
        $rows = array_filter($this->rows, static fn(array $row): bool => $row['document'] === $document);
        if ($rows === []) {
            return $text;
        }
        if (!class_exists(Yaml::class)) {
            require_once \dirname(__DIR__, 2) . '/vendor/autoload.php';
        }
        try {
            $value = Yaml::parse($text);
        } catch (Throwable $error) {
            throw new GateError('A structural YAML input could not be parsed: ' . $error->getMessage());
        }
        $translated = [];
        foreach ($rows as $row) {
            $source = explode('.', $row['to']);
            if (!self::contains($value, $source)) {
                continue;
            }
            foreach ($translated as $other) {
                foreach (['from', 'to'] as $column) {
                    if (str_starts_with($row[$column] . '.', $other[$column] . '.')
                        || str_starts_with($other[$column] . '.', $row[$column] . '.')) {
                        throw new GateError('Structural input translations need disjoint source and destination paths.');
                    }
                }
            }
            $translated[] = $row;
        }
        if ($translated === []) {
            return $text;
        }
        $items = [];
        foreach ($translated as $row) {
            $items[] = self::remove($value, explode('.', $row['to']));
        }
        foreach ($translated as $index => $row) {
            $target = explode('.', $row['from']);
            if (self::contains($value, $target)) {
                throw new GateError('A structural input translation collides with its destination: ' . $row['from']);
            }
            self::insert($value, $target, $items[$index]);
        }
        $result = Yaml::dump($value, 20, 2);
        if (Yaml::parse($result) !== $value) {
            throw new GateError('A structural input translation changed an undeclared YAML value.');
        }
        foreach ($translated as $row) {
            $this->credit($row['document'], $row['from']);
        }
        return $result;
    }

    /** @param list<string> $path */
    private static function contains(mixed $value, array $path): bool
    {
        foreach ($path as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return false;
            }
            $value = $value[$key];
        }
        return true;
    }

    /** @param non-empty-list<string> $path */
    private static function remove(mixed &$value, array $path): mixed
    {
        $key = array_shift($path);
        if ($path === []) {
            $removed = $value[$key];
            unset($value[$key]);
            return $removed;
        }
        $removed = self::remove($value[$key], $path);
        if ($value[$key] === []) {
            unset($value[$key]);
        }
        return $removed;
    }

    /** @param non-empty-list<string> $path */
    private static function insert(mixed &$value, array $path, mixed $item): void
    {
        if (!\is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new GateError('A structural input translation needs mapping parents.');
        }
        $key = array_shift($path);
        if ($path === []) {
            $value[$key] = $item;
            return;
        }
        if (!\array_key_exists($key, $value)) {
            $value[$key] = [];
        }
        self::insert($value[$key], $path, $item);
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
