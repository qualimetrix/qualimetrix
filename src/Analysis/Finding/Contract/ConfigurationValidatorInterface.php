<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;

/**
 * The second kind of finding producer: one that reports on the configuration
 * it was handed rather than on the code it analysed.
 *
 * The distinction is the producer's type rather than a flag a channel
 * declaration carries, so that a rule cannot classify its own findings as
 * unacceptable-as-debt while remaining, in every other respect, a rule.
 * Whatever a validator declares is a configuration error, whatever a rule
 * declares is not, and no third answer is representable. The reclassifying
 * wither on {@see ChannelDeclaration} is applied once, where the registry is
 * assembled and the declaring type is known.
 *
 * **A validator is not free-standing — it belongs to a rule.**
 * {@see producerRuleName()} names that rule, and it is the name under which
 * the validator's channels are registered, addressed by `--disable-rule` and
 * `only_rules`, keyed by `exclude_paths` and `exclude_namespaces`, and
 * resolved for a description, a documentation page and a remediation
 * estimate. The validator also runs in the producer's slot in the execution
 * order and answers to the producer's own options, `enabled` included. All of
 * that is behaviour the diagnostics already had while they lived inside the
 * rule class; naming the owner is what preserves it once they no longer do.
 *
 * A validator's findings are ordinary {@see Violation}s. A second finding type
 * would have to be carried through suppression, exclusion, report scope, every
 * formatter and the exit code separately, and each of those passes would be a
 * new place for the two to diverge.
 */
interface ConfigurationValidatorInterface
{
    /**
     * The rule this validator belongs to — its owner for selection,
     * exclusion, options, presentation and execution order.
     */
    public static function producerRuleName(): string;

    /**
     * What every channel this validator declares means for baseline purposes
     * (ADR 0031 / {@see ChannelShape}) — one answer for the whole validator,
     * not per channel. Registry assembly refuses a value that disagrees with
     * {@see producerRuleName()}'s own rule, and refuses a channel here whose
     * direction disagrees with it.
     */
    public static function shape(): ChannelShape;

    /**
     * The diagnostic channels this validator emits, keyed by
     * {@see ViolationChannel::toKey()}. Every one of them is registered as a
     * configuration error by that fact alone.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array;

    /**
     * @return list<Violation>
     */
    public function validate(AnalysisContext $context): array;
}
