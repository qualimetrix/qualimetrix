<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Directive;

use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\InlineDirectivePolicyInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * The finding that says one authored directive silenced nothing this run.
 *
 * Its own class because its shape is a decision, not a formatting detail: the
 * subject is the file rather than the declaration, and the two reasons for that
 * are documented on the factory below. {@see DirectiveUsage} decides *which*
 * directives are stale; what that verdict looks like when published is this.
 */
final readonly class StaleDirectiveFinding
{
    /**
     * The subject is the **file**, never a declaration the directive happened
     * to be bound to.
     *
     * Two reasons, and both are about the directive rather than the code. A
     * finding about an annotation belongs where the annotation was written,
     * and the `Location` line already says exactly where. And this is the one
     * of the four channels a project may ratchet: a declaration subject
     * carries the declaration's byte offset, so every edit above it would
     * rewrite the entry's key for reasons that have nothing to do with the
     * annotation.
     */
    public static function of(
        RelativePath $path,
        Suppression $suppression,
        Severity $severity,
    ): Finding {
        $subject = MetricSubject::aggregate(SymbolPath::forFile($path));

        return new Finding(
            location: new Location($path, $suppression->line, precise: true),
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: InlineDirectivePolicyInterface::UNUSED_DIRECTIVE_NAME,
            code: InlineDirectivePolicyInterface::UNUSED_DIRECTIVE_NAME,
            message: \sprintf(
                'Suppression "%s" matched nothing in this run — the finding it silences is gone.',
                $suppression->target(),
            ),
            severity: $severity,
            recommendation: 'Remove the annotation, or keep it and note why the finding is expected to return.',
        );
    }
}
