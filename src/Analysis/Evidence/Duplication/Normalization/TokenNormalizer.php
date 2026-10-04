<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Duplication\Normalization;

/**
 * Variables and literals share placeholders; original source spans remain intact.
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
        \T_INLINE_HTML,
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
                } else {
                    $value = self::NORMALIZE_MAP[$type] ?? $value;
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
