<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * The finding fields each authoritative record view publishes, which is what a
 * licensed field move may name. Every JSON capture of a finding report shares
 * the JSON report's schema.
 */
final class PublishedVocabulary
{
    private const JSON_CAPTURES = ['check:output:file', 'check:parallel', 'check:baseline', 'check:baseline-source'];

    /** @return list<string> Fields decoded by the JSON record comparator. */
    public static function comparedFieldsOf(string $surface): array
    {
        return match (self::syntaxSurface($surface)) {
            'format:json' => ReportRecords::SCHEMAS['json'],
            'format:suppressed' => ['rule', 'code', 'subject', 'occurrence', 'edge', 'file', 'line', 'symbol', 'severity', 'message', 'recommendation'],
            'baseline-file' => ['subject', 'channel', 'occurrence', 'edge'],
            default => [],
        };
    }

    private static function syntaxSurface(string $surface): string
    {
        return \in_array($surface, self::JSON_CAPTURES, true) ? 'format:json' : $surface;
    }
}
