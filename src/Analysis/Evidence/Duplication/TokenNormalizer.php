<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Duplication;

/**
 * Normalizes PHP token streams for duplication detection.
 *
 * Strips whitespace and comments, replaces variable names, string literals,
 * and numbers with placeholders so that structurally identical code with
 * different identifiers is detected as duplicate.
 *
 * After normalization, {@see DataDeclarationTagger} runs a second pass over
 * the resulting token list to flag tokens that lie inside a constant
 * declaration or a property's array-literal initializer (see its docblock
 * for the exact patterns matched). This is a separate concern from
 * normalization proper, kept in its own class for cohesion, but composed
 * here so every {@see normalize()} caller gets tagged tokens without having
 * to know the tagger exists.
 *
 * `T_CLOSE_TAG` (`?>`) is skipped like the other {@see SKIP_TOKENS} for
 * every caller that does not want tagging, but when tagging is enabled it
 * is instead preserved as a {@see DataDeclarationTagger::PHP_CLOSE_TAG_BARRIER}
 * sentinel token before being handed to the tagger, then stripped back out
 * of the result. The tagger's forward scans (looking for the `;` that ends
 * a `const`/property declaration) need *some* in-stream marker for where a
 * PHP block ends, or they run straight through into the next block's code
 * and mis-tag it as data — see {@see DataDeclarationTagger::findStatementEnd()}.
 * Because the barrier is added and removed within the same {@see normalize()}
 * call, callers never observe it: the returned token stream for a file
 * without `?>` is unaffected, and for a file with `?>` it has exactly the
 * same tokens as before this barrier existed — only the tagger's internal
 * scans see the extra marker.
 *
 * Tagging is opt-out because the detector's two passes need different things.
 * Pass 1 hashes token *values* only and discards the tokens immediately, so
 * tagging there is pure waste — measured at ~28% of normalization time, with a
 * full token-array rebuild for the ~35% of files that contain a constant or
 * property array. Pass 2 re-tokenizes only the candidate files and is the
 * single place `isData` is read, so it keeps tagging enabled.
 */
final class TokenNormalizer
{
    private DataDeclarationTagger $dataDeclarationTagger;

    /**
     * @param bool $tagDataDeclarations Whether to flag tokens inside constant/property
     *                                  array declarations. Disable when the caller only
     *                                  consumes token values and never reads `isData`.
     */
    public function __construct(private readonly bool $tagDataDeclarations = true)
    {
        $this->dataDeclarationTagger = new DataDeclarationTagger();
    }

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

            if ($type === \T_CLOSE_TAG && $this->tagDataDeclarations) {
                // Preserve the PHP-block boundary as a barrier token instead
                // of discarding it like the other SKIP_TOKENS — see the
                // class docblock. Stripped back out below, after tagging,
                // so it never reaches a normalize() caller.
                $result[] = new NormalizedToken(DataDeclarationTagger::PHP_CLOSE_TAG_BARRIER, '', $line);

                continue;
            }

            if (\in_array($type, self::SKIP_TOKENS, true)) {
                continue;
            }

            if (isset(self::NORMALIZE_MAP[$type])) {
                $value = self::NORMALIZE_MAP[$type];
            }

            $result[] = new NormalizedToken($type, $value, $line);
        }

        if (!$this->tagDataDeclarations) {
            return $result;
        }

        $tagged = $this->dataDeclarationTagger->tag($result);

        return array_values(array_filter(
            $tagged,
            static fn(NormalizedToken $t): bool => $t->type !== DataDeclarationTagger::PHP_CLOSE_TAG_BARRIER,
        ));
    }
}
