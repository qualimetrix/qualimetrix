<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Contract;

/** Channel vocabulary and the producers that read prepared layer policy. */
final class ArchitectureChannels
{
    public const string PRODUCER_RULE_NAME = 'architecture.layer-violation';
    public const string LAYER_DECLARATION_PRODUCER_NAME = 'architecture.layer-declaration';
    public const string UNASSIGNED_CLASS_DIAGNOSTIC_NAME = 'architecture.unassigned-class';
    public const string COVERAGE_DIAGNOSTIC_NAME = 'architecture.coverage-gap';
    public const string UNREACHABLE_LAYER_DIAGNOSTIC_NAME = 'architecture.unreachable-layer';
    public const string POTENTIAL_SHADOW_DIAGNOSTIC_NAME = 'architecture.potential-shadow';
    public const string EMPTY_TEMPLATE_DIAGNOSTIC_NAME = 'architecture.empty-template';
    public const string PENDING_LAYER_MATCHED_DIAGNOSTIC_NAME = 'architecture.pending-layer-matched';
    public const string UNMATCHED_EXCLUDE_DIAGNOSTIC_NAME = 'architecture.unmatched-exclude';
    public const string DOUBTED_ASSIGNMENT_DIAGNOSTIC_NAME = 'architecture.doubted-assignment';
    public const string LAYER_OVERLAP_DIAGNOSTIC_NAME = 'architecture.layer-overlap';
    public const string UNMATCHED_TYPE_DIAGNOSTIC_NAME = 'architecture.unmatched-type';

    /** @var list<string> */
    public const array PRODUCERS = [
        self::PRODUCER_RULE_NAME,
        self::UNASSIGNED_CLASS_DIAGNOSTIC_NAME,
        self::LAYER_DECLARATION_PRODUCER_NAME,
    ];

    /** @var list<string> Channels exempt from path and namespace suppression. */
    public const array PROJECT_SCOPED_CHANNELS = [
        self::PRODUCER_RULE_NAME,
        self::COVERAGE_DIAGNOSTIC_NAME,
        self::UNASSIGNED_CLASS_DIAGNOSTIC_NAME,
        self::UNREACHABLE_LAYER_DIAGNOSTIC_NAME,
        self::POTENTIAL_SHADOW_DIAGNOSTIC_NAME,
        self::EMPTY_TEMPLATE_DIAGNOSTIC_NAME,
        self::PENDING_LAYER_MATCHED_DIAGNOSTIC_NAME,
        self::UNMATCHED_EXCLUDE_DIAGNOSTIC_NAME,
        self::DOUBTED_ASSIGNMENT_DIAGNOSTIC_NAME,
        self::LAYER_OVERLAP_DIAGNOSTIC_NAME,
        self::UNMATCHED_TYPE_DIAGNOSTIC_NAME,
    ];
}
