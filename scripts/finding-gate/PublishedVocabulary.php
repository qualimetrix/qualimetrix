<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * Compared-field spellings belong to each captured side's publication.
 *
 * Reference HTML keeps its legacy aliases; candidate HTML publishes the shared
 * record's canonical keys. A surface absent from the exhaustive key table cannot
 * be licensed by a tuple spelling it never publishes. Prose fields are handled
 * by ProseRecords, including the candidate's independent advice and baseline lines.
 */
final class PublishedVocabulary
{
    /** `"field": value`, the JSON family. */
    private const MEMBER = 'member';

    /** `field="value"`, checkstyle's XML — whose values are escaped, so a licence row for it transcribes the escaped pair. */
    private const ATTRIBUTE = 'attribute';

    /**
     * Surface class => how it marks a field, which fields it publishes under
     * which key, and whether that list is exhaustive.
     *
     * `exhaustive: false` means "everything else under its own tuple spelling",
     * which is true of the two surfaces the tuple is named after. Everywhere
     * else a field absent from `keys` is absent from the surface.
     *
     * @var array<string, array{syntax: string, exhaustive: bool, keys: array<string, string>}>
     */
    private const SURFACES = [
        'format:json' => [
            'syntax' => self::MEMBER,
            'exhaustive' => false,
            'keys' => [],
        ],
        'format:html' => [
            'syntax' => self::MEMBER,
            'exhaustive' => false,
            'keys' => ['rule' => 'ruleName', 'code' => 'violationCode', 'symbol' => 'symbolPath'],
        ],
        'format:sarif' => [
            'syntax' => self::MEMBER,
            'exhaustive' => true,
            'keys' => [
                'message' => 'text',
                'code' => 'ruleId',
                'severity' => 'level',
                'file' => 'uri',
                'line' => 'startLine',
            ],
        ],
        'format:gitlab' => [
            'syntax' => self::MEMBER,
            'exhaustive' => true,
            'keys' => [
                'message' => 'description',
                'code' => 'check_name',
                'severity' => 'severity',
                'file' => 'path',
                'line' => 'begin',
            ],
        ],
        'format:checkstyle' => [
            'syntax' => self::ATTRIBUTE,
            'exhaustive' => true,
            'keys' => [
                'message' => 'message',
                'code' => 'source',
                'severity' => 'severity',
                'line' => 'line',
                'file' => 'name',
            ],
        ],
        // `channel` here holds the finding's *code*, which the JSON report
        // publishes under `code` and whose own `channel` this surface does not
        // carry at all. Spelling that out is the whole point of the table:
        // reading `channel` under its tuple spelling here would compare one
        // field against another field's value. `edge` is an object rather than
        // a scalar, so, as on the baseline file, it is not readable as a value.
        'format:suppressed' => [
            'syntax' => self::MEMBER,
            'exhaustive' => true,
            'keys' => [
                'rule' => 'rule',
                'code' => 'channel',
                'subject' => 'subject',
                'occurrence' => 'occurrence',
                'file' => 'file',
                'line' => 'line',
                'symbol' => 'symbol',
                'severity' => 'severity',
                'message' => 'message',
                'recommendation' => 'recommendation',
            ],
        ],
        // Not a format but a captured file, and the one non-format artifact that
        // publishes a compared field. `edge` is an object rather than a scalar,
        // so it is not readable as a value and is left out.
        'baseline-file' => [
            'syntax' => self::MEMBER,
            'exhaustive' => true,
            'keys' => ['channel' => 'channel', 'occurrence' => 'occurrence'],
        ],
    ];

    /**
     * The formats whose lines carry no named finding-field marker, each with why.
     *
     * Enumerated rather than defaulted to, because "that surface publishes
     * nothing readable" is the claim that let eight declarations through
     * unexamined. Four print the finding as prose with no member or attribute marker; two
     * publish no finding record at all.
     *
     * @var array<string, string>
     */
    public const UNREADABLE = [
        'summary' => 'prints the message as prose after a bare channel name',
        'text' => 'prints the message as prose after a bare channel name',
        'text-detail' => 'prints the message as prose after a bare channel name',
        'text-verbose' => 'withdrawn reference surface; prints the message as prose after a bare channel name',
        'github' => 'prints the message as prose after "::"',
        'metrics' => 'publishes measured metrics, not finding records',
        'health' => 'publishes health scores, not finding records',
    ];

