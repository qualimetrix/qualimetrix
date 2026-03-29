<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Duplication;

/**
 * Extracts a short content preview from duplicated code blocks.
 *
 * Used during duplication detection to generate human-readable hints
 * that help developers understand what the duplicated code looks like
 * without opening the file.
 */
final class ContentHintExtractor
{
    private const MAX_HINT_LENGTH = 80;
    private const MAX_LINES_TO_SCAN = 10;
    private const MIN_MEANINGFUL_LINE_LENGTH = 3;

    /**
     * Extracts a content hint from the given source lines.
     *
     * Takes the first 2-3 meaningful lines (skipping blank lines and brace-only lines),
     * normalizes whitespace, and truncates to ~80 characters.
     *
     * @param string $source Full file source code
     * @param int $startLine 1-based start line of the duplicated block
     * @param int $endLine 1-based end line of the duplicated block
     */
    public function extract(string $source, int $startLine, int $endLine): ?string
    {
        $allLines = explode("\n", $source);
        $totalLines = \count($allLines);

        if ($startLine < 1 || $startLine > $totalLines) {
            return null;
        }

        $endLine = min($endLine, $totalLines);
        $scanEnd = min($startLine - 1 + self::MAX_LINES_TO_SCAN, $endLine);

        $meaningfulLines = [];

        for ($i = $startLine - 1; $i < $scanEnd; $i++) {
            $line = trim($allLines[$i]);

            // Skip empty lines and brace-only lines
            if ($line === '' || $line === '{' || $line === '}' || $line === '};') {
                continue;
            }

            // Skip lines that are too short to be meaningful
            if (\strlen($line) < self::MIN_MEANINGFUL_LINE_LENGTH) {
                continue;
            }

            $meaningfulLines[] = $line;

            if (\count($meaningfulLines) >= 3) {
                break;
            }
        }

        if ($meaningfulLines === []) {
            return null;
        }

        // Join lines with space separator, collapse multiple whitespace
        $hint = implode(' ', $meaningfulLines);
        $hint = (string) preg_replace('/\s+/', ' ', $hint);
        $hint = trim($hint);

        if ($hint === '') {
            return null;
        }

        // Truncate to max length
        if (\strlen($hint) > self::MAX_HINT_LENGTH) {
            // Try to cut at a word boundary
            $truncated = substr($hint, 0, self::MAX_HINT_LENGTH - 3);
            $lastSpace = strrpos($truncated, ' ');

            if ($lastSpace !== false && $lastSpace > self::MAX_HINT_LENGTH * 0.5) {
                $truncated = substr($truncated, 0, $lastSpace);
            }

            $hint = $truncated . '...';
        }

        return $hint;
    }
}
