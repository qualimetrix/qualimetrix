<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Contract;

use LogicException;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveRefusalReason;

/**
 * A directive starts its physical comment line; quoting only excuses a mention.
 *
 * Masking preserves byte offsets. Extractors restore an admitted tag's authored
 * arguments from the original comment so masking another tag cannot change them.
 */
final readonly class DocumentationRegions
{
    // Comments may contain arbitrary PHP bytes; Unicode whitespace is matched by its UTF-8 bytes.
    private const string UNICODE_SPACE = '(?:\xC2[\x85\xA0]|\xE1\x9A\x80|\xE2\x80[\x80-\x8A\xA8\xA9\xAF]|\xE2\x81\x9F|\xE3\x80\x80)';

    private const string CANDIDATE = '/@qmx-[a-zA-Z][\w-]*|@qmx(?:[-_ \t]|' . self::UNICODE_SPACE . ')*(?:ignore|threshold)[\w-]*|qmx[-_](?:ignore|threshold)[\w-]*/i';

    public static function mask(string $text): string
    {
        return self::read($text)['mask'];
    }

    /** @return list<array{offset: int, tag: string, reason: DirectiveRefusalReason, fenceLine: ?int}> */
    public static function mentions(string $text): array
    {
        return self::read($text)['mentions'];
    }

    /** @return array{mask: string, mentions: list<array{offset: int, tag: string, reason: DirectiveRefusalReason, fenceLine: ?int}>} */
    private static function read(string $text): array
    {
        $lines = explode("\n", $text);
        $mentions = [];
        $fencedTags = [];
        $fence = null;
        $offset = 0;

        foreach ($lines as $index => $line) {
            if ($fence !== null) {
                if (self::closesFence($line, $fence['character'], $fence['length'])) {
                    $fence = null;
                    $fencedTags = [];
                } else {
                    foreach (self::tags($line) as [$tag, $position]) {
                        if (self::isExact($tag) && self::atStart(substr($line, 0, $position))) {
                            $fencedTags[] = ['offset' => $offset + $position, 'tag' => $tag,
                                'reason' => DirectiveRefusalReason::InsideUnclosedFence, 'fenceLine' => $fence['line']];
                        }
                    }
                }
                $lines[$index] = self::blank($line);
            } elseif (($opening = self::openingFence($line)) !== null) {
                $fence = [...$opening, 'line' => $index + 1];
                $lines[$index] = self::blank($line);
            } else {
                foreach (self::tags($line) as [$tag, $position]) {
                    if (self::atStart(substr($line, 0, $position))) {
                        continue;
                    }
                    $lines[$index] = substr_replace($lines[$index], str_repeat(' ', \strlen($tag)), $position, \strlen($tag));
                    if (self::isExact($tag) && !self::quoted($line, $position)) {
                        $mentions[] = ['offset' => $offset + $position, 'tag' => $tag,
                            'reason' => DirectiveRefusalReason::NotAtLineStart, 'fenceLine' => null];
                    }
                }
            }
            $offset += \strlen($line) + 1;
        }

        return ['mask' => implode("\n", $lines), 'mentions' => [...$mentions, ...$fencedTags]];
    }

    /** @return list<array{string, int}> */
    private static function tags(string $line): array
    {
        self::regexResult(preg_match_all(self::CANDIDATE, $line, $matches, \PREG_OFFSET_CAPTURE));

        return $matches[0];
    }

    private static function isExact(string $tag): bool
    {
        return self::regexResult(preg_match('/^@qmx-[a-zA-Z]/', $tag)) === 1;
    }

    private static function atStart(string $prefix): bool
    {
        return self::regexResult(preg_match('/^(?:[\s\/*#]|' . self::UNICODE_SPACE . ')*$/', $prefix)) === 1;
    }

    /** @return array{character: string, length: int}|null */
    private static function openingFence(string $line): ?array
    {
        $result = preg_match('/^(?:[\s\/*#]|' . self::UNICODE_SPACE . ')*(`{3,}|~{3,})(.*)$/', $line, $match);
        self::regexResult($result);
        if ($result !== 1
            || ($match[1][0] === '`' && str_contains($match[2], '`'))) {
            return null;
        }

        return ['character' => $match[1][0], 'length' => \strlen($match[1])];
    }

    private static function closesFence(string $line, string $character, int $length): bool
    {
        return self::regexResult(preg_match('/^(?:[\s\/*#]|' . self::UNICODE_SPACE . ')*' . preg_quote($character, '/') . '{' . $length . ',}(?:\s|' . self::UNICODE_SPACE . ')*(?:\*\/)?(?:\s|' . self::UNICODE_SPACE . ')*$/', $line)) === 1;
    }

    private static function quoted(string $line, int $position): bool
    {
        self::regexResult(preg_match_all('/`+/', $line, $matches, \PREG_OFFSET_CAPTURE));
        $runs = $matches[0];

        for ($i = 0, $count = \count($runs); $i < $count; ++$i) {
            for ($j = $i + 1; $j < $count; ++$j) {
                if (\strlen($runs[$i][0]) !== \strlen($runs[$j][0])) {
                    continue;
                }
                $start = $runs[$i][1] + \strlen($runs[$i][0]);
                $end = $runs[$j][1];
                if ($position >= $start && $position < $end) {
                    return self::regexResult(preg_match('/^(?:[\s\/*#`]|' . self::UNICODE_SPACE . ')*$/', substr($line, $start, $position - $start))) === 1;
                }
                $i = $j;
                break;
            }
        }

        return false;
    }

    private static function regexResult(int|false $result): int
    {
        if ($result === false) {
            throw new LogicException('Cannot read directive grammar: ' . preg_last_error_msg());
        }

        return $result;
    }

    private static function blank(string $line): string
    {
        return preg_replace('/[^\r]/', ' ', $line) ?? throw new LogicException('Cannot mask comment bytes: ' . preg_last_error_msg());
    }
}
