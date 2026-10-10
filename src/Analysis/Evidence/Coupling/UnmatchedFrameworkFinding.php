<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

/** Finding for one authored framework selector that classified nothing. */
final readonly class UnmatchedFrameworkFinding
{
    public static function of(NamespacePattern $selector, string $channel, string $occurrenceKind): Finding
    {
        $display = $selector->definition->display();

        return new Finding(
            location: Location::none(),
            subject: MetricSubject::aggregate(SymbolPath::forProject()),
            symbolPath: SymbolPath::forProject(),
            ruleName: $channel,
            code: $channel,
            message: \sprintf(
                'The framework namespace selector "%s" matched no class the run analysed or depends on. Nothing was moved'
                . ' out of the application scope for it, so "coupling.cbo-app" still counts every class it was'
                . ' written to exclude and "coupling.ce-framework" counts none of them.',
                $display,
            ),
            severity: Severity::Warning,
            recommendation: \sprintf(
                'Check "%s" against the names the code really imports. Use exact for one name, subtree for a namespace'
                . ' and all descendants, or regex for an explicit delimiterless PCRE fragment. Drop the entry if the'
                . ' dependency is gone.',
                $display,
            ),
            occurrenceKey: OccurrenceKey::semantic($occurrenceKind, ['selector' => $display]),
        );
    }

}
