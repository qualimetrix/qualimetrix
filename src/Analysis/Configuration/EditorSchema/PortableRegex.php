<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\EditorSchema;

/**
 * Patterns in the subset ECMA-262 and PCRE read alike: literals, classes,
 * anchors, alternation and non-capturing groups. Editors evaluate a schema
 * pattern as ECMA-262 (in Unicode mode, where escaping a character that is not
 * syntax is an error), PHP validators as PCRE.
 *
 * A control character is written as itself; the JSON encoding of the schema
 * escapes it, so neither dialect sees a regex escape for it.
 */
final class PortableRegex
{
    private const string SYNTAX = '\\^$.|?*+()[]{}';

    private const string CLASS_SYNTAX = '\\]^-';

    /**
     * Exactly one of the texts, nothing around it.
     *
     * @param non-empty-list<string> $texts
     */
    public static function exactly(array $texts): string
    {
        return '^(?:' . implode('|', array_map(self::literal(...), $texts)) . ')$';
    }

    /**
     * Exactly one of the words, each ASCII letter in either case — the
     * comparison `strcasecmp()` makes.
     *
     * @param non-empty-list<string> $words
     */
    public static function exactlyFoldingCase(array $words): string
    {
        return '^(?:' . implode('|', array_map(self::foldingCase(...), $words)) . ')$';
    }

    /** Text holding at least one character that is not among `$characters`. */
    public static function holdingCharacterOutside(string $characters): string
    {
        return '[^' . self::escaped($characters, self::CLASS_SYNTAX) . ']';
    }

    private static function literal(string $text): string
    {
        return self::escaped($text, self::SYNTAX);
    }

    private static function foldingCase(string $word): string
    {
        $pattern = '';
        foreach (str_split($word) as $byte) {
            $pattern .= preg_match('/^[A-Za-z]$/', $byte) === 1
                ? '[' . strtolower($byte) . strtoupper($byte) . ']'
                : self::literal($byte);
        }

        return $pattern;
    }

    private static function escaped(string $text, string $syntax): string
    {
        $escaped = '';
        foreach (str_split($text) as $byte) {
            $escaped .= str_contains($syntax, $byte) ? '\\' . $byte : $byte;
        }

        return $escaped;
    }
}
