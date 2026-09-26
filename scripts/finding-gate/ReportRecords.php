<?php

declare(strict_types=1);

namespace QmxFindingGate;

use DOMDocument;
use JsonException;

/** Published records, their complete schemas, and edits confined to their byte spans. */
final class ReportRecords
{
    public const array SCHEMAS = [
        'json' => ['file', 'line', 'subject', 'symbol', 'channel', 'occurrence', 'edge', 'namespace', 'rule', 'code', 'severity', 'message', 'recommendation', 'metricValue', 'threshold', 'techDebtMinutes', 'acceptedLevel'],
        'suppressed' => ['mechanism', 'suppressor', 'rule', 'channel', 'subject', 'occurrence', 'edge', 'file', 'line', 'symbol', 'severity', 'message', 'recommendation'],
        'metrics' => ['type', 'name', 'file', 'line', 'metrics'],
        'directives' => ['file', 'line', 'form', 'target', 'effect', 'reason', 'masked_by', 'boundary_observable'],
    ];
    public const array RANKED_FIELDS = ['rank', 'file', 'line', 'symbol', 'rule', 'severity', 'message', 'recommendation', 'impactScore', 'coupling.class-rank', 'debtMinutes'];
    public const array ARRAYS = ['json' => 'violations', 'suppressed' => 'suppressed', 'metrics' => 'symbols', 'directives' => 'directives'];

