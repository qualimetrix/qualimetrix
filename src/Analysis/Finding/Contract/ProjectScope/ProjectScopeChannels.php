<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\ProjectScope;

/** Channels whose absence claims require measured project scope. */
final class ProjectScopeChannels
{
    public const array NAMESPACE_CLAIM_CHANNELS = [
        'architecture.empty-template',
        'architecture.unmatched-exclude',
        'architecture.unmatched-type',
        'architecture.unreachable-layer',
        'cohesion.unmatched-exclude-method',
        'coupling.unmatched-framework-namespace',
        'suppression.unmatched-namespace',
    ];

    public const array VALUE_CHANNELS = [
        'suppression.unmatched-path',
        'suppression.unmatched-rule-ledger',
    ];

    public const string WALK_CHANNEL = 'discovery.unmatched-exclude';

    public const array PROJECT_SCOPED_CHANNELS = [
        ...self::NAMESPACE_CLAIM_CHANNELS,
        ...self::VALUE_CHANNELS,
        self::WALK_CHANNEL,
    ];
}
