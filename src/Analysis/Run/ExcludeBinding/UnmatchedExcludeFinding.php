<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\ExcludeBinding;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Severity;
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

    public static function forPattern(string $display, ?string $coveredBy = null): Finding
    {
        return new Finding(
            location: Location::none(),
            subject: MetricSubject::aggregate(SymbolPath::forProject()),
            symbolPath: SymbolPath::forProject(),
            ruleName: UnmatchedExcludeOptions::CHANNEL,
            code: UnmatchedExcludeOptions::CHANNEL,
            message: $coveredBy === null
                ? \sprintf('The exclude pattern "%s" matched no entry in the project, so it leaves nothing out.', $display)
                : \sprintf('The exclude pattern "%s" matched no entry outside what "%s" already removes.', $display, $coveredBy),
            severity: Severity::Warning,
            recommendation: \sprintf(
                'Check "%s" against project-relative entry paths. Exact selectors name one entry,'
                . ' subtree selectors include descendants, and regex selectors match the full path. Drop the'
                . ' entry if its target is gone.',
                $display,
            ),
            occurrenceKey: OccurrenceKey::semantic(self::OCCURRENCE_KIND, ['pattern' => $display]),
        );
    }
}
