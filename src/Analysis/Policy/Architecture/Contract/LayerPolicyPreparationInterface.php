<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Contract;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;

interface LayerPolicyPreparationInterface
{
    public const string PRODUCER_RULE_NAME = 'architecture.layer-violation';

    /**
     * The five diagnostics the layer-policy producer's configuration validator
     * emits under rule names other than its own, plus the rule name of the
     * second producer that reads the same prepared policy. They are
     * `ruleName`s in their own right — nothing else declares them — so they
     * live beside {@see PRODUCER_RULE_NAME} for the same reason it does: one
     * literal, readable by a cross-owner consumer without importing either
     * producer.
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
     * @var list<string> {@see \Qualimetrix\Analysis\Finding\Contract\FindingChannel::toKey()} form
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

    /**
     * Every rule name whose findings need this policy prepared for the run.
     *
     * Two, not one, and the second was learned the hard way: while
     * `architecture.unassigned-class` was a channel of the layer-violation
     * rule, asking whether that one producer was enabled covered it, because
     * a selector naming the channel matched its producer. Once it became a
     * producer of its own, `--only-rule=architecture.unassigned-class` left
     * the policy unprepared and the rule reached an unprepared collector.
     *
     * The list lives here rather than in the caller for the same reason
     * {@see PRODUCER_RULE_NAME} does: the caller is the run, which may not
     * import a rule to ask it its name. A third rule reading the prepared
     * policy has to be added here, and the run needs no change.
     *
     * @var list<string>
     */
    public const array PRODUCER_RULE_NAMES = [
        self::PRODUCER_RULE_NAME,
        self::UNASSIGNED_CLASS_DIAGNOSTIC_NAME,
    ];

    /** @param iterable<\Qualimetrix\Core\Symbol\SymbolPath> $classUniverse */
    public function prepare(DependencyGraphInterface $graph, iterable $classUniverse): void;

    /** Clears prepared run state without traversing the class universe. */
    public function reset(): void;
}
