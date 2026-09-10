<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\ExcludeBinding;

use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * Reports an `--exclude` value or an `exclude:` entry that removed no
 * directory from the run.
 *
 * The author wrote the entry to keep a directory out of the analysis. One that
 * matches nothing keeps nothing out, and until this channel existed the two
 * states were byte-identical: the report of a run with a missed exclusion is
 * the same report as a run with no exclusion at all. The user reads a clean
 * report about a smaller codebase than the one that was measured.
 *
 * **The rule emits nothing itself, and cannot.** What a pattern bound to is a
 * fact about file discovery, which happens before rules run and is not part of
 * {@see AnalysisContext} — nor may it become part of it, since ADR 0022
 * forbids a new port into rule execution for it. So the finding is assembled
 * by {@see UnmatchedExcludeAudit} on Run's own side of the pipeline and passed
 * through `RuleExecutionInterface::publishable()`, exactly as
 * `annotation.unused-directive` is. This class is what makes that assembled
 * finding a *channel*: without a registered rule there is no entry in
 * `qmx rules`, no `--disable-rule`, no severity and no baseline identity.
 *
 * **Not a configuration validator, and that is a decision.** A channel
 * declared through `ConfigurationValidatorInterface` bypasses `fail_on` and
 * exits 2 unconditionally. A stale exclusion does not deserve that: a
 * `qmx.yaml` shared across repositories may legitimately name a directory one
 * of them does not have. An ordinary warning is visible in the report,
 * answers to `--fail-on`, and can be accepted as debt.
 *
 * **Statelessness:** trivially — `analyze()` does nothing at all.
 */
final class UnmatchedExcludeRule extends AbstractRule
{
    public const string NAME = UnmatchedExcludeOptions::CHANNEL;

    /**
     * Interim, and the interim is the point: the channel's own group page
     * (`rules/discovery.md`) cannot be created here, because a new group page
     * also needs an inventory prefix and a nav entry, and those three must
     * land in one change owned by the round's consolidating package. The
     * catalog page carries this rule's anchor until then, so the guarantee
     * "every rule's name is documented where its reports point" holds without
     * a gap.
     */
    public const string DOCS_PAGE = 'rules/discovery.md';

    /** Deleting one line of configuration, plus checking what the tree really contains. */
    public const int REMEDIATION_MINUTES = 10;

    public const ChannelShape SHAPE = ChannelShape::Occurrence;

    /** There is no measured quantity here to retune. */
    public const bool SUPPORTS_THRESHOLD_OVERRIDE = false;

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Reports an exclude pattern that removed no directory from the analysed set';
    }

    /**
     * @return class-string<UnmatchedExcludeOptions>
     */
    public static function getOptionsClass(): string
    {
        return UnmatchedExcludeOptions::class;
    }

    /**
     * An occurrence reported on the project: the finding counts nothing and
     * belongs to no declaration — it is a fact about the run's configuration,
     * the way `architecture.unreachable-layer` and
     * `coupling.unmatched-framework-namespace` are.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            self::NAME => ChannelDeclaration::occurrence(SymbolLevel::Project),
        ];
    }

    /**
     * Nothing: the finding is assembled where the answer is known, and this
     * rule exists to give that finding a channel identity, an options object
     * and a place in `qmx rules`.
     *
     * @return list<Finding>
     */
    public function analyze(AnalysisContext $context): array
    {
        return [];
    }
}
