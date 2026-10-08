<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter;

use Qualimetrix\Analysis\Finding\Contract\Finding;

/**
 * Publishes message and recommendation separately wherever the surface has
 * room for both. Single-slot interchange formats append baseline status to
 * the message; prose prints that status on its own line.
 */
final class PublishedFinding
{
    /**
     * The message with the baseline comparison status appended — for
     * a surface with a single free-text slot.
     */
    public static function annotatedMessage(Finding $finding): string
    {
        return $finding->message . self::breachSuffix($finding);
    }

    public static function place(Finding $finding): FindingPlace
    {
        $symbol = $finding->symbolPath;

        return $symbol->getType() === \Qualimetrix\Core\Symbol\SymbolType::Project
            ? new FindingPlace(\Qualimetrix\Core\Symbol\SymbolLevel::Project, '[project]')
            : new FindingPlace(\Qualimetrix\Core\Symbol\SymbolLevel::Namespace_, \Qualimetrix\Core\Symbol\SymbolPath::forNamespace($symbol->namespace ?? '')->toString());
    }

    /** The namespace label in a single text slot without a physical location. */
    public static function locatedMessage(Finding $finding): string
    {
        $place = self::place($finding);
        $prefix = $finding->location->file === null && $place->level === \Qualimetrix\Core\Symbol\SymbolLevel::Namespace_
            ? $place->name . ': '
            : '';

        return $prefix . self::annotatedMessage($finding);
    }

    /**
     * The dependency edge a finding is about, as the structured surfaces
     * publish it under `edge`.
     *
     * @return ?array{target: string, type?: string}
     */
    public static function edge(Finding $finding): ?array
    {
        if ($finding->dependencyTarget === null) {
            return null;
        }

        $target = $finding->dependencyTarget->toCanonical();
        if ($finding->dependencyType === null) {
            return ['target' => $target];
        }

        return [
            'type' => $finding->dependencyType->value,
            'target' => $target,
        ];
    }

    /** @return array{acceptedLevel: ?array{shape: string, describe: string, count: int}, baselineVerdict: ?string, baselineReason: ?string} */
    public static function baselineFields(Finding $finding): array
    {
        $accepted = $finding->acceptedLevel;

        return [
            'acceptedLevel' => $accepted === null ? null : [
                'shape' => $accepted->shape()->value,
                'describe' => $accepted->describe(),
                'count' => $accepted->count,
            ],
            'baselineVerdict' => $accepted === null ? null : ($finding->uncomparedReason === null ? 'breached' : 'not-compared'),
            'baselineReason' => $accepted === null ? null : $finding->uncomparedReason,
        ];
    }

    /** Appends the accepted level and comparison status when a baseline addressed the finding. */
    private static function breachSuffix(Finding $finding): string
    {
        $breach = AcceptedLevelNarrator::describe($finding);

        return $breach === null ? '' : \sprintf(' (%s)', $breach);
    }
}
