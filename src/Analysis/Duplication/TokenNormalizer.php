<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Duplication;

/**
 * Normalizes PHP token streams for duplication detection.
 *
 * Strips whitespace and comments, replaces variable names, string literals,
 * and numbers with placeholders so that structurally identical code with
 * different identifiers is detected as duplicate.
 */
final class TokenNormalizer
{
    /**
     * Token types to skip entirely (whitespace, comments).
     */
    private const SKIP_TOKENS = [
        \T_WHITESPACE,
        \T_COMMENT,
        \T_DOC_COMMENT,
        \T_OPEN_TAG,
        \T_CLOSE_TAG,
        \T_INLINE_HTML,
    ];

    /**
     * Token types to replace with a placeholder value.
     */
    private const NORMALIZE_MAP = [
        \T_VARIABLE => '$_',
        \T_CONSTANT_ENCAPSED_STRING => "'_'",
        \T_ENCAPSED_AND_WHITESPACE => "'_'",
        \T_LNUMBER => '0',
        \T_DNUMBER => '0',
    ];

    /**
     * Normalizes a PHP source string into a stream of NormalizedToken objects.
     *
     * @return list<NormalizedToken>
     */
    public function normalize(string $source): array
    {
        $rawTokens = @token_get_all($source);
        $result = [];
        $currentLine = 1;

        foreach ($rawTokens as $token) {
            if (\is_string($token)) {
                // Single-character tokens (operators, braces, etc.)
                // Inherit line number from the last seen token
                $result[] = new NormalizedToken(0, $token, $currentLine);

                continue;
            }

            [$type, $value, $line] = $token;
            $currentLine = $line;

            if (\in_array($type, self::SKIP_TOKENS, true)) {
                continue;
            }

            if (isset(self::NORMALIZE_MAP[$type])) {
                $value = self::NORMALIZE_MAP[$type];
            }

            $result[] = new NormalizedToken($type, $value, $line);
        }

        return $result;
    }
}
