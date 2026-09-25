<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * A JSON document as the bytes it was published in, edited without being
 * re-encoded.
 *
 * A JSON surface is compared as those bytes, so its layout, its escaping and
 * the spelling of its numbers stay under comparison: a formatter that stops
 * pretty-printing, starts escaping slashes, writes `1.50` for `1.5` or repeats
 * a key is a change of the publication, and decoding both sides to compare
 * values made every one of them invisible. What normalization excludes is
 * therefore cut out of the text in place — the value's own span is replaced —
 * and every other byte is left as it was.
 */
final class JsonText
{
    private const string WHITESPACE = " \t\n\r";

    private const string STRING = '~"(?:[^"\\\\]++|\\\\.)*+"~As';

    private const string NUMBER = '~-?(?:0|[1-9]\d*+)(?:\.\d++)?(?:[eE][+-]?\d++)?~A';

    /**
     * Replaces the value at every path `$segments` names — a segment is a key,
     * an index, or `*` for every member at that depth — with `$replacement`,
     * a JSON value spelled as it is to appear.
     *
     * @param list<string> $segments
     *
     * @return array{0: string, 1: int} the text, and how many values were replaced
     */
    public static function redact(string $text, array $segments, string $replacement): array
    {
        $spans = [];
        self::value($text, self::skip($text, 0), [], $segments, $spans);

        foreach (array_reverse($spans) as [$start, $end]) {
            $text = substr($text, 0, $start) . $replacement . substr($text, $end);
        }

        return [$text, \count($spans)];
    }

    /**
     * Reads the value starting at `$at` and returns the offset just after it,
     * collecting the span of every value whose path the segments match.
     *
     * @param list<string> $path
     * @param list<string> $segments
     * @param list<array{0: int, 1: int}> $spans
     */
    private static function value(string $text, int $at, array $path, array $segments, array &$spans): int
    {
        $end = match ($text[$at] ?? '') {
            '{' => self::members($text, $at, $path, $segments, $spans),
            '[' => self::elements($text, $at, $path, $segments, $spans),
            '"' => self::token($text, $at, self::STRING),
            't' => self::literal($text, $at, 'true'),
            'f' => self::literal($text, $at, 'false'),
            'n' => self::literal($text, $at, 'null'),
            default => self::token($text, $at, self::NUMBER),
        };

        if (self::matches($path, $segments)) {
            $spans[] = [$at, $end];
        }

        return $end;
    }

    /**
     * @param list<string> $path
     * @param list<string> $segments
     * @param list<array{0: int, 1: int}> $spans
     */
    private static function members(string $text, int $at, array $path, array $segments, array &$spans): int
    {
        $at = self::skip($text, $at + 1);

        if (($text[$at] ?? '') === '}') {
            return $at + 1;
        }

        while (true) {
            $keyEnd = self::token($text, $at, self::STRING);
            $key = json_decode(substr($text, $at, $keyEnd - $at), false, 1, \JSON_THROW_ON_ERROR);
            $at = self::skip($text, $keyEnd);
            self::expect($text, $at, ':');
            $at = self::skip($text, self::value($text, self::skip($text, $at + 1), [...$path, (string) $key], $segments, $spans));

            if (($text[$at] ?? '') === '}') {
                return $at + 1;
            }

            self::expect($text, $at, ',');
            $at = self::skip($text, $at + 1);
        }
    }

    /**
     * @param list<string> $path
     * @param list<string> $segments
     * @param list<array{0: int, 1: int}> $spans
     */
    private static function elements(string $text, int $at, array $path, array $segments, array &$spans): int
    {
        $at = self::skip($text, $at + 1);

        if (($text[$at] ?? '') === ']') {
            return $at + 1;
        }

        for ($index = 0; ; ++$index) {
            $at = self::skip($text, self::value($text, $at, [...$path, (string) $index], $segments, $spans));

            if (($text[$at] ?? '') === ']') {
                return $at + 1;
            }

            self::expect($text, $at, ',');
            $at = self::skip($text, $at + 1);
        }
    }

    /**
     * @param list<string> $path
     * @param list<string> $segments
     */
    private static function matches(array $path, array $segments): bool
    {
        if (\count($path) !== \count($segments) || $path === []) {
            return false;
        }

        foreach ($segments as $index => $segment) {
            if ($segment !== '*' && $segment !== $path[$index]) {
                return false;
            }
        }

        return true;
    }

    private static function token(string $text, int $at, string $pattern): int
    {
        if (preg_match($pattern, $text, $matched, 0, $at) !== 1) {
            throw self::unreadable($at);
        }

        return $at + \strlen($matched[0]);
    }

    private static function literal(string $text, int $at, string $literal): int
    {
        if (substr_compare($text, $literal, $at, \strlen($literal)) !== 0) {
            throw self::unreadable($at);
        }

        return $at + \strlen($literal);
    }

    private static function expect(string $text, int $at, string $character): void
    {
        if (($text[$at] ?? '') !== $character) {
            throw self::unreadable($at);
        }
    }

    private static function skip(string $text, int $at): int
    {
        return $at + strspn($text, self::WHITESPACE, $at);
    }

    private static function unreadable(int $at): GateError
    {
        return new GateError(\sprintf('A document that decodes as JSON could not be read as JSON text at offset %d.', $at));
    }
}
