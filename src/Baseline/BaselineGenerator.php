<?php

declare(strict_types=1);

namespace Qualimetrix\Baseline;

use Qualimetrix\Core\Time\ClockInterface;
use Qualimetrix\Core\Violation\ChannelDeclarationRegistryInterface;
use Qualimetrix\Core\Violation\ChannelShape;
use Qualimetrix\Core\Violation\Violation;

/**
 * Captures a run's findings as baseline entries.
 *
 * Findings are grouped by identity (ADR 0017); each
 * group becomes one entry holding its size and, for a `magnitude` channel,
 * the magnitude every member reported.
 *
 * **Two kinds of group are deliberately not captured**, because an entry
 * that cannot be applied is worse than no entry — it would be reported as
 * inert on every subsequent run while suppressing nothing:
 *
 * - a channel no rule declares (ADR 0017 "not baselineable" default,
 *   `annotation.*` and any `computed.*` metric no longer configured);
 * - a `magnitude` channel where some member reports no usable number. ADR 0017
 *   requires exactly one finite magnitude per member, and inventing one
 *   would fabricate the very boundary the entry exists to state. The finding
 *   is simply reported next run, which is the fail-safe direction.
 *
 * Both refusals are **returned**, not swallowed: see {@see BaselineCapture}.
 * The drop is sanctioned; the drop being invisible was not.
 *
 * `generated` comes from the injected {@see ClockInterface}: with it, the
 * whole file is determined by the analysis, which is what lets ADR 0017 claim byte
 * stability.
 */
final readonly class BaselineGenerator
{
    public function __construct(
        private ChannelDeclarationRegistryInterface $declarations,
        private ClockInterface $clock,
    ) {}

    /**
     * @param list<Violation> $violations
     * @param list<string> $scope the analysed paths that produced this run; {@see Baseline}
     *                            normalizes it, so the caller passes what it analysed
     */
    public function generate(array $violations, array $scope): BaselineCapture
    {
        /** @var array<string, array{identity: BaselineIdentity, violations: list<Violation>}> $groups */
        $groups = [];

        foreach ($violations as $violation) {
            $identity = BaselineIdentity::forViolation($violation);
            $key = $identity->key();

            $groups[$key] ??= ['identity' => $identity, 'violations' => []];
            $groups[$key]['violations'][] = $violation;
        }

        $entries = [];
        $uncaptured = [];

        foreach ($groups as $group) {
            $entry = $this->captureGroup($group['identity'], $group['violations']);

            if ($entry instanceof BaselineEntry) {
                $entries[] = $entry;
            } else {
                $uncaptured[] = new UncapturedGroup($group['identity'], $entry, \count($group['violations']));
            }
        }

        return new BaselineCapture(
            new Baseline(
                generated: $this->clock->now(),
                scope: $scope,
                entries: $entries,
            ),
            $uncaptured,
        );
    }

    /**
     * The entry for a group, or the reason there is none.
     *
     * @param list<Violation> $group
     */
    private function captureGroup(BaselineIdentity $identity, array $group): BaselineEntry|UncapturedReason
    {
        $declaration = $this->declarations->declarationFor($identity->channel);

        if ($declaration === null) {
            return UncapturedReason::UndeclaredChannel;
        }

        if ($declaration->shape === ChannelShape::Occurrence) {
            return new BaselineEntry($identity, null, \count($group));
        }

        $magnitudes = [];
        foreach ($group as $violation) {
            if ($violation->metricValue === null || !is_finite((float) $violation->metricValue)) {
                return UncapturedReason::MagnitudeUnavailable;
            }

            $magnitudes[] = $violation->metricValue;
        }

        return new BaselineEntry($identity, $magnitudes, \count($group));
    }
}
