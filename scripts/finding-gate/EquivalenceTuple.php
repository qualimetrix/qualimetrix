<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * The finding fields the gate compares, derived from the code that publishes
 * them rather than typed into a document.
 *
 * A hand-written tuple is how `recommendation`, `techDebtMinutes`,
 * `acceptedLevel`, `edge` and `occurrence` once sat outside the comparison while
 * the plan claimed everything published was guarded. A field that exists but is
 * not compared must be impossible, so the derivation and the tracked file are
 * checked against each other on every run.
 */
final class EquivalenceTuple
{
    public const COLUMNS = ['field', 'source'];

    private const SOURCE_FILE = 'src/Reporting/Formatter/Json/JsonViolationSection.php';

    private const SOURCE_METHOD = 'formatViolation';

    /** @param list<string> $fields */
    private function __construct(public readonly array $fields) {}

    public static function load(string $path): self
    {
        $fields = [];

        foreach (Tsv::rows($path, self::COLUMNS) as $row) {
            $fields[] = $row['field'];
        }

        if ($fields === []) {
            throw new GateError(\sprintf('%s lists no field.', $path));
        }

        return new self($fields);
    }

    /** Reads the published key set out of the publishing method's own array literal. */
    public static function derive(string $treeRoot): self
    {
        $source = Fs::read($treeRoot . '/' . self::SOURCE_FILE);
        $start = strpos($source, 'private function ' . self::SOURCE_METHOD);

        if ($start === false) {
            throw new GateError(\sprintf('%s no longer declares %s().', self::SOURCE_FILE, self::SOURCE_METHOD));
        }

        $body = substr($source, $start);
        $returnAt = strpos($body, "        return [\n");
        $endAt = strpos($body, "\n        ];");

        if ($returnAt === false || $endAt === false || $endAt < $returnAt) {
            throw new GateError(\sprintf(
                '%s::%s() no longer returns a single array literal; the derivation must be taught the new shape'
                . ' rather than the tuple being written by hand.',
                self::SOURCE_FILE,
                self::SOURCE_METHOD,
            ));
        }

        $literal = substr($body, $returnAt, $endAt - $returnAt);
        preg_match_all("~^ {12}'([^']+)' => ~m", $literal, $matches);
        $fields = $matches[1];

        if ($fields === [] || \count($fields) !== \count(array_unique($fields))) {
            throw new GateError(\sprintf('Cannot derive a unique field list from %s.', self::SOURCE_FILE));
        }

        return new self($fields);
    }

    public function render(): string
    {
        return Tsv::render(
            self::COLUMNS,
            array_map(
                static fn(string $field): array => [$field, self::SOURCE_FILE . '::' . self::SOURCE_METHOD],
                $this->fields,
            ),
        );
    }

    public function equals(self $other): bool
    {
        return $this->fields === $other->fields;
    }
}
