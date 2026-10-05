<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryMode;
use Qualimetrix\Analysis\Policy\Baseline\BoundaryExplanation;
use Qualimetrix\Analysis\Policy\Baseline\BoundaryExplanationStatus;
use Qualimetrix\Analysis\Policy\Baseline\Contract\CurrentMeasurement;
use Qualimetrix\Analysis\Policy\Baseline\EffectiveBoundary;
use Qualimetrix\Analysis\Policy\Baseline\EffectiveBoundaryBaselineSource;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * How `baseline:explain` spells a {@see BoundaryExplanation} on the console —
 * the command decides what to explain, this class only how it reads.
 */
final class BaselineExplanationRenderer
{
    private function __construct() {}

    public static function render(BoundaryExplanation $explanation, OutputInterface $output): void
    {
        $output->writeln(\sprintf('Subject: <info>%s</info>', $explanation->subjectKey));

        if ($explanation->status === BoundaryExplanationStatus::BaselineOnly) {
            $output->writeln('  <comment>Baseline only: this subject is absent from the current analysis scope or result.</comment>');
        }

        foreach ($explanation->unidentifiedEntries as $entry) {
            $output->writeln('');
            $output->writeln(\sprintf(
                '  <comment>Unreadable baseline entry [%s] (channel %s): %s — %s</comment>',
                $entry->selector->value,
                $entry->channelKey ?? '(none)',
                $entry->reason->description(),
                $entry->detail,
            ));
        }

        if ($explanation->boundaries === []) {
            $output->writeln('');
            $output->writeln($explanation->status === BoundaryExplanationStatus::BaselineOnly
                ? '  <comment>The baseline names this subject, but no entry forms an applicable boundary.</comment>'
                : '  <comment>Nothing currently reports on this measured subject.</comment>');

            return;
        }

        foreach ($explanation->boundaries as $boundary) {
            $output->writeln('');
            $output->writeln(\sprintf('  Channel: <info>%s</info>', $boundary->identity->channel->code));

            if ($boundary->identity->edge !== null) {
                $output->writeln(\sprintf('    Edge: %s', $boundary->identity->edge->target));
            }

            self::renderOccurrence($boundary, $output);

            $output->writeln(\sprintf('    baseline:      %s', self::describeBaseline($boundary->baseline)));
            $output->writeln(\sprintf('    now:           %s', self::describeCurrent($boundary)));
            $output->writeln(\sprintf('    qmx.yaml:      %s', self::describeConfigured($boundary)));
            $output->writeln(\sprintf('    annotation:    %s', self::describeAnnotation($boundary->annotation)));
        }
    }

    /**
     * The occurrence, and where its findings sit now, for an identity that
     * carries one: sections of one channel and subject then differ in more
     * than their numbers. A boundary nothing reports now has no location to
     * print, and its occurrence is the only handle left on it.
     */
    private static function renderOccurrence(EffectiveBoundary $boundary, OutputInterface $output): void
    {
        if ($boundary->identity->occurrenceKey === null) {
            return;
        }

        $output->writeln(\sprintf('    Occurrence: %s', $boundary->identity->occurrenceKey));

        if ($boundary->currentLocations !== []) {
            $output->writeln(\sprintf('    Reported at: %s', implode(', ', $boundary->currentLocations)));
        }
    }

    private static function describeBaseline(?EffectiveBoundaryBaselineSource $source): string
    {
        if ($source === null) {
            return '(none)';
        }
        if ($source->inert !== null) {
            return \sprintf(
                'present but not applied (%s — %s) [%s]',
                $source->inert->reason->description(),
                $source->inert->detail,
                $source->inert->selector->value,
            );
        }

        return \sprintf(
            'accepted %s%s%s',
            $source->accepted?->describe() ?? '',
            $source->mode === BaselineEntryMode::Suppress ? ' (mode: suppress, accepted whatever is reported)' : '',
            $source->verdict === 'stale' ? ' (stale)' : '',
        );
    }

    private static function describeCurrent(EffectiveBoundary $boundary): string
    {
        $now = $boundary->now;

        return match ($now->state) {
            CurrentMeasurement::NOTHING_REPORTED => 'nothing reported',
            CurrentMeasurement::NOT_MEASURED => 'not measured (' . self::describeReason($now->reason) . ')',
            CurrentMeasurement::OUTSIDE_COVERAGE => "outside this run's coverage (" . self::describeReason($now->reason) . ')',
            CurrentMeasurement::LEVEL_NOT_REPORTED => \sprintf(
                'channel %s reports at %s — not at %s',
                $boundary->identity->channel->code,
                implode(', ', $now->declaredLevels),
                $now->subjectLevel,
            ),
            CurrentMeasurement::NOT_COMPARED => self::describeMeasurement($now) . ' — not compared: ' . self::describeReason($now->reason),
            default => self::describeMeasurement($now),
        };
    }

    private static function describeMeasurement(CurrentMeasurement $now): string
    {
        if ($now->membersWithoutMagnitude > 0) {
            return \sprintf('%d findings, %d without a finite magnitude', $now->count, $now->membersWithoutMagnitude);
        }
        if ($now->shape === 'magnitude') {
            return implode(', ', array_map(self::formatNumber(...), $now->magnitudes ?? []));
        }
        $unit = $now->shape === 'occurrence' ? 'occurrence' : 'finding';

        return $now->count . ' ' . $unit . ($now->count === 1 ? '' : 's');
    }

    private static function describeReason(?string $reason): string
    {
        return match ($reason) {
            'producer-not-measured' => 'this invocation did not measure this channel at this subject level',
            'analysis-incomplete' => 'analysis is incomplete',
            'paths-differ' => "this run's coverage differs from the recorded one",
            'exclusions-differ' => 'discovery exclusions differ from the recorded ones',
            'metadata-unknown' => 'project metadata is unknown',
            'magnitude-unavailable' => 'the group has members without a finite magnitude',
            'channel-not-declared' => 'the channel is not declared in this configuration',
            default => $reason ?? 'the subject was not analyzed',
        };
    }

    private static function describeConfigured(EffectiveBoundary $boundary): string
    {
        return $boundary->configuredThreshold === null
            ? '(not resolvable from configuration)'
            : self::formatNumber($boundary->configuredThreshold);
    }

    private static function describeAnnotation(?ThresholdOverride $annotation): string
    {
        if ($annotation === null) {
            return '(none)';
        }

        return \sprintf(
            '@qmx-threshold %s warning=%s error=%s',
            $annotation->rulePattern,
            $annotation->warning === null ? '(unchanged)' : self::formatNumber($annotation->warning),
            $annotation->error === null ? '(unchanged)' : self::formatNumber($annotation->error),
        );
    }

    private static function formatNumber(int|float $value): string
    {
        if (\is_int($value)) {
            return (string) $value;
        }

        $formatted = rtrim(rtrim(\sprintf('%.6F', $value), '0'), '.');

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }
}
