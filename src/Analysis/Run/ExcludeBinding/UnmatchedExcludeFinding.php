<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\ExcludeBinding;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

/** Builds the authored-pattern occurrence whose project contains no matching directory. */
final readonly class UnmatchedExcludeFinding
{
    /**
     * What one finding here is about: the pattern.
     *
     * Without it every finding on this channel shared one baseline identity —
     * project subject, one channel, no occurrence — and the entry bounded
     * their *number*. Accepting two stale patterns then accepted any two,
     * including one introduced by the next edit. A value that points at
     * nothing is the whole content of the finding, so it is the whole content
     * of the identity too.
     */
    private const string OCCURRENCE_KIND = 'unmatched-exclude-pattern';

    public static function forPattern(PathPattern $pattern): Finding
    {
        $display = $pattern->definition->display();

        return new Finding(
            location: Location::none(),
            subject: MetricSubject::aggregate(SymbolPath::forProject()),
            symbolPath: SymbolPath::forProject(),
            ruleName: UnmatchedExcludeOptions::CHANNEL,
            code: UnmatchedExcludeOptions::CHANNEL,
            message: \sprintf(
                'The exclude pattern "%s" matched no directory anywhere in the project, so nothing was left out'
                . ' for it. Every file it was written to skip was measured, and this report covers them.',
                $display,
            ),
            severity: Severity::Warning,
            recommendation: \sprintf(
                'Check "%s" against project-relative directory paths. Exact selectors name one directory,'
                . ' subtree selectors include descendants, and regex selectors match the full path. Drop the'
                . ' entry if its target is gone.',
                $display,
            ),
            occurrenceKey: OccurrenceKey::semantic(self::OCCURRENCE_KIND, ['pattern' => $display]),
        );
    }
}
