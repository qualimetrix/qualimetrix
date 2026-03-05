<?php

declare(strict_types=1);

namespace AiMessDetector\Baseline\Suppression;

use AiMessDetector\Core\Violation\Filter\ViolationFilterInterface;
use AiMessDetector\Core\Violation\Violation;

/**
 * Filters violations based on suppression tags in code.
 *
 * Suppressions can be applied at:
 * - File level (@aimd-ignore-file)
 * - Symbol level (@aimd-ignore <rule>)
 * - Line level (@aimd-ignore-next-line <rule>)
 */
final class SuppressionFilter implements ViolationFilterInterface
{
    /**
     * @var array<string, list<Suppression>> file => suppressions
     */
    private array $suppressions = [];

    /**
     * Adds suppressions for a file.
     *
     * @param list<Suppression> $suppressions
     */
    public function addSuppressions(string $file, array $suppressions): void
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

        foreach ($this->suppressions[$file] as $suppression) {
            if ($suppression->matches($violation->violationCode)) {
                return false; // Suppressed — filter out
            }
        }

        return true;
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
