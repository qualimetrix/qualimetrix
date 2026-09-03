<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Directive\Audit;

use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveSite;
use Qualimetrix\Core\Path\RelativePath;

/**
 * One authored `@qmx-threshold` and every binding it expanded onto.
 *
 * The unit an author edits is the tag they wrote, not the declarations it was
 * materialised on, so the sweep's unit of removal is this whole group. The
 * type exists because that unit was carried as a bare array through both
 * halves of the audit, and everything anyone knew about it — its site, its
 * subjects — lived as public statics on a class named after something else.
 *
 * **The field types are the ones the consumers need, not the ones a value
 * object would reach for first.** `$fileKey` is the run's per-file override map
 * key held verbatim, not a path: {@see RelativePath::fromString()} normalizes,
 * so a group carrying the normalized form would rewrite a different bucket than
 * the one the run filled. `site()` is where that conversion belongs, because a
 * site is an identity rather than an index. `$subjects` holds canonical strings
 * because its one substantive reader intersects them, and an intersection over
 * objects would compare by string conversion anyway.
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
     * @param list<string> $subjects the subjects this group covers, canonically; settled at
     *                               construction because they are a fixed fact about an
     *                               immutable group, and because the overlap test asks each
     *                               group for them once per other group
     */
    private function __construct(
        public string $fileKey,
        public int $line,
        public string $rule,
        public array $bindings,
        public array $subjects,
        private DirectiveSite $site,
    ) {}

    /**
     * @param list<ThresholdOverride> $bindings
     */
    public static function of(string $fileKey, int $line, string $rule, array $bindings): self
    {
        return new self(
            $fileKey,
            $line,
            $rule,
            $bindings,
            array_values(array_unique(array_map(
                static fn(ThresholdOverride $override): string => $override->subject->toCanonical(),
                $bindings,
            ))),
            new DirectiveSite(
                file: RelativePath::fromString($fileKey),
                line: $line,
                form: self::FORM,
                target: $rule,
            ),
        );
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