    private const JSON_CAPTURES = ['check:output:file', 'check:parallel', 'check:baseline', 'check:baseline-source'];

    /** The key a surface publishes one tuple field under, or null when it does not publish it. */
    public static function spellingOf(string $surfaceClass, string $field, string $codec = 'legacy'): ?string
    {
        $surface = self::SURFACES[self::syntaxSurface($surfaceClass)] ?? null;

        if ($surface === null) {
            return null;
        }

        if ($codec === 'current' && self::syntaxSurface($surfaceClass) === 'format:html') {
            return $field;
        }

        return $surface['keys'][$field] ?? ($surface['exhaustive'] ? null : $field);
    }

    /**
     * Every value one line of one surface publishes for one field.
     *
     * A surface that marks no field, or a field that surface does not carry,
     * yields nothing — and the enumeration above is what makes that an answer
     * rather than a silence.
     *
     * @return list<string>
     */
    public static function valuesOn(string $surfaceClass, ?string $line, string $field, string $codec = 'legacy'): array
    {
        $spelling = self::spellingOf($surfaceClass, $field, $codec);

        if ($line === null || $spelling === null) {
            return [];
        }

        $quoted = preg_quote($spelling, '~');

        $pattern = self::SURFACES[self::syntaxSurface($surfaceClass)]['syntax'] === self::ATTRIBUTE
            ? \sprintf('~\b%s\s*=\s*"([^"]*)"()~', $quoted)
            : \sprintf('~"%s"\s*:\s*(?:"((?:[^"\\\\]|\\\\.)*)"|([^,}\]\s]+))~', $quoted);

        if (preg_match_all($pattern, $line, $matches) === false) {
            throw new GateError(\sprintf('Cannot read published values of "%s".', $spelling));
        }

        $values = [];

        foreach ($matches[2] as $index => $bare) {
            $values[] = $bare === '' ? $matches[1][$index] : $bare;
        }

        return $values;
    }

    /**
     * The surface classes a field is readable on, as a list.
     *
     * @return list<string>
     */
    public static function readableSurfaces(): array
    {
        return [...array_keys(self::SURFACES), ...self::JSON_CAPTURES];
    }

    /**
     * The keys one surface renames a compared field to, for the self-test to
     * pin against the formatter that writes them.
     *
     * @return array<string, string>
     */
    public static function keysOf(string $surfaceClass, string $codec = 'legacy'): array
    {
        if ($codec === 'current' && self::syntaxSurface($surfaceClass) === 'format:html') {
            return array_combine(ReportRecords::SCHEMAS['json'], ReportRecords::SCHEMAS['json']);
        }
        return self::SURFACES[self::syntaxSurface($surfaceClass)]['keys'] ?? [];
    }
    /** @return list<string> Fields decoded by the complete record comparator. */
    public static function comparedFieldsOf(string $surface, string $codec = 'current'): array
    {
        $surface = self::syntaxSurface($surface);
        return match ($surface) {
            'format:json' => ReportRecords::SCHEMAS['json'],
            'format:html' => $codec === 'current' ? [...ReportRecords::SCHEMAS['json'], 'baselineVerdict', 'baselineReason'] : ['subject', 'rule', 'code', 'message', 'recommendation', 'severity', 'metricValue', 'symbol', 'occurrence', 'file', 'line'],
            'format:suppressed' => ['rule', 'code', 'subject', 'occurrence', 'edge', 'file', 'line', 'symbol', 'severity', 'message', 'recommendation'],
            'format:sarif', 'format:gitlab', 'format:checkstyle' => ['code', 'severity', 'message', 'file', 'line'],
            'baseline-file' => ['subject', 'channel', 'occurrence', 'edge'],
            default => ProseRecords::fieldsOf($surface, $codec),
        };
    }

    private static function syntaxSurface(string $surface): string
    {
        return \in_array($surface, self::JSON_CAPTURES, true) ? 'format:json' : $surface;
    }

}
