<?php

declare(strict_types=1);

namespace AiMessDetector\Baseline\Suppression;

use AiMessDetector\Core\Suppression\Suppression;
use AiMessDetector\Core\Suppression\SuppressionType;
use PhpParser\Node;

/**
 * Extracts suppression tags from docblock comments.
 *
 * Supported tags:
 * - @aimd-ignore <rule> [reason]
 * - @aimd-ignore-next-line <rule> [reason]
 * - @aimd-ignore-file [rule] [reason]
 */
final readonly class SuppressionExtractor
{
    private const PATTERN_SYMBOL = '/@aimd-ignore(?!-next-line|-file)(?![\w-])\s+([\w.*-]+)(?:[^\S\n\r]+([^\n\r]+))?/';
    private const PATTERN_NEXT_LINE = '/@aimd-ignore-next-line(?![\w-])\s+([\w.*-]+)(?:[^\S\n\r]+([^\n\r]+))?/';
    private const PATTERN_FILE = '/@aimd-ignore-file(?![\w-])(?:\s+([\w.*-]+)(?:[^\S\n\r]+([^\n\r]+))?)?/';

    /**
     * Extracts suppression tags from node's docblock.
     *
     * @return list<Suppression>
     */
    public function extract(Node $node): array
    {
        $docComment = $node->getDocComment();
        if ($docComment === null) {
            return [];
        }

        $suppressions = [];
        $text = $docComment->getText();
        $line = $docComment->getStartLine();

        // Extract file-level suppressions
        if (preg_match_all(self::PATTERN_FILE, $text, $matches, \PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $rule = ($match[1] ?? '') !== '' ? $match[1] : '*';
                $suppressions[] = new Suppression(
                    rule: $rule,
                    reason: self::extractReason($match[2] ?? null),
                    line: $line,
                    type: SuppressionType::File,
                );
            }
        }

        // Extract next-line suppressions (must be checked before symbol pattern)
        // Use endLine so that multi-line docblocks target the line after the closing */
        if (preg_match_all(self::PATTERN_NEXT_LINE, $text, $matches, \PREG_SET_ORDER)) {
            $endLine = $docComment->getEndLine();
            foreach ($matches as $match) {
                $suppressions[] = new Suppression(
                    rule: $match[1],
                    reason: self::extractReason($match[2] ?? null),
                    line: $endLine,
                    type: SuppressionType::NextLine,
                );
            }
        }

        // Extract symbol-level suppressions (plain @aimd-ignore, not -next-line or -file)
        if (preg_match_all(self::PATTERN_SYMBOL, $text, $matches, \PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $suppressions[] = new Suppression(
                    rule: $match[1],
                    reason: self::extractReason($match[2] ?? null),
                    line: $line,
                    type: SuppressionType::Symbol,
                    endLine: $node->getEndLine() > 0 ? $node->getEndLine() : null,
                );
            }
        }

        return $suppressions;
    }

    /**
     * Extracts file-level suppressions from node.
     *
     * @return list<Suppression>
     */
    public function extractFileLevelSuppressions(Node $node): array
    {
        $docComment = $node->getDocComment();
        if ($docComment === null) {
            return [];
        }

        $text = $docComment->getText();
        if (!str_contains($text, '@aimd-ignore-file')) {
            return [];
        }

        $suppressions = [];

        if (preg_match_all(self::PATTERN_FILE, $text, $matches, \PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $rule = ($match[1] ?? '') !== '' ? $match[1] : '*';
                $suppressions[] = new Suppression(
                    rule: $rule,
                    reason: self::extractReason($match[2] ?? null),
                    line: $docComment->getStartLine(),
                    type: SuppressionType::File,
                );
            }
        }

        return $suppressions;
    }

    private static function extractReason(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        // Strip trailing docblock closing characters (e.g., "*/") and whitespace
        $trimmed = rtrim($raw, " \t*/");

        return $trimmed !== '' ? $trimmed : null;
    }
}
