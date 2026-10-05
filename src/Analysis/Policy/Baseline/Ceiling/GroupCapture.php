<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline\Ceiling;

use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Policy\Baseline\BaselineEntry;
use Qualimetrix\Analysis\Policy\Baseline\BaselineIdentity;
use Qualimetrix\Analysis\Policy\Baseline\Contract\BaselineAuditChannels;
use Qualimetrix\Analysis\Policy\Baseline\UncapturedReason;

/** Captures only a declared, complete measured group. */
final readonly class GroupCapture
{
    public function __construct(private ChannelDeclarationRegistryInterface $declarations) {}

    /** @param non-empty-list<Finding> $group */
    public function capture(BaselineIdentity $identity, array $group): BaselineEntry|UncapturedReason
    {
        if ($identity->channel->code === BaselineAuditChannels::UNUSED_ENTRY) {
            return UncapturedReason::BaselineAuditChannel;
        }

        $declaration = $this->declarations->declarationFor($identity->channel);
        if ($declaration === null) {
            return UncapturedReason::UndeclaredChannel;
        }
        if ($declaration->isConfigurationError()) {
            return UncapturedReason::ConfigurationErrorChannel;
        }

        $measurement = GroupMeasurement::fromFindings($group, $declaration->direction === null ? ChannelShape::Occurrence : ChannelShape::Magnitude);
        if (!$measurement->complete()) {
            return UncapturedReason::MagnitudeUnavailable;
        }

        return new BaselineEntry($identity, $measurement->magnitudes, $measurement->count);
    }
}
