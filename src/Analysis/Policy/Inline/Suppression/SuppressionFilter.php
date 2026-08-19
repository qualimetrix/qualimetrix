<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Suppression;

use Qualimetrix\Analysis\Finding\Contract\Filter\ViolationFilterInterface;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Analysis\Policy\Inline\Contract\AnnotationSuppressionInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\AnnotationSuppressionResult;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType;

/**
 * Filters violations based on suppression tags in code.
 *
 * Suppressions can be applied at:
 * - File level (`@qmx-ignore-file`) — suppresses all matching violations in file
 * - Symbol level (`@qmx-ignore <rule>`) — suppresses matching violations bound to the exact declaration subject
 * - Line level (`@qmx-ignore-next-line <rule>`) — suppresses matching violations on next line only
 */
final class SuppressionFilter implements ViolationFilterInterface, AnnotationSuppressionInterface
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
     * @param list<Violation> $violations
     * @param array<string, list<Suppression>> $suppressions
     */
    public function apply(array $violations, array $suppressions): AnnotationSuppressionResult
    {
        $this->clearSuppressions();
        foreach ($suppressions as $file => $fileSuppressions) {
            $this->setSuppressions($file, $fileSuppressions);
        }

        $retained = [];
        $suppressed = [];
        foreach ($violations as $violation) {
            if ($this->shouldInclude($violation)) {
                $retained[] = $violation;
            } else {
                $suppressed[] = $violation;
            }
        }

        return new AnnotationSuppressionResult($retained, $suppressed);
    }

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
            if (self::applies($file, $suppression, $violation)) {
                return false;
            }
        }

        foreach ($this->suppressions[$file] ?? [] as $suppression) {
            if ($suppression->type !== SuppressionType::Symbol && self::applies($file, $suppression, $violation)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether this one directive silences at least one of these findings.
     *
     * The usage accounting behind `annotation.unused-directive` needs the
     * answer per directive, which the indexed path above cannot give: it
     * answers "is this finding suppressed by anything". Both go through
     * {@see applies()}, so the two questions cannot drift into disagreeing
     * about what a directive covers.
     *
     * @param string $file the file the directive was authored in — the key
     *                     the caller holds it under
     * @param list<Violation> $violations
     */
    public static function suppressesAny(string $file, Suppression $suppression, array $violations): bool
    {
        foreach ($violations as $violation) {
            if (self::applies($file, $suppression, $violation)) {
                return true;
            }
        }

        return false;
    }

    /**
     * One directive against one finding: the channel selector first, then the
     * placement the directive's form implies.
     *
     * A symbol directive is bound to its declaration subject and ignores the
     * file entirely — the finding it silences is reported wherever that
     * declaration is presented. The two physical forms are bound to the file
     * they were written in, and the next-line form additionally to the line
     * after it.
     */
    private static function applies(string $file, Suppression $suppression, Violation $violation): bool
    {
        if (!$suppression->matches($violation->ruleName, $violation->violationCode)) {
            return false;
        }

        if ($suppression->type === SuppressionType::Symbol) {
            return $suppression->subject !== null
                && $suppression->subject->toCanonical() === $violation->subject->toCanonical();
        }

        if ($violation->location->pathString() !== $file) {
            return false;
        }

        if ($suppression->type === SuppressionType::File) {
            return true;
        }

        return $violation->location->line !== null
            && $violation->location->line === $suppression->line + 1;
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
