<?php

declare(strict_types=1);

namespace AiMessDetector\Baseline\Suppression;

use PhpParser\Node;

/**
 * Extracts suppression tags from docblock comments.
 *
 * Supported tags:
 * - @aimd-ignore <rule> [reason]
 * - @aimd-ignore-next-line <rule> [reason]
 * - @aimd-ignore-file
 */
final readonly class SuppressionExtractor
{
    private const PATTERN = '/@aimd-ignore(?:-next-line|-file)?\s+(\w+|\*)(?:\s+([^\n\r*]+))?/';

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

        if (preg_match_all(self::PATTERN, $text, $matches, \PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $reason = isset($match[2]) ? trim($match[2]) : null;
                $suppressions[] = new Suppression(
                    rule: $match[1],
                    reason: $reason !== '' ? $reason : null,
                    line: $docComment->getStartLine(),
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

        // File-level suppression ignores all rules
        return [
            new Suppression(
                rule: '*',
                reason: null,
                line: $docComment->getStartLine(),
            ),
        ];
    }
}
