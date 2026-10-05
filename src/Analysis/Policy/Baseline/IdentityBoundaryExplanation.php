<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\FindingChannel;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Baseline\Contract\CurrentMeasurement;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolLevelProjection;

/** Assembles one identity's boundary from its measured evidence and authored sources. */
final readonly class IdentityBoundaryExplanation
{
    public function __construct(private ChannelIdentityInterface $channels) {}

    /**
     * @param list<Finding> $group
     * @param array<string, list<ThresholdOverride>> $thresholdOverridesByFile
     * @param array<string, array<string, int|float>> $configuredThresholds
     */
    public function explain(
        BaselineIdentity $identity,
        array $group,
        ?MetricSubject $subject,
        array $thresholdOverridesByFile,
        array $configuredThresholds,
        CurrentMeasurement $now,
        ?EffectiveBoundaryBaselineSource $baselineSource,
    ): EffectiveBoundary {
        $configuredThreshold = self::configuredThresholdFor($configuredThresholds[$identity->channel->code] ?? [], $subject);
        $annotation = $subject !== null
            ? $this->annotationFor($identity->channel, $thresholdOverridesByFile, $subject)
            : null;

        return new EffectiveBoundary(
            $identity,
            $baselineSource,
            $configuredThreshold,
            $annotation,
            $now,
            array_values(array_map(
                static fn(Finding $finding): string => $finding->location->toString(),
                array_filter($group, static fn(Finding $finding): bool => !$finding->location->isNone()),
            )),
        );
    }

    /**
     * The boundary a channel is judged against **at the level of the subject
     * being explained**.
     *
     * A channel reports at more than one level now, so the level is what
     * chooses between its boundaries. When the subject could not be resolved
     * at all there is nothing to choose with, and a channel with one boundary
     * still has an unambiguous answer; a channel with two does not, and
     * printing either would be a guess printed as a fact.
     *
     * @param array<string, int|float> $byLevel
     */
    private static function configuredThresholdFor(array $byLevel, ?MetricSubject $subject): int|float|null
    {
        if ($subject !== null) {
            return $byLevel[SymbolLevelProjection::ofDeclaration($subject->toSymbolPath()->getType())->value] ?? null;
        }

        return \count($byLevel) === 1 ? reset($byLevel) : null;
    }

    /**
     * @param array<string, list<ThresholdOverride>> $thresholdOverridesByFile
     */
    private function annotationFor(
        FindingChannel $channel,
        array $thresholdOverridesByFile,
        MetricSubject $subject,
    ): ?ThresholdOverride {
        $producer = $this->channels->producerOf($channel->code);

        if ($producer === null) {
            return null;
        }

        $matches = [];
        foreach ($thresholdOverridesByFile as $overrides) {
            $matches = [...$matches, ...array_values(array_filter(
                $overrides,
                static fn(ThresholdOverride $override): bool => $override->subject->toCanonical() === $subject->toCanonical()
                    && $override->matches($producer),
            ))];
        }

        usort($matches, static function (ThresholdOverride $left, ThresholdOverride $right): int {
            $specificity = $right->controlScope->specificity() <=> $left->controlScope->specificity();
            if ($specificity !== 0) {
                return $specificity;
            }

            $leftSpan = $left->endLine === null ? \PHP_INT_MAX : max(0, $left->endLine - $left->line);
            $rightSpan = $right->endLine === null ? \PHP_INT_MAX : max(0, $right->endLine - $right->line);

            return $leftSpan <=> $rightSpan;
        });

        return $matches[0] ?? null;
    }

}
