<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use JsonException;

/** Pure canonical envelope-line recognition; document semantics remain with BaselineFileShape. */
final class CanonicalEnvelope
{
    public const int DOCUMENT_DEPTH_LIMIT = 512;

    private const int DEPTH_LIMIT = self::DOCUMENT_DEPTH_LIMIT - 1;

    private const string UNDECODABLE = "\x00undecodable";

    /** `  "<json string>": <json value>,` */
    private const string LINE = '/^  ("(?:[^"\\\\]|\\\\.)*"): (.+),$/';

    private function __construct() {}

    /**
     * @return array{string, mixed}|null
     */
    public static function parseLine(?string $line): ?array
    {
        if ($line === null || preg_match(self::LINE, $line, $match) !== 1) {
            return null;
        }

        $key = self::decode($match[1]);
        $value = self::decode($match[2]);

        if (!\is_string($key) || $value === self::UNDECODABLE) {
            return null;
        }

        return [$key, $value];
    }

    private static function decode(string $json): mixed
    {
        try {
            return json_decode($json, false, self::DEPTH_LIMIT, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::UNDECODABLE;
        }
    }
}
