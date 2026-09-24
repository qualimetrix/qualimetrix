<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntryMode;
use Qualimetrix\Analysis\Policy\Baseline\BoundaryExplanation;
use Qualimetrix\Analysis\Policy\Baseline\BoundaryExplanationStatus;
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

    /**
     * Both numbers, always: the level the entry stores and the level being
     * compared against it in this run (ADR 0017) — and, where the ceiling
     * does not compare them, the reason it does not, since the pair alone
     * reads as a verdict the ceiling never reaches.
     */
    private static function describeBaseline(?EffectiveBoundaryBaselineSource $source): string
    {
        if ($source === null) {
            return '(none)';
        }

        if ($source->inert !== null) {
            return \sprintf(
                'present but not applied (%s — %s) [%s]; now %s',
                $source->inert->reason->description(),
                $source->inert->detail,
                $source->inert->selector->value,
                self::describeCurrent($source),
            );
        }

        return \sprintf(
            'accepted %s%s; now %s',
            $source->accepted?->describe() ?? '',
            $source->mode === BaselineEntryMode::Suppress ? ' (mode: suppress, accepted whatever is reported)' : '',
            self::describeCurrent($source),
        );
    }

    private static function describeCurrent(EffectiveBoundaryBaselineSource $source): string
    {
        if ($source->currentCount === 0) {
            return $source->producerRan
                ? 'nothing reported'
                : 'not measured (this invocation did not run the rule for this channel at this level)';
        }

        if ($source->currentMagnitudes === null) {
            return $source->currentCount === 1 ? '1 occurrence' : $source->currentCount . ' occurrences';
        }

        $measured = $source->currentMagnitudes === []
            ? 'no member with a value'
            : implode(', ', array_map(self::formatNumber(...), $source->currentMagnitudes));

        if ($source->membersWithoutMagnitude === 0) {
            return $measured;
        }

        return \sprintf(
            '%s, and %d without a finite value%s',
            $measured,
            $source->membersWithoutMagnitude,
            $source->mode === BaselineEntryMode::Suppress ? '' : ', so the entry is not applied and the group is reported',
        );
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
