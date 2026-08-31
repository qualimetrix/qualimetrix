<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Directive;

use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\ChannelLevelAddressing;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\InlineDirectivePolicyInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Suppression\SuppressionFilter;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * The post-execution half of the inline-directive subject: which authored
 * suppressions the run leaves behind unused.
 *
 * It is a pure function of the prepared directives and the produced findings,
 * and it holds no run state — {@see InlineDirectivePolicy} keeps that and asks
 * this once, after every rule has finished. Splitting the two is what keeps
 * the run state a store: the accounting needs the channel universe, the rule
 * selection and the finding vocabulary, and none of those has anything to do
 * with holding directives for the length of a run.
 */
final class DirectiveUsage
{
    /**
     * The shared refusal for a `channel:level` pair. Built here rather than
     * injected, mirroring {@see DirectiveAddressability}: a pure function of
     * the same universe, with no lifecycle of its own.
     */
    private readonly ChannelLevelAddressing $levels;

    public function __construct(
        private readonly ChannelIdentityInterface $identity,
        private readonly RuleSelector $ruleSelector,
        private readonly RuleConfigurationInterface $ruleConfiguration,
    ) {
        $this->levels = new ChannelLevelAddressing($identity);
    }

    /**
     * The suppressions that addressed something real and still matched
     * nothing this run.
     *
     * @param array<string, list<Suppression>> $suppressionsByFile file => directives, as prepared
     * @param list<Finding> $findings everything the rules produced this run
     *
     * @return list<Finding>
     */
    public function stale(array $suppressionsByFile, array $findings, Severity $severity): array
    {
        $selection = $this->ruleConfiguration->selection();
        $stale = [];

        foreach ($suppressionsByFile as $file => $fileSuppressions) {
            foreach (self::groupByAuthoredSite($fileSuppressions) as $group) {
                $directive = $group[0];
                if (!$this->isAccountable($directive, $selection->only, $selection->disabled)) {
                    continue;
                }

                if (self::anyOfTheGroupFired($file, $group, $findings)) {
                    continue;
                }

                $stale[] = self::staleFinding(RelativePath::fromString($file), $directive, $severity);
            }
        }

        return $stale;
    }

    /**
     * The same identity as {@see InlineDirectivePolicy::authoredSuppressions()},
     * but keeping every binding rather than one.
     *
     * The usage question genuinely needs them all: the author wrote one
     * directive, and it did something as soon as *any* declaration it covers
     * was silenced — which for a class-level directive is usually the class
     * and not the five methods beside it.
     *
     * @param list<Suppression> $suppressions
     *
     * @return list<list<Suppression>>
     */
    private static function groupByAuthoredSite(array $suppressions): array
    {
        $groups = [];

        foreach ($suppressions as $suppression) {
            $groups[$suppression->line . "\0" . $suppression->type->value . "\0" . $suppression->rule][] = $suppression;
        }

        return array_values($groups);
    }

    /**
     * The group fired if any of its bindings did: the author wrote one
     * directive, and it did something as soon as one subject it covers was
     * silenced.
     *
     * @param list<Suppression> $group
     * @param list<Finding> $findings
     */
    private static function anyOfTheGroupFired(string $file, array $group, array $findings): bool
    {
        foreach ($group as $suppression) {
            if (SuppressionFilter::suppressesAny($file, $suppression, $findings)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The subject is the **file**, never a declaration the directive happened
     * to be bound to.
     *
     * Two reasons, and both are about the directive rather than the code. A
     * finding about an annotation belongs where the annotation was written,
     * and the `Location` line already says exactly where. And this is the one
     * of the four channels a project may ratchet: a declaration subject
     * carries the declaration's byte offset, so every edit above it would
     * rewrite the entry's key for reasons that have nothing to do with the
     * annotation.
     */
    private static function staleFinding(
        RelativePath $path,
        Suppression $suppression,
        Severity $severity,
    ): Finding {
        $subject = MetricSubject::aggregate(SymbolPath::forFile($path));

        return new Finding(
            location: new Location($path, $suppression->line, precise: true),
            subject: $subject,
            symbolPath: $subject->toSymbolPath(),
            ruleName: InlineDirectivePolicyInterface::UNUSED_DIRECTIVE_NAME,
            code: InlineDirectivePolicyInterface::UNUSED_DIRECTIVE_NAME,
            message: \sprintf(
                'Suppression "%s" matched nothing in this run — the finding it silences is gone.',
                $suppression->target(),
            ),
            severity: $severity,
            recommendation: 'Remove the annotation, or keep it and note why the finding is expected to return.',
        );
    }

    /**
     * The accounting is deliberately narrow, and the two limits are the ones
     * that would otherwise turn ordinary configuration into noise.
     *
     * A directive is counted only when the rule producing the channel it
     * addresses actually reported: without that, disabling a family of rules
     * — as the shipped `legacy` preset does — would report every annotation
     * belonging to it as stale. **Both** ways of switching a rule off count,
     * because the user made the same decision either way: `disabled_rules`
     * and `--disable-rule` stop the rule from running, and `rules: { X:
     * false }` / `rules: { X: { enabled: false } }` let it run and return
     * nothing. Reading only the first is what made the second report every
     * annotation of the switched-off rule as a leftover. The other limit
     * needs no code: the directive maps are keyed by the files collection
     * actually analysed, so a run scoped to part of the tree never sees an
     * annotation outside it.
     *
     * A directive that carries no rule filter at all is never counted. It
     * says "whatever is here", so there is no channel whose producer could be
     * consulted, and reporting it stale would mean reporting the file's
     * cleanliness as a defect.
     *
     * Nor does accounting start for a directive whose pair
     * {@see ChannelLevelAddressing} already refused: `coupling.cbo:project`,
     * naming a level `coupling.cbo` never reports at, can never be silenced
     * by any finding, so calling it stale on top of the
     * `annotation.unresolved-directive` {@see DirectiveAddressability} already
     * raised would answer one mistake twice.
     *
     * @param list<string> $only
     * @param list<string> $disabled
     */
    private function isAccountable(Suppression $suppression, array $only, array $disabled): bool
    {
        $target = $suppression->target();
        if ($target->appliesToEveryChannel()) {
            return false;
        }

        if ($this->levels->problemWith((string) $target) !== null) {
            return false;
        }

        foreach ($this->addressedCodes($suppression) as $code) {
            $producer = $this->identity->producerOf($code);
            if ($producer === null) {
                continue;
            }

            if (
                $this->ruleSelector->isProducerEnabled($producer, $only, $disabled)
                && !$this->ruleConfiguration->isRuleDisabledByOptions($producer)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * The finding codes a target addresses.
     *
     * @return list<string>
     */
    private function addressedCodes(Suppression $suppression): array
    {
        $selector = $suppression->target()->selector();
        if ($selector === null) {
            return [];
        }

        $codes = [];
        foreach ($this->identity->expand($selector->channel()) as $channel) {
            $codes[] = $channel->code;
        }

        return $codes;
    }
}
