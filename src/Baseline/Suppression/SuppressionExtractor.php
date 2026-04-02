<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline\Suppression;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use Qualimetrix\Core\Suppression\Suppression;
use Qualimetrix\Core\Suppression\SuppressionType;

/**
 * Extracts suppression tags from docblock comments and regular PHP comments.
 *
 * Supported comment styles:
 *
 * - PHPDoc docblocks: /** @qmx-ignore ... * /
 * - Line comments: // @qmx-ignore ...
 * - Block comments: /* @qmx-ignore ... * /
 *
 * Supported tags:
 * - @qmx-ignore <rule> [reason]
 * - @qmx-ignore-next-line <rule> [reason]
 * - @qmx-ignore-file [rule] [reason]
 *
 * Note: inline same-line comments (e.g., `$x = foo(); // @qmx-ignore rule`) are not supported.
 * Only separate-line comments are recognized.
 */
final readonly class SuppressionExtractor
{
    private const PATTERN_SYMBOL = '/@qmx-ignore(?!-next-line|-file)(?![\w-])\s+([\w.*-]+)(?:[^\S\n\r]+([^\n\r]+))?/';
    private const PATTERN_NEXT_LINE = '/@qmx-ignore-next-line(?![\w-])\s+([\w.*-]+)(?:[^\S\n\r]+([^\n\r]+))?/';
    private const PATTERN_FILE = '/@qmx-ignore-file(?![\w-])(?:\s+([\w.*-]+)(?:[^\S\n\r]+([^\n\r]+))?)?/';

    /**
     * Extracts suppression tags from node's docblock and regular comments.
     *
     * @return list<Suppression>
     */
    public function extract(Node $node): array
    {
        $suppressions = [];
        $nodeEndLine = $node->getEndLine() > 0 ? $node->getEndLine() : null;

        // Process docblock
        $docComment = $node->getDocComment();
        if ($docComment !== null) {
            $suppressions = $this->extractFromText(
                $docComment->getText(),
                $docComment->getStartLine(),
                $docComment->getEndLine(),
                $nodeEndLine,
            );
        }

        // Process regular comments (skip Doc instances, already handled above)
        foreach ($node->getComments() as $comment) {
            if ($comment instanceof Doc) {
                continue;
            }

            foreach ($this->extractFromText(
                $comment->getText(),
                $comment->getStartLine(),
                $comment->getEndLine(),
                $nodeEndLine,
            ) as $suppression) {
                $suppressions[] = $suppression;
            }
        }

        return $suppressions;
    }

    /**
     * Extracts file-level suppressions from node's docblock and regular comments.
     *
     * @return list<Suppression>
     */
    public function extractFileLevelSuppressions(Node $node): array
    {
        $suppressions = [];

        // Check docblock
        $docComment = $node->getDocComment();
        if ($docComment !== null) {
            $text = $docComment->getText();
            if (str_contains($text, '@qmx-ignore-file')) {
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
            }
        }

        // Check regular comments (skip Doc instances)
        foreach ($node->getComments() as $comment) {
            if ($comment instanceof Doc) {
                continue;
            }

            $text = $comment->getText();
            if (!str_contains($text, '@qmx-ignore-file')) {
                continue;
            }

            if (preg_match_all(self::PATTERN_FILE, $text, $matches, \PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $rule = ($match[1] ?? '') !== '' ? $match[1] : '*';
                    $suppressions[] = new Suppression(
                        rule: $rule,
                        reason: self::extractReason($match[2] ?? null),
                        line: $comment->getStartLine(),
                        type: SuppressionType::File,
                    );
                }
            }
        }

        return $suppressions;
    }

    /**
     * Extracts suppressions from a comment text block.
     *
     * @return list<Suppression>
     */
    private function extractFromText(string $text, int $startLine, int $endLine, ?int $nodeEndLine): array
    {
        $suppressions = [];

        // Extract file-level suppressions
        if (preg_match_all(self::PATTERN_FILE, $text, $matches, \PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $rule = ($match[1] ?? '') !== '' ? $match[1] : '*';
                $suppressions[] = new Suppression(
                    rule: $rule,
                    reason: self::extractReason($match[2] ?? null),
                    line: $startLine,
                    type: SuppressionType::File,
                );
            }
        }

        // Extract next-line suppressions (use endLine so filter targets endLine+1)
        if (preg_match_all(self::PATTERN_NEXT_LINE, $text, $matches, \PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $suppressions[] = new Suppression(
                    rule: $match[1],
                    reason: self::extractReason($match[2] ?? null),
                    line: $endLine,
                    type: SuppressionType::NextLine,
                );
            }
        }

        // Extract symbol-level suppressions (plain @qmx-ignore, not -next-line or -file)
        if (preg_match_all(self::PATTERN_SYMBOL, $text, $matches, \PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $suppressions[] = new Suppression(
                    rule: $match[1],
                    reason: self::extractReason($match[2] ?? null),
                    line: $startLine,
                    type: SuppressionType::Symbol,
                    endLine: $nodeEndLine,
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
