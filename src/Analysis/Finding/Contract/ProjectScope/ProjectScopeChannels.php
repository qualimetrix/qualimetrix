<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\ProjectScope;

/** Channels whose absence claims require measured project scope. */
final class ProjectScopeChannels
{
    public const string UNMATCHED_NAMESPACE_SUPPRESSION = 'suppression.unmatched-namespace';

    public const string UNMATCHED_RULE_LEDGER = 'suppression.unmatched-rule-ledger';

    public const array NAMESPACE_CLAIM_CHANNELS = [
        'architecture.empty-template',
        'architecture.unmatched-exclude',
        'architecture.unmatched-type',
        'architecture.unreachable-layer',
        'cohesion.unmatched-exclude-method',
        'coupling.unmatched-framework-namespace',
        self::UNMATCHED_NAMESPACE_SUPPRESSION,
    ];

    public const array VALUE_CHANNELS = [
        'suppression.unmatched-path',
        self::UNMATCHED_RULE_LEDGER,
    ];

    public const string WALK_CHANNEL = 'discovery.unmatched-exclude';

    public const array PROJECT_SCOPED_CHANNELS = [
        ...self::NAMESPACE_CLAIM_CHANNELS,
        ...self::VALUE_CHANNELS,
        self::WALK_CHANNEL,
    ];
}
