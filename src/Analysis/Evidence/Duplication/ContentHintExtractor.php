<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Duplication;

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

        $meaningfulLines = $this->collectMeaningfulLines($allLines, $startLine - 1, $scanEnd);

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

        return $this->truncateHint($hint);
    }

    /**
     * Scans lines `[$fromIndex, $toIndex)`, skipping blank/brace-only/too-short
     * lines, and returns up to the first 3 meaningful ones (trimmed).
     *
     * @param list<string> $allLines
     *
     * @return list<string>
     */
    private function collectMeaningfulLines(array $allLines, int $fromIndex, int $toIndex): array
    {
        $meaningfulLines = [];

        for ($i = $fromIndex; $i < $toIndex; $i++) {
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

        return $meaningfulLines;
    }

    /**
     * Truncates a hint to {@see MAX_HINT_LENGTH}, preferring a word boundary
     * cut, and appends an ellipsis when truncated.
     */
    private function truncateHint(string $hint): string
    {
        if (\strlen($hint) <= self::MAX_HINT_LENGTH) {
            return $hint;
        }

        // Try to cut at a word boundary
        $truncated = substr($hint, 0, self::MAX_HINT_LENGTH - 3);
        $lastSpace = strrpos($truncated, ' ');

        if ($lastSpace !== false && $lastSpace > self::MAX_HINT_LENGTH * 0.5) {
            $truncated = substr($truncated, 0, $lastSpace);
        }

        return $truncated . '...';
    }
}
