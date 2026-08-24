<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline;

use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Core\Time\ClockInterface;

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
     * @param list<Finding> $findings
     * @param list<string> $scope the analysed paths that produced this run; {@see Baseline}
     *                            normalizes it, so the caller passes what it analysed
     */
    public function generate(array $findings, array $scope): BaselineCapture
    {
        $groups = self::groupFindings($findings);
        $generated = $this->clock->now();

        $entries = [];
        $rejected = [];

        foreach ($groups as $group) {
            $entry = $this->captureGroup($group['identity'], $group['findings']);

            if ($entry instanceof BaselineEntry) {
                $entries[] = $entry;
            } else {
                $rejected[] = [
                    'identity' => $group['identity'],
                    'reason' => $entry,
                    'memberCount' => \count($group['findings']),
                ];
            }
        }

        return BaselineCapture::fromRejectedGroups(
            new Baseline(
                generated: $generated,
                scope: $scope,
                entries: $entries,
            ),
            $rejected,
        );
    }

    /**
     * @param list<Finding> $findings
     *
     * @return array<string, array{identity: BaselineIdentity, findings: non-empty-list<Finding>}>
     */
    private static function groupFindings(array $findings): array
    {
        $groups = [];

        foreach ($findings as $finding) {
            $identity = BaselineIdentity::forFinding($finding);
            $key = $identity->key();

            $groups[$key] ??= ['identity' => $identity, 'findings' => []];
            $groups[$key]['findings'][] = $finding;
        }

        return $groups;
    }

    /**
     * The entry for a group, or the reason there is none.
     *
     * @param non-empty-list<Finding> $group
     */
    private function captureGroup(BaselineIdentity $identity, array $group): BaselineEntry|UncapturedReason
    {
        $declaration = $this->declarations->declarationFor($identity->channel);

        if ($declaration === null) {
            return UncapturedReason::UndeclaredChannel;
        }

        // Declared, but as a configuration error: capturing it would record
        // "the declared configuration does not describe this code" as an
        // accepted amount of debt. The finding is reported instead, and the
        // run stays red until the configuration is fixed.
        if ($declaration->isConfigurationError()) {
            return UncapturedReason::ConfigurationErrorChannel;
        }

        if ($declaration->direction === null) {
            return new BaselineEntry($identity, null, \count($group));
        }

        $magnitudes = [];
        foreach ($group as $finding) {
            if ($finding->metricValue === null || !is_finite((float) $finding->metricValue)) {
                return UncapturedReason::MagnitudeUnavailable;
            }

            $magnitudes[] = $finding->metricValue;
        }

        return new BaselineEntry($identity, $magnitudes, \count($group));
    }
}
