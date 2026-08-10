<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline\Suppression;

use Qualimetrix\Core\Suppression\Suppression;
use Qualimetrix\Core\Suppression\SuppressionType;
use Qualimetrix\Core\Violation\Filter\ViolationFilterInterface;
use Qualimetrix\Core\Violation\Violation;

/**
 * Filters violations based on suppression tags in code.
 *
 * Suppressions can be applied at:
 * - File level (`@qmx-ignore-file`) — suppresses all matching violations in file
 * - Symbol level (`@qmx-ignore <rule>`) — suppresses matching violations bound to the exact declaration subject
 * - Line level (`@qmx-ignore-next-line <rule>`) — suppresses matching violations on next line only
 */
final class SuppressionFilter implements ViolationFilterInterface
{
    /**
     * @var array<string, list<Suppression>> file => suppressions
     */
    private array $suppressions = [];

    /**
     * @var array<string, list<Suppression>> exact subject canonical => symbol controls
     */
    private array $symbolSuppressionsBySubject = [];

    /**
     * Sets suppressions for a file (replaces any existing).
     *
     * @param list<Suppression> $suppressions
     */
    public function setSuppressions(string $file, array $suppressions): void
    {
        $this->suppressions[$file] = $suppressions;
        $this->rebuildSymbolSuppressionsBySubject();
    }

    /**
     * Returns true if violation should be included (not suppressed).
     * Returns false if violation is suppressed (should be filtered out).
     */
    public function shouldInclude(Violation $violation): bool
    {
        $file = $violation->location->pathString();

        foreach ($this->symbolSuppressionsBySubject[$violation->subject->toCanonical()] ?? [] as $suppression) {
            if (!$suppression->matches($violation->violationCode)) {
                continue;
            }

            return false;
        }

        if (!isset($this->suppressions[$file])) {
            return true; // No physical suppressions at the presentation location
        }

        $violationLine = $violation->location->line;
        foreach ($this->suppressions[$file] as $suppression) {
            if (!$suppression->matches($violation->violationCode)) {
                continue;
            }

            if ($suppression->type === SuppressionType::File) {
                return false;
            }

            if ($suppression->type === SuppressionType::NextLine
                && $violationLine !== null
                && $violationLine === $suppression->line + 1
            ) {
                return false;
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
        $this->symbolSuppressionsBySubject = [];
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

    private function rebuildSymbolSuppressionsBySubject(): void
    {
        $this->symbolSuppressionsBySubject = [];

        foreach ($this->suppressions as $suppressions) {
            foreach ($suppressions as $suppression) {
                if ($suppression->type !== SuppressionType::Symbol || $suppression->subject === null) {
                    continue;
                }

                $this->symbolSuppressionsBySubject[$suppression->subject->toCanonical()][] = $suppression;
            }
        }
    }
}
