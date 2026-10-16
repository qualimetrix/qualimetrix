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
    public static function of(string $cause, string $selector, string $entry, string $reason, string $baselinePath): Finding
    {
        return new Finding(
            location: Location::none(),
            subject: MetricSubject::aggregate(SymbolPath::forProject()),
            symbolPath: SymbolPath::forProject(),
            ruleName: BaselineAuditChannels::UNUSED_ENTRY,
            code: BaselineAuditChannels::UNUSED_ENTRY,
            message: \sprintf('Baseline entry %s [%s] is %s: %s.', $entry, $selector, $cause, $reason),
            severity: Severity::Warning,
            recommendation: \sprintf(
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
