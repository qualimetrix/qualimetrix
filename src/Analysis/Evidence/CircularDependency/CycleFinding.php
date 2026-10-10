<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\CircularDependency;

use Qualimetrix\Analysis\Evidence\CircularDependency\Contract\CircularDependencyPreparationInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\OccurrenceKey;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

final class CycleFinding
{
    /**
     * Frozen to today's channel spelling on purpose — it does not follow a
     * future rename of the producer. Changing this value moves the
     * `occurrence` of every already-accepted finding on this channel.
     */
    private const string OCCURRENCE_KIND = 'architecture.circular-dependency';

    /** @param non-empty-list<string> $memberCanonicals */
    public static function of(Cycle $cycle, MetricSubject $projectSubject, Severity $severity, array $memberCanonicals): Finding
    {
        $category = $cycle->getSizeCategory();
        $size = $cycle->getSize();

        $pathDisplay = $category === 'large'
            ? $cycle->toTruncatedShortString(5)
            : $cycle->toShortString();

        $message = \sprintf(
            'Circular dependency (%d classes): %s',
            $size,
            $pathDisplay,
        );

        $recommendation = self::recommendation($cycle, $category);

        return new Finding(
            location: Location::none(),
            subject: $projectSubject,
            symbolPath: SymbolPath::forProject(),
            ruleName: CircularDependencyPreparationInterface::PRODUCER_RULE_NAME,
            code: CircularDependencyPreparationInterface::PRODUCER_RULE_NAME,
            message: $message,
            severity: $severity,
            metricValue: $size,
            recommendation: $recommendation,
            occurrenceKey: OccurrenceKey::semantic(self::OCCURRENCE_KIND, [
                'members' => implode(',', $memberCanonicals),
            ]),
        );
    }

    /** @param 'small'|'medium'|'large' $category */
    private static function recommendation(
        Cycle $cycle,
        string $category,
    ): string {
        $guidance = match ($category) {
            'small' => \sprintf(
                'Cycle path: %s (%d classes). Break by introducing an interface to invert one dependency.',
                $cycle->toShortString(),
                $cycle->getSize(),
            ),
            'medium' => \sprintf(
                'Cycle path: %s (%d classes). Consider extracting a shared abstraction layer or splitting into smaller modules.',
                $cycle->toShortString(),
                $cycle->getSize(),
            ),
            'large' => \sprintf(
                'Large cycle (%d classes) — focus on the entry-point classes: %s. '
                . 'Break the cycle incrementally by introducing interfaces at key boundaries.',
                $cycle->getSize(),
                $cycle->toTruncatedShortString(3),
            ),
        };

        return $guidance;
    }

}
