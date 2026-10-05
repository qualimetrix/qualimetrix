<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use LogicException;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Policy\Baseline\Ceiling\GroupMeasurement;
use Qualimetrix\Analysis\Policy\Baseline\Contract\CeilingOutcome;

/** Tightens one existing entry against the shared ceiling verdict. */
final readonly class BaselineEntryTightening
{
    public function __construct(private ChannelDeclarationRegistryInterface $declarations) {}

    /**
     * @param ?non-empty-list<Finding> $group
     *
     * @return array{BaselineEntry, BaselineEntryUpdateOutcome}
     */
    public function tighten(BaselineEntry $entry, ?array $group, CeilingOutcome $judgement): array
    {
        $status = $judgement->statusFor($entry->identity);
        if ($status === 'not-compared' || $status === 'unmeasured' || $status === 'outside-coverage') {
            return [$entry, BaselineEntryUpdateOutcome::notCompared($entry->identity, $judgement->reasonFor($entry->identity) ?? 'metadata-unknown')];
        }
        if ($group === null) {
            return [$entry, BaselineEntryUpdateOutcome::skipped($entry->identity)];
        }
        [$written, $outcome] = $this->reconcile($entry, $group);
        if ($outcome->disposition === BaselineUpdateDisposition::Updated && $written->toArray() === $entry->toArray()) {
            $outcome = BaselineEntryUpdateOutcome::unchanged($entry->identity);
        }
        return [$written, $outcome];
    }

    /**
     * Decides one entry, in the order applicability requires: whether the
     * entry can be compared at all is settled before anything about the
     * measured group is read, mirroring the ceiling stage's own ordering.
     *
     * @param non-empty-list<Finding> $group every measured finding sharing the entry's identity
     *
     * @return array{BaselineEntry, BaselineEntryUpdateOutcome}
     */
    private function reconcile(BaselineEntry $entry, array $group): array
    {
        $declaration = $this->declarations->declarationFor($entry->identity->channel);

        if ($declaration === null) {
            return [$entry, BaselineEntryUpdateOutcome::refused($entry->identity, BaselineUpdateRefusalReason::UndeclaredChannel)];
        }

        // Applicability, before anything about the measured group: a channel
        // that reports a configuration error is never re-recorded, so
        // `update` cannot turn a misconfigured run into a wider acceptance.
        if ($declaration->isConfigurationError()) {
            return [
                $entry,
                BaselineEntryUpdateOutcome::refused($entry->identity, BaselineUpdateRefusalReason::ConfigurationErrorChannel),
            ];
        }

        // The channel's own shape moved to the producer (ADR 0031);
        // `$declaration->direction` is null exactly when the producer
        // declared `occurrence`, since registry assembly refuses any other
        // combination. Comparing nullability against the entry's
        // self-derived shape is the same check as before.
        $declarationIsOccurrence = $declaration->direction === null;

        if ($declarationIsOccurrence !== ($entry->shape() === ChannelShape::Occurrence)) {
            return [$entry, BaselineEntryUpdateOutcome::refused($entry->identity, BaselineUpdateRefusalReason::ShapeMismatch)];
        }

        return $declarationIsOccurrence
            ? $this->reconcileOccurrence($entry, $group)
            : $this->reconcileMagnitude($entry, $declaration, $group);
    }

    /**
     * One level, no magnitudes: {@see GroupAcceptance::countWithin()} is the
     * whole comparison.
     *
     * @param non-empty-list<Finding> $group
     *
     * @return array{BaselineEntry, BaselineEntryUpdateOutcome}
     */
    private function reconcileOccurrence(BaselineEntry $entry, array $group): array
    {
        $currentCount = GroupMeasurement::fromFindings($group, ChannelShape::Occurrence)->count;

        if (!GroupAcceptance::countWithin($currentCount, $entry->count)) {
            return [$entry, BaselineEntryUpdateOutcome::refused($entry->identity, self::worsenedReason($entry))];
        }

        $written = new BaselineEntry($entry->identity, null, $currentCount, $entry->mode);

        return [$written, BaselineEntryUpdateOutcome::updated($entry->identity)];
    }

    /**
     * @param non-empty-list<Finding> $group
     *
     * @return array{BaselineEntry, BaselineEntryUpdateOutcome}
     */
    private function reconcileMagnitude(BaselineEntry $entry, ChannelDeclaration $declaration, array $group): array
    {
        $stored = $entry->magnitudes;

        if ($stored === null) {
            // Unreachable: BaselineEntry::shape() is Magnitude exactly when
            // magnitudes is non-null, and reconcile() already matched that
            // against $declaration->direction being non-null before calling
            // here. Kept only to narrow $stored's type for static analysis.
            throw new LogicException('BaselineEntry::shape() reported Magnitude with no magnitudes stored.');
        }

        $direction = $declaration->direction;

        if ($direction === null) {
            // Unreachable: ChannelDeclaration's own constructor refuses to
            // exist as a Magnitude declaration without a WorseDirection.
            throw new LogicException('A magnitude ChannelDeclaration was built without a WorseDirection.');
        }

        $current = GroupMeasurement::fromFindings($group, ChannelShape::Magnitude)->magnitudes;

        if ($current === null) {
            return [$entry, BaselineEntryUpdateOutcome::refused($entry->identity, BaselineUpdateRefusalReason::CurrentMagnitudeUnavailable)];
        }

        if (!GroupAcceptance::magnitudesWithin($current, $stored, $direction)) {
            return [$entry, BaselineEntryUpdateOutcome::refused($entry->identity, self::worsenedReason($entry))];
        }

        $written = new BaselineEntry($entry->identity, $current, \count($current), $entry->mode);

        return [$written, BaselineEntryUpdateOutcome::updated($entry->identity)];
    }

    /**
     * Which refusal a declined comparison is, on this entry.
     *
     * The comparison itself is the same one on every entry — a suppressed
     * entry is *not* exempt from it, or `update` would become a way to widen
     * an acceptance (ADR 0017). What differs is what the refusal means to a user:
     * on a `mode: suppress` entry the ceiling never compares these numbers at
     * `check` time, so nothing observable worsened and the word "worsened"
     * would send the user looking for a red build that is not there.
     * Behaviour is identical in both branches; only the name is not.
     */
    private static function worsenedReason(BaselineEntry $entry): BaselineUpdateRefusalReason
    {
        return $entry->mode === BaselineEntryMode::Suppress
            ? BaselineUpdateRefusalReason::WorsenedUnderSuppression
            : BaselineUpdateRefusalReason::Worsened;
    }

}
