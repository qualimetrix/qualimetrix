<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\ExcludeBinding;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * The finding for a selector the run could not check, because a directory it
 * might have matched inside would not list.
 *
 * A family of its own rather than a second shape inside
 * {@see UnmatchedExcludeAudit}, because it is a second *identity*: a family is
 * what one frozen occurrence kind belongs to, and one file declaring two of
 * them has no answer to "which one is frozen here".
 */
final readonly class UnjudgedExcludeFinding
{
    /**
     * "Nothing matched this pattern" and "nobody could look" are two facts
     * about one pattern, and a project that accepted the first has not thereby
     * accepted the second — so this identity is deliberately not
     * {@see UnmatchedExcludeAudit}'s. Pinned as a literal for the same reason
     * every sibling is: a channel rename must not move `occurrence` under an
     * already-accepted baseline entry.
     *
     * The blocking directory stays out of the key and in the message. It is
     * where the evidence stopped, not what the finding is about, and letting
     * it in would churn the identity whenever the unreadable mount moves.
     */
    private const string OCCURRENCE_KIND = 'unjudged-exclude-pattern';

    public static function forPattern(string $display, AbsolutePath $directory, AbsolutePath $projectRoot): Finding
    {
        $blocking = $directory->tryRelativizeTo($projectRoot)?->value() ?? $directory->value();

        return new Finding(
            location: Location::none(),
            subject: MetricSubject::aggregate(SymbolPath::forProject()),
            symbolPath: SymbolPath::forProject(),
            ruleName: UnmatchedExcludeOptions::CHANNEL,
            code: UnmatchedExcludeOptions::CHANNEL,
            message: \sprintf(
                'The exclude pattern "%s" could not be checked: the directory "%s" could not be listed, and a'
                . ' directory the pattern would have matched may sit inside it. This report says nothing about'
                . ' whether that pattern is still needed.',
                $display,
                $blocking,
            ),
            severity: Severity::Warning,
            recommendation: \sprintf(
                'Give this process permission to list "%s" and run again, or exclude it so the walk stops'
                . ' asking. Until then "%s" is neither confirmed nor reported as stale.',
                $blocking,
                $display,
            ),
            occurrenceKey: OccurrenceKey::semantic(self::OCCURRENCE_KIND, ['pattern' => $display]),
        );
    }
}
