<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline\EntryBinding;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Baseline\Contract\BaselineAuditChannels;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

final class UnusedEntryFinding
{
    /** @param list<string> $removedTogether selectors that must leave in the same invocation as this one, itself included */
    public static function of(string $cause, string $selector, string $entry, string $reason, string $baselinePath, array $removedTogether = []): Finding
    {
        return new Finding(
            location: new Location(\Qualimetrix\Core\Path\PathFactory::tryProjectRelative($baselinePath, \Qualimetrix\Core\Path\AbsolutePath::fromString((string) getcwd()))),
            subject: MetricSubject::aggregate(SymbolPath::forProject()),
            symbolPath: SymbolPath::forProject(),
            ruleName: BaselineAuditChannels::UNUSED_ENTRY,
            code: BaselineAuditChannels::UNUSED_ENTRY,
            message: \sprintf('Baseline entry %s [%s] is %s: %s.', $entry, $selector, $cause, $reason),
            severity: Severity::Warning,
            recommendation: \count($removedTogether) > 1
                ? \sprintf(
                    'Remove baseline entry [%s] together with every other retired closure entry in one call: qmx baseline:cleanup %s --remove=%s.',
                    $selector,
                    escapeshellarg($baselinePath),
                    implode(' --remove=', $removedTogether),
                )
                : \sprintf(
                    'Remove baseline entry [%s] with qmx baseline:cleanup %s --remove=%s.%s',
                    $selector,
                    escapeshellarg($baselinePath),
                    $selector,
                    $cause === 'stale' ? ' Or run baseline:update after an intentional change to accepted debt.' : '',
                ),
            occurrenceKey: OccurrenceKey::semantic('baseline-unused-entry', ['cause' => $cause, 'selector' => $selector]),
        );
    }
}
