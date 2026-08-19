<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Contract;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;

interface LayerPolicyPreparationInterface
{
    public const string PRODUCER_RULE_NAME = 'architecture.layer-violation';

    /**
     * The six diagnostics the layer-policy rule emits under rule names other
     * than its own. They are `ruleName`s in their own right — nothing else
     * declares them — so they live beside {@see PRODUCER_RULE_NAME} for the
     * same reason it does: one literal, readable by a cross-owner consumer
     * without importing the rule.
     */
    public const string COVERAGE_DIAGNOSTIC_NAME = 'architecture.coverage';

    public const string UNASSIGNED_CLASS_DIAGNOSTIC_NAME = 'architecture.unassigned-class';

    public const string UNREACHABLE_LAYER_DIAGNOSTIC_NAME = 'architecture.unreachable-layer';

    public const string POTENTIAL_SHADOW_DIAGNOSTIC_NAME = 'architecture.potential-shadow';

    public const string EMPTY_TEMPLATE_DIAGNOSTIC_NAME = 'architecture.empty-template';

    public const string PENDING_LAYER_MATCHED_DIAGNOSTIC_NAME = 'architecture.pending-layer-matched';

    /**
     * This capability's channels that are **not** file-scoped: a layer policy
     * is a statement about the project, so `exclude_paths` and
     * `exclude_namespaces` do not apply to its findings. Declared here rather
     * than inferred from the `architecture.` spelling — see
     * {@see \Qualimetrix\Analysis\Finding\Contract\Filter\ChannelFileScope}.
     *
     * @var list<string> {@see \Qualimetrix\Analysis\Finding\Contract\ViolationChannel::toKey()} form
     */
    public const array PROJECT_SCOPED_CHANNELS = [
        self::PRODUCER_RULE_NAME . '#' . self::PRODUCER_RULE_NAME,
        self::COVERAGE_DIAGNOSTIC_NAME . '#' . self::COVERAGE_DIAGNOSTIC_NAME,
        self::UNASSIGNED_CLASS_DIAGNOSTIC_NAME . '#' . self::UNASSIGNED_CLASS_DIAGNOSTIC_NAME,
        self::UNREACHABLE_LAYER_DIAGNOSTIC_NAME . '#' . self::UNREACHABLE_LAYER_DIAGNOSTIC_NAME,
        self::POTENTIAL_SHADOW_DIAGNOSTIC_NAME . '#' . self::POTENTIAL_SHADOW_DIAGNOSTIC_NAME,
        self::EMPTY_TEMPLATE_DIAGNOSTIC_NAME . '#' . self::EMPTY_TEMPLATE_DIAGNOSTIC_NAME,
        self::PENDING_LAYER_MATCHED_DIAGNOSTIC_NAME . '#' . self::PENDING_LAYER_MATCHED_DIAGNOSTIC_NAME,
    ];

    /** @param iterable<\Qualimetrix\Core\Symbol\SymbolPath> $classUniverse */
    public function prepare(DependencyGraphInterface $graph, iterable $classUniverse): void;

    /** Clears prepared run state without traversing the class universe. */
    public function reset(): void;
}