    /**
     * @param list<array<string,mixed>> $records
     *
     * @return list<array{path:list<int|string>,fields:array<string,mixed>}>
     */
    public static function baselineEntries(string $text, array $records): array
    {
        $document = self::decode($text);
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
            $identity[$key] = $record[$key];
        }
        return DeclaredRecords::canonical($identity);
    }

    /**
     * @param array<string,mixed> $record
     *
     * @return array<string,mixed>
     */
    public static function projection(string $surface, array $record): array
    {
        $message = self::message($record);
        $severity = $record['severity'];
        return match ($surface) {
            'format:html' => ['subject' => $record['subject'], 'ruleName' => $record['rule'], 'violationCode' => $record['code'], 'message' => $record['message'], 'recommendation' => $record['recommendation'], 'severity' => $severity, 'metricValue' => $record['metricValue'], 'symbolPath' => $record['symbol'], 'occurrence' => $record['occurrence'], 'file' => $record['file'], 'line' => $record['line']],
            'format:checkstyle' => ['file' => $record['file'] ?? '[project]', 'line' => $record['line'] ?? 1, 'severity' => $severity, 'code' => 'qmx.' . $record['code'], 'message' => $message],
            'format:gitlab' => ['description' => $message, 'check_name' => $record['code'], 'severity' => match ($severity) {
                'error' => 'critical', 'warning' => 'major', default => 'info',
            }, 'location' => ['path' => $record['file'] ?? '', 'lines' => ['begin' => $record['line'] ?? 1]]],
            'format:sarif' => ['ruleId' => $record['code'], 'level' => match ($severity) {
                'warning' => 'warning', 'error' => 'error', default => 'note',
            }, 'message' => ['text' => $message], 'file' => $record['file'], 'line' => $record['file'] === null ? null : ($record['line'] ?? 1)],
            default => throw new GateError('Unknown finding projection: ' . $surface),
        };
    }

    /** @param array<string,mixed> $record */
    public static function message(array $record, bool $advice = false): string
    {
        $text = $advice && $record['recommendation'] !== null ? $record['recommendation'] : $record['message'];
        if (!\is_string($text)) {
            throw new GateError('A finding publishes no message string.');
        }
        if ($record['acceptedLevel'] !== null && $record['metricValue'] !== null && $record['metricValue'] > $record['acceptedLevel']) {
            $text .= \sprintf(' (accepted at %s, now %s)', $record['acceptedLevel'], $record['metricValue']);
        }
        return $text;
    }

    /**
     * Paths retain their original record order and bytes; only a licensed member is replaced or removed.
     *
     * @param array<string,string|null> $edits JSON encoded paths => replacement JSON, or null for deletion
     */
    public static function edit(string $text, array $edits): string
    {
        self::decode($text);
        $at = 0;
        $spans = [];
        self::scan($text, $at, [], $edits, $spans);
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
                $comma = $text[$at] === ',' ? $at++ : null;
                $members[] = [$memberStart, $end, $comma, $encoded];
                self::space($text, $at);
                ++$index;
            }
            ++$at;
            foreach ($members as $i => [$memberStart, $memberEnd, $comma, $encoded]) {
                if (\array_key_exists($encoded, $edits) && $edits[$encoded] === null) {
                    if ($comma !== null) {
                        $memberEnd = $comma + 1;
                    } elseif ($i > 0) {
                        $previous = $members[$i - 1][2];
                        if ($previous !== null) {
                            $memberStart = $previous;
                        }
                    }
                    $spans[] = [$memberStart, $memberEnd, ''];
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

    /** @return list<array{path:list<string|int>,fields:array<string,mixed>}> */
    public static function projected(string $surface, string $text): array
    {
        $document = self::decode($text);
        $records = [];
        if ($surface === 'format:html') {
            self::html($document, [], $records);
        } elseif ($surface === 'format:gitlab') {
            if (!array_is_list($document)) {
                throw new GateError('GitLab publishes a result list.');
            }
            foreach ($document as $index => $record) {
                if (!\is_array($record) || !isset($record['check_name'])) {
                    throw new GateError('A GitLab result requires a check_name.');
                }
                if (str_starts_with((string) $record['check_name'], 'qmx.analysis') || str_starts_with((string) $record['check_name'], 'qmx.output')) {
                    continue;
                }
                unset($record['fingerprint']);
                $records[] = ['path' => [$index], 'fields' => $record];
            }
        } elseif ($surface === 'format:sarif') {
            if (!\is_array($document['runs'] ?? null) || !array_is_list($document['runs'])) {
                throw new GateError('SARIF publishes a run list.');
            }
            foreach ($document['runs'] as $runIndex => $run) {
                if (!\is_array($run) || !\is_array($run['results'] ?? null) || !\is_array($run['tool']['driver']['rules'] ?? null)) {
                    throw new GateError('SARIF requires results and their rule catalog.');
                }
                foreach ($run['results'] as $index => $record) {
                    if (!\is_array($record) || !\is_int($record['ruleIndex'] ?? null)
                        || ($run['tool']['driver']['rules'][$record['ruleIndex']]['id'] ?? null) !== ($record['ruleId'] ?? null)) {
                        throw new GateError('A SARIF ruleIndex disagrees with its own published ruleId catalog.');
                    }
                    $location = $record['locations'][0]['physicalLocation'] ?? null;
                    $records[] = ['path' => ['runs', $runIndex, 'results', $index], 'fields' => [
                        'ruleId' => $record['ruleId'], 'level' => $record['level'] ?? null, 'message' => $record['message'] ?? null,
                        'file' => $location === null ? null : ($location['artifactLocation']['uri'] ?? null),
                        'line' => $location === null ? null : ($location['region']['startLine'] ?? null),
                    ]];
                }
            }
        } else {
            throw new GateError('Unknown structured finding projection.');
        }
        return $records;
    }

    /** @return list<array<string,mixed>> */
    public static function checkstyle(string $text): array
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$document->loadXML($text, \LIBXML_NONET) || $document->documentElement?->tagName !== 'checkstyle') {
                throw new GateError('The checkstyle projection is not a readable checkstyle XML document.');
            }
            $records = [];
            foreach ($document->getElementsByTagName('file') as $file) {
                if (!$file->hasAttribute('name')) {
                    throw new GateError('A checkstyle file publishes no path.');
                }
                foreach ($file->getElementsByTagName('error') as $error) {
                    foreach (['line', 'severity', 'source', 'message'] as $field) {
                        if (!$error->hasAttribute($field)) {
                            throw new GateError('A checkstyle error publishes no ' . $field);
                        }
                    }
                    $records[] = ['file' => $file->getAttribute('name'), 'line' => (int) $error->getAttribute('line'), 'severity' => $error->getAttribute('severity'), 'code' => $error->getAttribute('source'), 'message' => $error->getAttribute('message')];
                }
            }
            return $records;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @param array<mixed> $value
     * @param list<string|int> $path
     * @param list<array{path:list<string|int>,fields:array<string,mixed>}> $records
     */
    private static function html(array $value, array $path, array &$records): void
    {
        foreach ($value as $key => $child) {
            if ($key === 'findings') {
                if (!\is_array($child) || !array_is_list($child)) {
                    throw new GateError('An HTML node requires its finding list.');
                }
                foreach ($child as $index => $record) {
                    if (!\is_array($record) || array_is_list($record)) {
                        throw new GateError('An HTML finding is an object.');
                    }
                    /** @var array<string,mixed> $record */
                    $records[] = ['path' => [...$path, $key, $index], 'fields' => $record];
                }
            } elseif (\is_array($child)) {
                self::html($child, [...$path, $key], $records);
            }
        }
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
