<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Duplication\Normalization;

use LogicException;

/**
 * Variables and literals share placeholders; inline HTML keeps a compact
 * byte-safe digest. Original source spans remain intact.
 * Raw token types are retained only until data declarations have been tagged.
 */
final class TokenNormalizer
{
    private DataDeclarationTagger $dataDeclarationTagger;

    public function __construct(private readonly bool $tagDataDeclarations = true)
    {
        $this->dataDeclarationTagger = new DataDeclarationTagger();
    }

    private const SKIP_TOKENS = [
        \T_WHITESPACE,
        \T_COMMENT,
        \T_DOC_COMMENT,
        \T_OPEN_TAG,
        \T_CLOSE_TAG,
    ];

    private const FOLD_CASE_TOKENS = [
        \T_ABSTRACT, \T_ARRAY, \T_AS, \T_BREAK, \T_CALLABLE, \T_CASE,
        \T_CATCH, \T_CLASS, \T_CLONE, \T_CONST, \T_CONTINUE, \T_DECLARE,
        \T_DEFAULT, \T_DO, \T_ECHO, \T_ELSE, \T_ELSEIF, \T_EMPTY,
        \T_ENDDECLARE, \T_ENDFOR, \T_ENDFOREACH, \T_ENDIF, \T_ENDSWITCH,
        \T_ENDWHILE, \T_ENUM, \T_EVAL, \T_EXIT, \T_EXTENDS, \T_FINAL,
        \T_FINALLY, \T_FN, \T_FOR, \T_FOREACH, \T_FUNCTION, \T_GLOBAL,
        \T_GOTO, \T_IF, \T_IMPLEMENTS, \T_INCLUDE, \T_INCLUDE_ONCE,
        \T_INSTANCEOF, \T_INSTEADOF, \T_INTERFACE, \T_ISSET, \T_LIST,
        \T_LOGICAL_AND, \T_LOGICAL_OR, \T_LOGICAL_XOR, \T_MATCH,
        \T_NAMESPACE, \T_NEW, \T_PRINT, \T_PRIVATE, \T_PRIVATE_SET,
        \T_PROTECTED, \T_PROTECTED_SET, \T_PUBLIC, \T_PUBLIC_SET,
        \T_READONLY, \T_REQUIRE, \T_REQUIRE_ONCE, \T_RETURN, \T_STATIC,
        \T_SWITCH, \T_THROW, \T_TRAIT, \T_TRY, \T_UNSET, \T_USE,
        \T_VAR, \T_WHILE, \T_YIELD, \T_YIELD_FROM, \T_HALT_COMPILER,
        \T_INT_CAST, \T_DOUBLE_CAST, \T_STRING_CAST, \T_ARRAY_CAST,
        \T_OBJECT_CAST, \T_BOOL_CAST, \T_UNSET_CAST,
        \T_LINE, \T_FILE, \T_DIR, \T_CLASS_C, \T_TRAIT_C,
        \T_METHOD_C, \T_FUNC_C, \T_NS_C, \T_PROPERTY_C,
    ];

    private const NORMALIZE_MAP = [
        \T_VARIABLE => '$_',
        \T_CONSTANT_ENCAPSED_STRING => "'_'",
        \T_ENCAPSED_AND_WHITESPACE => "'_'",
        \T_LNUMBER => '0',
        \T_DNUMBER => '0',
    ];

    public function normalize(string $source): TokenStream
    {
        $rawTokens = @token_get_all($source);
        $values = $types = $startLines = $endLines = $startBytes = $endBytes = [];
        $currentLine = 1;
        $byte = 0;
        $barriers = [];

        foreach ($rawTokens as $token) {
            $text = \is_string($token) ? $token : $token[1];
            $startByte = $byte;
            $byte += \strlen($text);

            if (\is_string($token)) {
                $type = 0;
                $line = $currentLine;
                $value = $text;
            } else {
                [$type, $value, $line] = $token;
                // Single-character tokens inherit the previous token's end, including skipped whitespace.
                $currentLine = $line + substr_count($text, "\n") + substr_count($text, "\r") - substr_count($text, "\r\n");
                if ($type === \T_CLOSE_TAG && $this->tagDataDeclarations) {
                    // A closing PHP block terminates a declaration before the next block's code.
                    $barriers[] = \count($values);
                    $type = DataDeclarationTagger::PHP_CLOSE_TAG_BARRIER;
                    $value = '';
                } elseif (\in_array($type, self::SKIP_TOKENS, true)) {
                    continue;
                } elseif ($type === \T_INLINE_HTML) {
                    $collapsedHtml = preg_replace('/[\x09-\x0D\x20]+/', ' ', $text)
                        ?? throw new LogicException('Cannot normalize inline HTML whitespace.');
                    $value = 'html:' . hash('xxh128', $collapsedHtml);
                } else {
                    $value = self::NORMALIZE_MAP[$type] ?? $value;
                    if (\in_array($type, self::FOLD_CASE_TOKENS, true)
                        || ($type === \T_STRING && \in_array(strtolower($value), ['true', 'false', 'null'], true))) {
                        $value = strtolower($value);
                    }
                }
            }

            $values[] = $value;
            if ($this->tagDataDeclarations) {
                $types[] = $type;
            }
            $startLines[] = $line;
            $endLines[] = $currentLine;
            $startBytes[] = $startByte;
            $endBytes[] = $byte;
        }

        unset($rawTokens);
        $dataMask = $this->tagDataDeclarations
            ? $this->dataDeclarationTagger->tag($types, $values)
            : str_repeat('0', \count($values));
        unset($types);

        if ($barriers !== []) {
            foreach ($barriers as $index) {
                unset($values[$index], $startLines[$index], $endLines[$index], $startBytes[$index], $endBytes[$index]);
                $dataMask[$index] = ' ';
            }
            $values = array_values($values);
            $startLines = array_values($startLines);
            $endLines = array_values($endLines);
            $startBytes = array_values($startBytes);
            $endBytes = array_values($endBytes);
            $dataMask = str_replace(' ', '', $dataMask);
        }

        $coveredPrefix = [];
        $covered = 0;
        $previousEnd = 0;
        foreach ($startLines as $index => $start) {
            $end = $endLines[$index];
            $covered += $end - max($start, $previousEnd + 1) + 1;
            $coveredPrefix[] = $covered;
            $previousEnd = $end;
        }

        return new TokenStream($values, $startLines, $endLines, $coveredPrefix, $dataMask, $startBytes, $endBytes);
    }
}
