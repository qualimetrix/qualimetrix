<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Suppression;

use Qualimetrix\Analysis\Finding\Contract\Filter\FindingFilterInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Policy\Inline\Contract\AnnotationSuppressionInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\AnnotationSuppressionResult;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\SuppressionType;
use Qualimetrix\Analysis\Policy\Inline\Directive\DirectiveChannelBan;

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
     * @var array<string, array<string, list<Suppression>>> exact subject canonical => file => symbol controls
     */
    private array $symbolSuppressionsBySubject = [];

    /**
     * @param list<Finding> $findings
     * @param array<string, list<Suppression>> $suppressions
     */
    public function apply(array $findings, array $suppressions): AnnotationSuppressionResult
    {
        // Loads every file first and indexes once. Routing each file through
        // `setSuppressions()` cost one index rebuild per file, which is
        // quadratic in the number of annotated files and lands in the phase
        // this pipeline declares sequential and cheap: measured at 0.6s for a
        // thousand annotated files and 9.1s for four thousand.
        $this->clearSuppressions();
        foreach ($suppressions as $file => $fileSuppressions) {
            $this->suppressions[$file] = $fileSuppressions;
            $this->indexSymbolControls($file, $fileSuppressions);
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
     * Only this file's entries move: the index is keyed by subject **and** by
     * the file the directive was written in, so replacing one file's controls
     * neither reads nor rewrites another's.
     *
     * @param list<Suppression> $suppressions
     */
    public function setSuppressions(string $file, array $suppressions): void
    {
        $this->forgetSymbolControls($file);
        $this->suppressions[$file] = $suppressions;
        $this->indexSymbolControls($file, $suppressions);
    }

    /**
     * Returns true if finding should be included (not suppressed).
     * Returns false if finding is suppressed (should be filtered out).
     */
    public function shouldInclude(Finding $finding): bool
    {
        $file = $finding->location->pathString();

        foreach ($this->symbolSuppressionsBySubject[$finding->subject->toCanonical()] ?? [] as $authoredIn) {
            foreach ($authoredIn as $suppression) {
                if (self::applies($file, $suppression, $finding)) {
                    return false;
                }
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
     * A third reader asks a narrower question elsewhere —
     * `Reporting\FindingProjection\DirectiveSuppressorResolver` names the
     * line of the directive that silenced an already-suppressed finding — and
     * it reproduces the placement half of {@see applies()} rather than calling
     * it, because Reporting holds these values as `mixed`. What it leaves out
     * is the channel ban, and the set it is asked about is this filter's own
     * output: a banned channel never reaches it, because the ban is what kept
     * the finding out of that set.
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
     *
     * A directive the extractor refused reaches this method like any other —
     * the report and the filter read one list — and is stopped by the selector
     * question below, which it answers `false` to for every channel.
     *
     * {@see DirectiveChannelBan} is asked first and about the finding alone:
     * no directive silences a banned channel, the form that names it having
     * been refused where it was written and the form that names nothing having
     * covered it only by covering everything. Asking here rather than in the
     * two callers is what makes publication and the usage accounting one
     * answer — both reach a finding through this method — and it leaves the
     * finding *retained* rather than lifted out of the pipeline, so it goes on
     * through the exclusions, the baseline ceiling and the git scope like the
     * ordinary debt it is.
     */
    private static function applies(string $file, Suppression $suppression, Finding $finding): bool
    {
        if (DirectiveChannelBan::covers($finding->code)) {
            return false;
        }

        if (!$suppression->matches($finding->code, $finding->level())) {
            return false;
        }

        if ($suppression->type === SuppressionType::Symbol) {
            return $suppression->binding?->subject->toCanonical() === $finding->subject->toCanonical();
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

    /** @param list<Suppression> $suppressions */
    private function indexSymbolControls(string $file, array $suppressions): void
    {
        foreach ($suppressions as $suppression) {
            $binding = $suppression->binding;

            if ($suppression->type !== SuppressionType::Symbol || $binding === null) {
                continue;
            }

            $this->symbolSuppressionsBySubject[$binding->subject->toCanonical()][$file][] = $suppression;
        }
    }

    private function forgetSymbolControls(string $file): void
    {
        foreach ($this->suppressions[$file] ?? [] as $suppression) {
            $binding = $suppression->binding;

            if ($binding === null) {
                continue;
            }

            $subject = $binding->subject->toCanonical();
            unset($this->symbolSuppressionsBySubject[$subject][$file]);

            if (($this->symbolSuppressionsBySubject[$subject] ?? []) === []) {
                unset($this->symbolSuppressionsBySubject[$subject]);
            }
        }
    }
}
