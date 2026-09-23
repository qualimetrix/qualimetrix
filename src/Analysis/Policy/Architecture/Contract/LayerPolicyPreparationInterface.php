<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Contract;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphInterface;

interface LayerPolicyPreparationInterface
{
    public const string PRODUCER_RULE_NAME = 'architecture.layer-violation';

    /**
     * The five diagnostics the layer-policy producer's configuration validator
     * emits under rule names other than its own, the producer's own second
     * and third channels, plus the rule name of the second producer that reads the same
     * prepared policy. They are
     * `ruleName`s in their own right — nothing else declares them — so they
     * live beside {@see PRODUCER_RULE_NAME} for the same reason it does: one
     * literal, readable by a cross-owner consumer without importing either
     * producer.
     */
    public const string COVERAGE_DIAGNOSTIC_NAME = 'architecture.coverage-gap';

    public const string UNASSIGNED_CLASS_DIAGNOSTIC_NAME = 'architecture.unassigned-class';

    public const string UNREACHABLE_LAYER_DIAGNOSTIC_NAME = 'architecture.unreachable-layer';

    public const string POTENTIAL_SHADOW_DIAGNOSTIC_NAME = 'architecture.potential-shadow';

    public const string EMPTY_TEMPLATE_DIAGNOSTIC_NAME = 'architecture.empty-template';

    public const string PENDING_LAYER_MATCHED_DIAGNOSTIC_NAME = 'architecture.pending-layer-matched';

    /**
     * The layer-policy producer's SECOND channel — a statement about the code
     * the way {@see PRODUCER_RULE_NAME} is, not about the declaration the way
     * the five above are. An `exclude:` clause that removed nothing makes the
     * layer larger than its author wrote it to be, and the run says so at
     * warning level instead of failing unconditionally, which is why it is a
     * channel of the rule and not of the configuration validator.
     */
    public const string UNMATCHED_EXCLUDE_DIAGNOSTIC_NAME = 'architecture.unmatched-exclude';

    /**
     * The producer's THIRD channel: how many symbols stand assigned while a
     * layer bearing on the assignment could not be answered. Information about
     * how far the layer verdicts can be trusted, reported at `info` so it never
     * gates — which is why it is neither the coverage gap nor any other
     * configuration validator's channel.
     */
    public const string DOUBTED_ASSIGNMENT_DIAGNOSTIC_NAME = 'architecture.doubted-assignment';

    /**
     * This capability's channels that are **not** file-scoped: a layer policy
     * is a statement about the project, so `suppress_paths` and
     * `suppress_namespaces` do not apply to its findings. Declared here rather
     * than inferred from the `architecture.` spelling — see
     * {@see \Qualimetrix\Analysis\Finding\Contract\Filter\ChannelFileScope}.
     *
     * @var list<string> channel names
     */
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
