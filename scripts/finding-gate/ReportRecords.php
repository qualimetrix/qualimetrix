<?php

declare(strict_types=1);

namespace QmxFindingGate;

use JsonException;
use stdClass;

/** Published records, their complete schemas, and edits confined to their byte spans. */
final class ReportRecords
{
    public const array IDENTITY_FIELDS = ['channel', 'subject', 'occurrence', 'edge'];

    public const array ANALYSIS_DIAGNOSTICS = ['analysis.parse', 'analysis.processing', 'analysis.directory-symlink', 'analysis.not-regular-file', 'analysis.unreadable-directory'];

    public const array SCHEMAS = [
        'json' => ['file', 'line', 'subject', 'symbol', 'channel', 'occurrence', 'edge', 'namespace', 'namespaces', 'rule', 'code', 'severity', 'message', 'recommendation', 'metricValue', 'threshold', 'techDebtMinutes', 'acceptedLevel', 'baselineVerdict', 'baselineReason'],
        'suppressed' => ['mechanism', 'suppressor', 'rule', 'channel', 'subject', 'occurrence', 'edge', 'file', 'line', 'symbol', 'severity', 'message', 'recommendation'],
        'metrics' => ['type', 'name', 'file', 'line', 'metrics'],
        'directives' => ['file', 'line', 'form', 'target', 'effect', 'reason', 'masked_by', 'boundary_observable', 'refusals'],
    ];
    public const array ARRAYS = ['json' => 'violations', 'suppressed' => 'suppressed', 'metrics' => 'symbols', 'directives' => 'directives'];

    /**
     * @param list<array<string,mixed>> $records
     *
     * @return list<array{path:list<int|string>,fields:array<string,mixed>}>
     */
    public static function baselineEntries(string $text, array $records): array
    {
        $document = self::baselineDocument($text);
        if (!\in_array($document['version'] ?? null, [13, 14], true) || !\is_array($document['entries'] ?? null)) {
            throw new GateError('The baseline publication has no supported entries schema.');
        }
        $groups = [];
        foreach ($records as $record) {
            $groups[self::identity('json', $record)][] = $record;
        }
        $projected = [];
        $seen = [];
        foreach ($document['entries'] as $subject => $entries) {
            if (!\is_string($subject) || !\is_array($entries) || !array_is_list($entries)) {
                throw new GateError('A baseline subject requires its observed entry list.');
            }
            foreach ($entries as $index => $entry) {
                if (!\is_array($entry) || !\is_string($entry['channel'] ?? null)) {
                    throw new GateError('A baseline entry requires its exact channel.');
                }
                $identity = ['channel' => $entry['channel'], 'subject' => $subject, 'occurrence' => $entry['occurrence'] ?? null, 'edge' => $entry['edge'] ?? null];
                $key = self::identity('json', $identity);
                $group = $groups[$key] ?? [];
                if ($group === [] || isset($seen[$key])) {
                    throw new GateError('A baseline entry has no unique complete source record group.');
                }
                $seen[$key] = true;
                $fields = array_keys($entry);
                $allowed = ['channel', 'occurrence', 'edge', 'magnitudes', 'count'];
                if (array_diff($fields, $allowed) !== []) {
                    throw new GateError('A generated baseline entry publishes an unknown member.');
                }
                if (\array_key_exists('magnitudes', $entry)) {
                    if (\array_key_exists('count', $entry) || !\is_array($entry['magnitudes']) || !array_is_list($entry['magnitudes'])) {
                        throw new GateError('A magnitude baseline entry must publish only its magnitude list.');
                    }
                    $expected = [];
                    foreach ($group as $record) {
                        if (!\is_int($record['metricValue']) && !\is_float($record['metricValue'])) {
                            throw new GateError('A magnitude entry has a source finding without a measured magnitude.');
                        }
                        $expected[] = round((float) $record['metricValue'], 6);
                    }
                    $actual = [];
                    foreach ($entry['magnitudes'] as $value) {
                        if (!\is_int($value) && !\is_float($value)) {
                            throw new GateError('A baseline magnitude is not numeric.');
                        }
                        $actual[] = (float) $value;
                    }
                    sort($expected);
                    if ($actual !== $expected) {
                        throw new GateError('Baseline magnitudes differ from the complete source record multiset.');
                    }
                } elseif (!\is_int($entry['count'] ?? null) || $entry['count'] !== \count($group)) {
                    throw new GateError('The baseline count differs from its complete source record group.');
                }
                $projected[] = ['path' => ['entries', $subject, $index], 'fields' => $entry];
            }
        }
        return $projected;
    }

