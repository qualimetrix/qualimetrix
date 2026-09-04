<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * What a surface calls a compared field, and how it writes it down.
 *
 * `delta-overreach` refuses a diff line that moves a field the equivalence
 * tuple compares. To do that it has to *find* the field on the line, and the
 * tuple is named after the JSON report — so reading the tuple's own spelling in
 * the tuple's own syntax means reading the JSON report and, by accident, only
 * the JSON report.
 *
 * That accident had already been half-noticed: the HTML payload publishes three
 * fields under other keys, and reading nothing there was fixed by an alias list.
 * The half that was missed is that the same is true of `sarif`, `gitlab`,
 * `checkstyle` and `suppressed`. One step moved one record's `message` on nine
 * surfaces and declared a delta for each; the licence that lets a compared field
 * move was needed on exactly one of them — not because the other eight do not
 * publish `message`, but because six of them mark no field at all and two mark
 * it under another name. Eight declarations were accepted by a reader that could
 * not reach them, and "one licence sufficed" was a fact about the reader.
 *
 * Two things are therefore stated per surface, and both are checked rather than
 * assumed by {@see SelfTest::publicationVocabulary()}: the key each field is
 * published under, pinned against the formatter that writes it, and whether the
 * list is the *whole* of what that surface publishes. The second matters as much
 * as the first: SARIF carries five of the seventeen tuple fields, and letting the
 * other twelve fall back to their tuple spelling would have the reader hunting
 * for keys the surface does not have.
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
        // field against another field's value.
        'format:suppressed' => [
            'syntax' => self::MEMBER,
            'exhaustive' => true,
            'keys' => [
                'rule' => 'rule',
                'code' => 'channel',
                'file' => 'file',
                'line' => 'line',
                'symbol' => 'symbol',
                'severity' => 'severity',
                'message' => 'message',
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
     * The formats no reader can pick a field out of, each with why.
     *
     * Enumerated rather than defaulted to, because "that surface publishes
     * nothing readable" is the claim that let eight declarations through
     * unexamined. Four print the finding as prose with no field marking; two
     * publish no finding record at all.
     *
     * @var array<string, string>
     */
    public const UNREADABLE = [
        'summary' => 'prints the message as prose after a bare channel name',
        'text' => 'prints the message as prose after a bare channel name',
        'text-verbose' => 'prints the message as prose after a bare channel name',
        'github' => 'prints the message as prose after "::"',
        'metrics' => 'publishes measured metrics, not finding records',
        'health' => 'publishes health scores, not finding records',
    ];

    /** The key a surface publishes one tuple field under, or null when it does not publish it. */
    public static function spellingOf(string $surfaceClass, string $field): ?string
    {
        $surface = self::SURFACES[$surfaceClass] ?? null;

        if ($surface === null) {
            return null;
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
    public static function valuesOn(string $surfaceClass, ?string $line, string $field): array
    {
        $spelling = self::spellingOf($surfaceClass, $field);

        if ($line === null || $spelling === null) {
            return [];
        }

        $quoted = preg_quote($spelling, '~');

        $pattern = self::SURFACES[$surfaceClass]['syntax'] === self::ATTRIBUTE
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
        return array_keys(self::SURFACES);
    }

    /**
     * The keys one surface renames a compared field to, for the self-test to
     * pin against the formatter that writes them.
     *
     * @return array<string, string>
     */
    public static function keysOf(string $surfaceClass): array
    {
        return self::SURFACES[$surfaceClass]['keys'] ?? [];
    }
}
