<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\SuppressionBinding;

use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\ChannelShape;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Core\Symbol\SymbolLevel;

/**
 * Reports a configured suppression value — `suppress_paths`,
 * `suppress_namespaces` or `suppress_namespace_channels`, global or per-rule —
 * that names nothing this run contains.
 *
 * **This is a different zero from the one `--format=suppressed` already
 * reports.** That format's `neverMatched` list is built from the findings a
 * suppressor removed, so it answers "this suppressor removed nothing" —
 * effect-zero. A suppressor can reach effect-zero honestly: it names a real
 * directory that simply has no findings left in it, which is what a paid-down
 * suppression looks like. The zero reported here is binding-zero: the value
 * names no analysed file and no declared namespace at all, so it could not
 * have suppressed anything whatever the code contained, and it will go on
 * doing nothing after the debt it was written for is repaid. Binding-zero is a
 * subset of effect-zero, and the run counts the two separately — see
 * {@see UnboundSuppressionAudit}.
 *
 * **The rule emits nothing itself, and cannot.** Which files a run analysed
 * and which namespaces it declared are facts about the whole run, known only
 * after every rule has finished; `suppress_*` does not enter rule execution at
 * all, and {@see AnalysisContext} carries neither the run's file list nor its
 * configuration — nor may it, since ADR 0022 forbids a new port into rule
 * execution for it. So the findings are assembled by
 * {@see UnboundSuppressionAudit} at the one seam where the configured values
 * and the run's universes meet, and passed through
 * `RuleExecutionInterface::publishable()`, exactly as
 * `annotation.unused-directive` and `discovery.unmatched-exclude` are. This
 * class is what makes those assembled findings *channels*: without a
 * registered rule there is no entry in `qmx rules`, no `--disable-rule`, no
 * severity and no baseline identity.
 *
 * **Not a configuration validator, and that is a decision.** A channel
 * declared through `ConfigurationValidatorInterface` bypasses `fail_on` and
 * exits 2 unconditionally. A stale suppression does not deserve that: a
 * `qmx.yaml` shared across repositories may legitimately name a path or a
 * namespace one of them does not have. An ordinary warning is visible in the
 * report and answers to `--fail-on`.
 *
 * **What that leaves an author who cannot correct the value, measured rather
 * than assumed.** Two routes, and they are not the same route:
 *
 * - A baseline entry accepts one of these findings like any other — measured
 *   on a fixture: an entry under `project:` naming the channel and the
 *   finding's `occurrence` removes it from the report. Since the value is part
 *   of that occurrence, the acceptance names *this* value, and replacing it
 *   with another unbound one is reported rather than passing under the
 *   accepted entry.
 * - `baseline:generate` does **not** write them, deliberately and by test
 *   ({@see \Qualimetrix\Tests\Analysis\Finding\Integration\SuppressionBinding\UnboundSuppressionIntegrationTest}):
 *   a warning about the author's own configuration should not become accepted
 *   debt in the file that author produces with one command. So acceptance here
 *   is a written decision, never a generated one — which is the point, not an
 *   oversight.
 *
 * For the shared-`qmx.yaml` case the answer is usually neither: the channel is
 * switched off where the shared configuration lives, by name and without a CLI
 * flag — `disabled_rules: ['suppression.unmatched-path']` silences that one
 * channel, measured, while the other two keep speaking.
 *
 * **Statelessness:** trivially — `analyze()` does nothing at all.
 */
final class UnboundSuppressionRule extends AbstractRule
{
    /**
     * The producer's name is not one of its channels, the way
     * `annotation.directive` is not: three channels answer one question about
     * one subject — the run's suppression configuration — and a rule named
     * after any one of them would make the other two read as its subordinates.
     */
    public const string NAME = 'suppression.configuration';

    public const string DOCS_PAGE = 'rules/suppression.md';

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
        return 'Reports a suppress_paths, suppress_namespaces or suppress_namespace_channels value that names nothing the run contains';
    }

    /**
     * @return class-string<UnboundSuppressionOptions>
     */
    public static function getOptionsClass(): string
    {
        return UnboundSuppressionOptions::class;
    }

    /**
     * Three occurrences reported on the project: each finding counts nothing
     * and belongs to no declaration — it is a fact about the run's
     * configuration, the way `discovery.unmatched-exclude` is.
     *
     * Project level is also what keeps a finding about a pattern from being
     * removed by the very pattern it reports. These channels are not declared
     * project-scoped (`ChannelFileScope`); the global filters pass them
     * because a project finding has no file for a path pattern to match and
     * no namespace for a namespace pattern to compare.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        return [
            UnboundSuppressionOptions::UNMATCHED_PATH => ChannelDeclaration::occurrence(SymbolLevel::Project)
                ->describedAs('Reports a global suppress_paths value that matches no analysed file.'),
            UnboundSuppressionOptions::UNMATCHED_NAMESPACE => ChannelDeclaration::occurrence(SymbolLevel::Project)
                ->describedAs('Reports a global suppress_namespaces value that matches no declared namespace.'),
            UnboundSuppressionOptions::UNMATCHED_RULE_LEDGER => ChannelDeclaration::occurrence(SymbolLevel::Project)
                ->describedAs('Reports a suppression value configured under a rule that names nothing the run contains.'),
        ];
    }

    /**
     * Nothing: the findings are assembled where the answer is known, and this
     * rule exists to give them a channel identity, an options object and a
     * place in `qmx rules`.
     *
     * @return list<Finding>
     */
    public function analyze(AnalysisContext $context): array
    {
        return [];
    }
}
