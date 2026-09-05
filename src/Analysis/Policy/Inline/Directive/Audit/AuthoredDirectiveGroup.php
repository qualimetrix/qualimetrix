<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Directive\Audit;

use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveSite;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;

/**
 * One authored `@qmx-threshold` and every binding it expanded onto.
 *
 * The unit an author edits is the tag they wrote, not the declarations it was
 * materialised on, so the sweep's unit of removal is this whole group. The
 * type exists because that unit was carried as a bare array through both
 * halves of the audit, and everything anyone knew about it — its site, its
 * subjects — lived as public statics on a class named after something else.
 *
 * **The file is a path and the subjects are subjects, because that is what the
 * run produces.** The one live producer of an override-map key is
 * `CollectionOrchestrator`, which keys the map by `$result->filePath->value()`
 * off a `RelativePath`; every key in the map is therefore already what
 * {@see RelativePath::fromString()} would make of it, and {@see of()} converts
 * without moving a bucket. That is a fact about the run rather than a
 * convention, so it is measured rather than asserted in prose:
 * `OverrideMapKeyNormalizationTest` takes the keys from a pipeline run over a
 * fixture and reddens if any one of them is not its own normalized form. It is
 * what lets {@see ThresholdDirectiveAudit::without()} index the map by
 * `$file->value()` and reach the bucket the run filled.
 *
 * The subjects are held as {@see MetricSubject}, and overlap is decided by
 * {@see MetricSubject::equals()} rather than by intersecting canonical strings.
 * The two answer alike only because the canonical form separates the identity
 * families — held by `MetricSubjectTest`, not by inspection of this class.
 *
 * @qmx-ignore health.cohesion -- Each accessor of an immutable record exposes a different one of
 *   the facts settled at construction: covers() reads the subjects, overlaps() the rule name,
 *   site() the identity. Shared-field cohesion counts that as unrelated method groups, which is
 *   what a record of independent facts looks like rather than a defect. The health.cohesion
 *   producer supports no threshold override, so `@qmx-ignore` is the only inline mechanism that
 *   can state it.
 */
final readonly class AuthoredDirectiveGroup
{
    /**
     * The one form an author can write for a threshold override, and the only
     * value {@see DirectiveSite::$form} takes for a group built here.
     */
    private const string FORM = 'threshold';

    /**
     * @param list<ThresholdOverride> $bindings
     * @param list<MetricSubject> $subjects the subjects this group covers; settled at
     *                                      construction because they are a fixed fact about an
     *                                      immutable group, and because the overlap test asks each
     *                                      group for them once per other group
     */
    private function __construct(
        public RelativePath $file,
        public int $line,
        public string $rule,
        public array $bindings,
        public array $subjects,
        private DirectiveSite $site,
    ) {}

    /**
     * @param string $fileKey the run's per-file override-map key, normalized by its producer
     * @param list<ThresholdOverride> $bindings
     */
    public static function of(string $fileKey, int $line, string $rule, array $bindings): self
    {
        $file = RelativePath::fromString($fileKey);

        /** @var array<string, MetricSubject> $subjects keyed to drop the duplicates one site expands into */
        $subjects = [];
        foreach ($bindings as $binding) {
            $subjects[$binding->subject->toCanonical()] = $binding->subject;
        }

        return new self(
            $file,
            $line,
            $rule,
            $bindings,
            array_values($subjects),
            new DirectiveSite(
                file: $file,
                line: $line,
                form: self::FORM,
                target: $rule,
            ),
        );
    }

    public function covers(MetricSubject $subject): bool
    {
        foreach ($this->subjects as $covered) {
            if ($covered->equals($subject)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the other group could hide this one: same rule, and at least one
     * subject in common.
     *
     * Asked of the group rather than of its subject list, so the one place
     * that decides what "the same subject" means stays one place.
     */
    public function overlaps(self $other): bool
    {
        if ($other->rule !== $this->rule) {
            return false;
        }

        foreach ($other->subjects as $subject) {
            if ($this->covers($subject)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The directive's identity, as one object for the group's whole life.
     *
     * **Built once and returned by identity, deliberately.**
     * {@see DirectiveMaskingCoalition::hiddenBy()} separates a candidate from
     * its fellow maskers by `!==` on this value. Recomputing it per call would
     * make every comparison true, silently leaving the candidate in its own
     * "others" set and changing which neighbour the report names.
     */
    public function site(): DirectiveSite
    {
        return $this->site;
    }
}
