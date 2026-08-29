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
 *
 * The tracked file also names *where* each field came from, and that column is
 * an assertion about the tree, not a caption: a renamed publisher left all
 * seventeen rows pointing at a deleted file for a whole step, because nothing
 * read the column. Loading now resolves every `source` against the tree — the
 * file must exist and must still declare the named method — so a stale
 * provenance row refuses to load instead of documenting a file that is gone.
 */
final class EquivalenceTuple
{
    public const COLUMNS = ['field', 'source'];

    public const TRACKED_PATH = 'finding-gate/equivalence-tuple.tsv';

    private const SOURCE_FILE = 'src/Reporting/Formatter/Json/JsonFindingSection.php';

    private const SOURCE_METHOD = 'formatFinding';

    /**
     * @param list<string> $fields
     * @param list<string> $sources `<file>::<method>` per field, in the same order
     */
    private function __construct(public readonly array $fields, public readonly array $sources) {}

    public static function load(string $treeRoot): self
    {
        $path = $treeRoot . '/' . self::TRACKED_PATH;
        $fields = [];
        $sources = [];

        foreach (Tsv::rows($path, self::COLUMNS) as $row) {
            self::assertSourceResolves($treeRoot, $path, $row['field'], $row['source']);
            $fields[] = $row['field'];
            $sources[] = $row['source'];
        }

        if ($fields === []) {
            throw new GateError(\sprintf('%s lists no field.', $path));
        }

        return new self($fields, $sources);
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

        return new self($fields, array_fill(0, \count($fields), self::source()));
    }

    public static function source(): string
    {
        return self::SOURCE_FILE . '::' . self::SOURCE_METHOD;
    }

    public function render(): string
    {
        return Tsv::render(
            self::COLUMNS,
            array_map(
                static fn(string $field): array => [$field, self::source()],
                $this->fields,
            ),
        );
    }

    public function equals(self $other): bool
    {
        return $this->fields === $other->fields && $this->sources === $other->sources;
    }

    /**
     * A `source` cell is a claim that this file still declares this method.
     * Anything else — a malformed cell, a deleted file, a renamed method — is a
     * refusal, because the alternative is a tracked artifact that describes the
     * tree as it was before some rename and says so to nobody.
     */
    private static function assertSourceResolves(string $treeRoot, string $path, string $field, string $source): void
    {
        $parts = explode('::', $source);

        if (\count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new GateError(\sprintf(
                '%s names the source of field "%s" as "%s"; expected "<file>::<method>".',
                $path,
                $field,
                $source,
            ));
        }

        [$file, $method] = $parts;
        $absolute = $treeRoot . '/' . $file;

        if (!is_file($absolute)) {
            throw new GateError(\sprintf(
                '%s says field "%s" is published by %s, but that file does not exist. Re-derive the tuple with'
                . ' --derive-tuple after a rename instead of leaving the provenance column pointing at a deleted file.',
                $path,
                $field,
                $file,
            ));
        }

        if (!str_contains(Fs::read($absolute), 'function ' . $method . '(')) {
            throw new GateError(\sprintf(
                '%s says field "%s" is published by %s::%s(), but %s declares no such method. Re-derive the tuple'
                . ' with --derive-tuple.',
                $path,
                $field,
                $file,
                $method,
                $file,
            ));
        }
    }
}
