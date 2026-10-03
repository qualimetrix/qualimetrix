<?php

declare(strict_types=1);

namespace QmxFindingGate;

/** The complete ranked publisher schema, with one physical/comparative boundary. */
final readonly class RankingSchema
{
    public const array PROJECTION = ['file', 'line', 'symbol', 'rule', 'severity', 'message', 'recommendation'];
    public const array VALUES = ['impactScore', 'coupling.class-rank'];
    public const array FIELDS = ['rank', 'file', 'line', 'symbol', 'rule', 'severity', 'message', 'recommendation', 'impactScore', 'coupling.class-rank', 'debtMinutes'];

    /** @param list<string> $fields */
    private function __construct(public array $fields) {}

    public static function derive(string $treeRoot): self
    {
        $source = Fs::read($treeRoot . '/src/Reporting/Formatter/Json/JsonFormatter.php');
        $start = strpos($source, 'private function formatTopIssues(');
        if ($start === false) {
            throw new GateError('The ranked publisher no longer declares formatTopIssues().');
        }
        $end = strpos($source, "\n    }", $start);
        if ($end === false) {
            throw new GateError('The ranked publisher method has no recognized boundary.');
        }
        $body = substr($source, $start, $end - $start);
        if (substr_count($body, '$result[] = [') !== 1
            || preg_match('~\\$result\\[\\] = \\[\\n(.*?)\\n {12}\\];~s', $body, $literal) !== 1) {
            throw new GateError('The ranked publisher requires one recognized array literal.');
        }
        preg_match_all("~^ {16}'([^']+)' => ~m", $literal[1], $keys);
        preg_match_all('~^ {16}\\S~m', $literal[1], $members);
        $fields = $keys[1];
        if ($fields === [] || \count($fields) !== \count($members[0]) || \count(array_unique($fields)) !== \count($fields)) {
            throw new GateError('The ranked publisher has an unknown or duplicate field expression.');
        }
        return new self($fields);
    }

    /** @param array<string,mixed> $comparative
     * @return array<string,mixed>
     */
    public static function physical(array $comparative): array
    {
        return array_filter($comparative, static fn(string $field): bool => !str_starts_with($field, 'ranking.'), \ARRAY_FILTER_USE_KEY);
    }

    /** @param array<string,mixed> $issue
     * @return array<string,mixed>
     */
    public static function values(array $issue): array
    {
        return array_intersect_key($issue, array_flip(self::VALUES));
    }

    /** @param array<string,mixed> $record
     * @param list<string> $fields
     */
    public static function joinKey(array $record, bool $ranked, array $fields = self::FIELDS): string
    {
        $join = [];
        foreach (array_intersect(self::PROJECTION, $fields) as $field) {
            if (!\array_key_exists($field, $record)) {
                throw new GateError('A ranked join requires the published projection field ' . $field . '.');
            }
            $join[$field] = $record[$field];
        }
        $debt = $ranked ? 'debtMinutes' : 'techDebtMinutes';
        if (\in_array('debtMinutes', $fields, true) && !\array_key_exists($debt, $record)) {
            throw new GateError('A ranked join requires its published debt.');
        }
        if (\in_array('debtMinutes', $fields, true)) {
            $join['debtMinutes'] = $record[$debt];
        }
        return DeclaredRecords::canonical($join);
    }

    /** @param array<string,mixed>|list<mixed> $document
     * @param list<string> $fields
     *
     * @return list<array<string,mixed>>
     */
    public static function records(array $document, array $fields): array
    {
        $records = $document['topIssues'] ?? null;
        if (!\is_array($records) || !array_is_list($records)) {
            throw new GateError('A ranking requires its observed topIssues list.');
        }
        $expected = $fields;
        sort($expected);
        $previous = \INF;
        foreach ($records as $index => $record) {
            if (!\is_array($record) || array_is_list($record)) {
                throw new GateError('A ranked issue must be a complete object.');
            }
            $actual = array_keys($record);
            sort($actual);
            if ($actual !== $expected) {
                throw new GateError('The ranked issue fields disagree with the complete publisher schema.');
            }
            if (\array_key_exists('rank', $record) && $record['rank'] !== $index + 1) {
                throw new GateError('The ranked positions must be consecutive from one.');
            }
            foreach (self::VALUES as $field) {
                if (!\array_key_exists($field, $record)) {
                    continue;
                }
                $value = $record[$field];
                if ($field === 'coupling.class-rank' && $value === null) {
                    continue;
                }
                if ((!\is_int($value) && !\is_float($value)) || !is_finite((float) $value)) {
                    throw new GateError('A published ranking value must be finite and numeric.');
                }
            }
            if (isset($record['impactScore'])) {
                if ($record['impactScore'] > $previous) {
                    throw new GateError('The published ranked score increases down the list.');
                }
                $previous = $record['impactScore'];
            }
        }
        /** @var list<array<string,mixed>> $records */
        return $records;
    }
}
