<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Suppression;

use Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Policy\Inline\Contract\AnnotationSuppressionInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\AnnotationSuppressionResult;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType;

/**
 * Filters findings based on suppression tags in code.
 *
 * Suppressions can be applied at:
 * - File level (`@qmx-ignore-file`) — suppresses all matching findings in file
 * - Symbol level (`@qmx-ignore <rule>`) — suppresses matching findings bound to the exact declaration subject
 * - Line level (`@qmx-ignore-next-line <rule>`) — suppresses matching findings on next line only
 */
final class SuppressionFilter implements FindingFilterInterface, AnnotationSuppressionInterface
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
     * @param list<Finding> $findings
     * @param array<string, list<Suppression>> $suppressions
     */
    public function apply(array $findings, array $suppressions): AnnotationSuppressionResult
    {
        $this->clearSuppressions();
        foreach ($suppressions as $file => $fileSuppressions) {
            $this->setSuppressions($file, $fileSuppressions);
        }

        $retained = [];
        $suppressed = [];
        foreach ($findings as $finding) {
            if ($this->shouldInclude($finding)) {
                $retained[] = $finding;
            } else {
                $suppressed[] = $finding;
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
     * Returns true if finding should be included (not suppressed).
     * Returns false if finding is suppressed (should be filtered out).
     */
    public function shouldInclude(Finding $finding): bool
    {
        $file = $finding->location->pathString();

        foreach ($this->symbolSuppressionsBySubject[$finding->subject->toCanonical()] ?? [] as $suppression) {
            if (self::applies($file, $suppression, $finding)) {
                return false;
            }
        }

        foreach ($this->suppressions[$file] ?? [] as $suppression) {
            if ($suppression->type !== SuppressionType::Symbol && self::applies($file, $suppression, $finding)) {
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
     * @param list<Finding> $findings
     */
    public static function suppressesAny(string $file, Suppression $suppression, array $findings): bool
    {
        foreach ($findings as $finding) {
            if (self::applies($file, $suppression, $finding)) {
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
    private static function applies(string $file, Suppression $suppression, Finding $finding): bool
    {
        if (!$suppression->matches($finding->code, $finding->level())) {
            return false;
        }

        if ($suppression->type === SuppressionType::Symbol) {
            return $suppression->subject !== null
                && $suppression->subject->toCanonical() === $finding->subject->toCanonical();
        }

        if ($finding->location->pathString() !== $file) {
            return false;
        }

        if ($suppression->type === SuppressionType::File) {
            return true;
        }

        return $finding->location->line !== null
            && $finding->location->line === $suppression->line + 1;
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
     * Returns findings that were suppressed.
     *
     * @param list<Finding> $allFindings All findings before filtering
     *
     * @return list<Finding> Suppressed findings
     */
    public function getSuppressedFindings(array $allFindings): array
    {
        return array_values(array_filter(
            $allFindings,
            fn(Finding $v) => !$this->shouldInclude($v),
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