    /** @return array<string,mixed>|list<mixed> */
    public static function decode(string $text): array
    {
        try {
            $value = json_decode($text, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new GateError('A published record document is not JSON: ' . $error->getMessage());
        }
        if (!\is_array($value)) {
            throw new GateError('A published record document must be an object or list.');
        }
        return $value;
    }

    /** @return array<string,mixed> */
    public static function object(string $text): array
    {
        $object = [];
        foreach (self::decode($text) as $key => $value) {
            if (!\is_string($key)) {
                throw new GateError('A record object requires named string members.');
            }
            $object[$key] = $value;
        }
        return $object;
    }

    /**
     * @param list<string> $fields
     *
     * @return list<array<string,mixed>>
     */
    public static function extract(string $report, string $text, array $fields, bool $optionalSubject = true): array
    {
        $key = self::ARRAYS[$report] ?? throw new GateError('Unknown record report: ' . $report);
        $document = self::decode($text);
        $records = $document[$key] ?? null;
        if (!\is_array($records) || !array_is_list($records)) {
            throw new GateError('A publication requires its observed ' . $key . ' list.');
        }
        foreach ($records as $record) {
            if (!\is_array($record) || array_is_list($record)) {
                throw new GateError('A published record must be a complete object.');
            }
            $actual = array_keys($record);
            $expected = $fields;
            if ($report === 'metrics' && $optionalSubject && !\in_array('subject', $fields, true) && \array_key_exists('subject', $record)) {
                if (!\is_string($record['subject']) || $record['subject'] === '') {
                    throw new GateError('A published metric subject must be a nonempty string.');
                }
                $expected[] = 'subject';
            }
            sort($actual);
            sort($expected);
            if ($actual !== $expected) {
                throw new GateError(\sprintf('The %s record fields differ: expected %s; published %s.', $report, implode(', ', $expected), implode(', ', $actual)));
            }
            if ($report === 'metrics' && (!\is_string($record['type']) || $record['type'] === '' || !\is_string($record['name']) || $record['name'] === '' || !\is_array($record['metrics']) || ($record['metrics'] !== [] && array_is_list($record['metrics'])))) {
                throw new GateError('A metric record requires type, name and a metrics object.');
            }
            if ($report === 'directives' && (!\is_string($record['form']) || !\is_string($record['target']) || !\is_bool($record['boundary_observable']))) {
                throw new GateError('A directive record requires form, target and a boolean boundary observation.');
            }
        }
        /** @var list<array<string,mixed>> $records */
        return $records;
    }

    /** @param array<string,mixed> $record */
    public static function identity(string $report, array $record): string
    {
        $keys = match ($report) {
            'json' => ['channel', 'subject', 'occurrence', 'edge'],
            'suppressed' => ['mechanism', 'suppressor', 'channel', 'subject', 'occurrence', 'edge'],
            'directives' => ['file', 'line', 'form', 'target'],
            default => throw new GateError('Metric records require their pair key.'),
        };
        $identity = [];
        foreach ($keys as $key) {
            if (!\array_key_exists($key, $record)) {
                throw new GateError('A record identity requires published ' . $key);
            }
            $identity[$key] = $key === 'edge' ? self::edge($record[$key]) : $record[$key];
        }
        return DeclaredRecords::canonical($identity);
    }

    /** @return array{target:string,type?:string}|null */
    private static function edge(mixed $edge): ?array
    {
        if ($edge === null) {
            return null;
        }
        if (!\is_array($edge) || array_is_list($edge) || !\is_string($edge['target'] ?? null) || $edge['target'] === ''
            || array_diff(array_keys($edge), ['target', 'type']) !== []) {
            throw new GateError('A record edge requires an exact target and optional dependency type.');
        }
        $identity = ['target' => $edge['target']];
        if (\array_key_exists('type', $edge)) {
            if (!\is_string($edge['type']) || !\in_array($edge['type'], ['extends', 'implements', 'trait_use', 'new', 'static_call', 'static_property_fetch', 'class_const_fetch', 'type_hint', 'catch', 'instanceof', 'attribute', 'property_type', 'constant_type', 'intersection_type', 'union_type'], true)) {
                throw new GateError('A record edge requires a known dependency type.');
            }
            $identity['type'] = $edge['type'];
        }
        return $identity;
    }

    public static function codecOf(string $treeRoot): string
    {
        $sources = array_values(array_unique(EquivalenceTuple::load($treeRoot)->sources));
        return match ($sources) {
            [EquivalenceTuple::source()] => 'current',
            ['src/Reporting/Formatter/Json/JsonFindingSection.php::formatFinding'] => 'legacy',
            default => throw new GateError('The tree has an unsupported finding publisher: ' . implode(', ', $sources)),
        };
    }

    /**
     * Paths retain their original record order and bytes; only a licensed member is replaced or removed.
     *
     * @param array<string,string|null> $edits JSON encoded paths => replacement JSON, or null for deletion
     */
    public static function edit(string $text, array $edits, bool $removeWholeLines = false): string
    {
        self::decode($text);
        $at = 0;
        $spans = [];
        self::scan($text, $at, [], $edits, $spans);
        if ($removeWholeLines) {
            foreach ($spans as &$span) {
                if ($span[2] !== '') {
                    continue;
                }
                $before = strrpos(substr($text, 0, $span[0]), "\n");
                $after = strpos($text, "\n", $span[1]);
                if ($before !== false && $after !== false
                    && trim(substr($text, $before + 1, $span[0] - $before - 1)) === ''
                    && trim(substr($text, $span[1], $after - $span[1])) === '') {
                    $span[0] = $before + 1;
                    $span[1] = $after + 1;
                }
            }
            unset($span);
        }
        usort($spans, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        /** @var list<array{0:int,1:int,2:string}> $merged */
        $merged = [];
        foreach ($spans as $span) {
            $last = \count($merged) - 1;
            if ($last >= 0 && $merged[$last][1] >= $span[0]) {
                if ($merged[$last][2] !== '' || $span[2] !== '') {
                    throw new GateError('Two licensed JSON edits overlap.');
                }
                $merged[$last] = [$merged[$last][0], max($merged[$last][1], $span[1]), $merged[$last][2]];
            } else {
                $merged[] = $span;
            }
        }
        $spans = $merged;
        usort($spans, static fn(array $a, array $b): int => $b[0] <=> $a[0]);
        foreach ($spans as [$start, $end, $replacement]) {
            $text = substr($text, 0, $start) . $replacement . substr($text, $end);
        }
        self::decode($text);
        return $text;
    }

    /** @return list<string> */
    public static function rawRecords(string $text, string $field): array
    {
        $native = self::native($text);
        if (!$native instanceof stdClass || !\is_array($native->{$field} ?? null)) {
            throw new GateError('A raw record comparison requires its observed list: ' . $field);
        }
        foreach ($native->{$field} as $record) {
            if (!$record instanceof stdClass) {
                throw new GateError('A published record must be a complete object.');
            }
        }
        $document = self::decode($text);
        $records = $document[$field] ?? null;
        if (!\is_array($records) || !array_is_list($records)) {
            throw new GateError('A raw record comparison requires its observed list: ' . $field);
        }
        $edits = [];
        foreach (array_keys($records) as $index) {
            $edits[ValueCheck::value([$field, $index])] = '';
        }
        $at = 0;
        $spans = [];
        self::scan($text, $at, [], $edits, $spans);
        usort($spans, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        if (\count($spans) !== \count($records)) {
            throw new GateError('A raw record list has missing or repeated textual members.');
        }
        return array_map(static fn(array $span): string => substr($text, $span[0], $span[1] - $span[0]), $spans);
    }

    /**
     * @param list<string|int> $path
     * @param array<string,string|null> $edits
     * @param list<array{int,int,string}> $spans
     */
    private static function scan(string $text, int &$at, array $path, array $edits, array &$spans): void
    {
        self::space($text, $at);
        $start = $at;
        $kind = $text[$at];
        if ($kind === '{' || $kind === '[') {
            ++$at;
            $index = 0;
            $members = [];
            self::space($text, $at);
            while ($text[$at] !== ($kind === '{' ? '}' : ']')) {
                $memberStart = $at;
                $key = $index;
                if ($kind === '{') {
                    $keyStart = $at;
                    self::token($text, $at);
                    $key = json_decode(substr($text, $keyStart, $at - $keyStart), true, 512, \JSON_THROW_ON_ERROR);
                    self::space($text, $at);
                    ++$at;
                }
                $memberPath = [...$path, $key];
                $encoded = json_encode($memberPath, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
                self::scan($text, $at, $memberPath, $edits, $spans);
                $end = $at;
                self::space($text, $at);
                $comma = $at;
                if ($text[$at] === ',') {
                    ++$at;
                }
                $members[] = [$memberStart, $end, $comma, $encoded];
                self::space($text, $at);
                ++$index;
            }
            ++$at;
            for ($i = 0, $count = \count($members); $i < $count;) {
                if (!\array_key_exists($members[$i][3], $edits) || $edits[$members[$i][3]] !== null) {
                    ++$i;
                    continue;
                }
                $first = $i;
                while ($i < $count && \array_key_exists($members[$i][3], $edits) && $edits[$members[$i][3]] === null) {
                    ++$i;
                }
                $last = $i - 1;
                if ($i < $count) {
                    $spans[] = [$members[$first][0], $members[$last][2] + 1, ''];
                } elseif ($first > 0) {
                    $spans[] = [$members[$first - 1][2], $members[$last][1], ''];
                } else {
                    $spans[] = [$members[$first][0], $members[$last][1], ''];
                }
            }
        } else {
            self::token($text, $at);
        }
        $encoded = json_encode($path, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        if (isset($edits[$encoded])) {
            $spans[] = [$start, $at, $edits[$encoded]];
        }
    }

    private static function native(string $text): mixed
    {
        try {
            return json_decode($text, false, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new GateError('A published record document is not JSON: ' . $error->getMessage());
        }
    }

    /** @return array<string,mixed> */
    public static function baselineDocument(string $text): array
    {
        $native = self::native($text);
        if (!$native instanceof stdClass || !\in_array($native->version ?? null, [13, 14], true)
            || !($native->entries ?? null) instanceof stdClass) {
            throw new GateError('The baseline publication has no supported entries schema.');
        }
        foreach (get_object_vars($native->entries) as $subject => $entries) {
            if (!\is_string($subject) || $subject === '' || !\is_array($entries)) {
                throw new GateError('A baseline subject requires its observed entry list.');
            }
            foreach ($entries as $entry) {
                if (!$entry instanceof stdClass || !\is_string($entry->channel ?? null) || $entry->channel === ''
                    || array_diff(array_keys(get_object_vars($entry)), ['channel', 'occurrence', 'edge', 'magnitudes', 'count']) !== []) {
                    throw new GateError('A baseline entry requires its exact channel and native members.');
                }
                if (property_exists($entry, 'occurrence') && $entry->occurrence !== null
                    && (!\is_string($entry->occurrence) || $entry->occurrence === '')) {
                    throw new GateError('A baseline occurrence requires its native identity.');
                }
                if (property_exists($entry, 'edge') && $entry->edge !== null) {
                    if (!$entry->edge instanceof stdClass) {
                        throw new GateError('A baseline edge requires its native object.');
                    }
                    self::edge(self::decode(json_encode($entry->edge, \JSON_THROW_ON_ERROR)));
                }
                if (property_exists($entry, 'magnitudes')) {
                    if (property_exists($entry, 'count') || !\is_array($entry->magnitudes)) {
                        throw new GateError('A magnitude baseline entry must publish only its magnitude list.');
                    }
                    foreach ($entry->magnitudes as $magnitude) {
                        if (!\is_int($magnitude) && !\is_float($magnitude)) {
                            throw new GateError('A baseline magnitude is not numeric.');
                        }
                    }
                } elseif (!\is_int($entry->count ?? null) || $entry->count < 1) {
                    throw new GateError('A baseline entry requires its positive count.');
                }
            }
        }
        return self::object($text);
    }

    private static function space(string $text, int &$at): void
    {
        while (isset($text[$at]) && str_contains(" \t\n\r", $text[$at])) {
            ++$at;
        }
    }

    private static function token(string $text, int &$at): void
    {
        $pattern = $text[$at] === '"' ? '~"(?:[^"\\\\]|\\\\.)*"~As' : '~(?:-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?|true|false|null)~A';
        if (preg_match($pattern, $text, $match, 0, $at) !== 1) {
            throw new GateError('Cannot locate the bytes of a published JSON member.');
        }
        $at += \strlen($match[0]);
    }
}
