<?php

declare(strict_types=1);

namespace AiMessDetector\Baseline\Suppression;

use AiMessDetector\Core\Violation\Filter\ViolationFilterInterface;
use AiMessDetector\Core\Violation\Violation;

/**
 * Filters violations based on suppression tags in code.
 *
 * Suppressions can be applied at:
 * - File level (@aimd-ignore-file) — suppresses all matching violations in file
 * - Symbol level (@aimd-ignore <rule>) — suppresses matching violations within the symbol's line range
 * - Line level (@aimd-ignore-next-line <rule>) — suppresses matching violations on next line only
 */
final class SuppressionFilter implements ViolationFilterInterface
{
    /**
     * @var array<string, list<Suppression>> file => suppressions
     */
    private array $suppressions = [];

    /**
     * Sets suppressions for a file (replaces any existing).
     *
     * @param list<Suppression> $suppressions
     */
    public function setSuppressions(string $file, array $suppressions): void
    {
        $this->suppressions[$file] = $suppressions;
    }

    /**
     * Returns true if violation should be included (not suppressed).
     * Returns false if violation is suppressed (should be filtered out).
     */
    public function shouldInclude(Violation $violation): bool
    {
        $file = $violation->location->file;

        if (!isset($this->suppressions[$file])) {
            return true; // No suppressions — pass through
        }

        $violationLine = $violation->location->line;

        foreach ($this->suppressions[$file] as $suppression) {
            if (!$suppression->matches($violation->violationCode)) {
                continue;
            }

            switch ($suppression->type) {
                case SuppressionType::File:
                    return false; // File-level: suppress all matching violations

                case SuppressionType::Symbol:
                    // Symbol-level: suppress matching violations at or after the suppression line,
                    // but only up to the symbol's end line (if known)
                    // Do NOT suppress file/namespace-level violations (line=null)
                    if ($violationLine !== null
                        && $violationLine >= $suppression->line
                        && ($suppression->endLine === null || $violationLine <= $suppression->endLine)
                    ) {
                        return false;
                    }
                    break;

                case SuppressionType::NextLine:
                    // Next-line: suppress only violations on the exact next line
                    if ($violationLine !== null && $violationLine === $suppression->line + 1) {
                        return false;
                    }
                    break;
            }
        }

        return true;
    }

    /**
     * Clears all stored suppressions.
     *
     * Prevents accumulation when the singleton is reused across multiple runs.
     */
    public function clearSuppressions(): void
    {
        $this->suppressions = [];
    }

    /**
     * Returns violations that were suppressed.
     *
     * @param list<Violation> $allViolations All violations before filtering
     *
     * @return list<Violation> Suppressed violations
     */
    public function getSuppressedViolations(array $allViolations): array
    {
        return array_values(array_filter(
            $allViolations,
            fn(Violation $v) => !$this->shouldInclude($v),
        ));
    }
}
