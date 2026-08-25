<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Directive;

use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\Finding;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleSelector;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\InlineDirectivePolicyInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Threshold\ThresholdDiagnostic;
use Qualimetrix\Analysis\Policy\Inline\Suppression\SuppressionFilter;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;

/**
 * Per-run state for the inline-directive subject.
 *
 * Mirrors the layer policy: Run prepares it, the owning rule reads it, and
 * nothing outside this capability touches the directives themselves.
 *
 * The usage half is separate from the validation half for a reason that is
 * about time, not taste. Whether a name addresses anything is knowable the
 * moment configuration has resolved, so the rule answers it while it runs.
 * Whether a suppression suppressed anything is knowable only once every rule
 * has produced its findings, which is after every rule — including this one —
 * has finished. So Run asks twice, and the second answer is assembled here
 * rather than at the call site, so the channel identity stays with its owner.
 *
 * @qmx-threshold coupling.instability warning=0.89 -- Ca=2, raw Ce=15 (I=0.882): this class had one consumer (its owning rule) until `InlineDirectiveValidator` became a second; `min_afferent: 2` never measured it before that, so the fifteen outgoing edges are not new, only now counted. The actual fix is splitting directive storage from finding-building, which is not done here — it is recorded as debt in the rule-vocabulary plan's Ш5 "Debt Ш3" section, not accepted as the permanent shape of this class. Raw Ce=15 gets one-edge headroom: at Ce=16, I=0.889, still under 0.89; at Ce=17, I=0.895, over it.
 */
final class InlineDirectivePolicy implements InlineDirectivePolicyInterface
{
    /** @var array<string, list<Suppression>> */
    private array $suppressions = [];

    /** @var array<string, list<ThresholdOverride>> */
    private array $thresholdOverrides = [];

    /** @var array<string, list<ThresholdDiagnostic>> */
    private array $thresholdDiagnostics = [];

    /**
     * Off until the owning rule turns it on, which it does only when it
     * actually runs and is enabled. One switch, not two.
     */
    private ?Severity $usageReportingSeverity = null;

    public function __construct(
        private readonly ChannelIdentityInterface $identity,
        private readonly RuleSelector $ruleSelector,
        private readonly RuleConfigurationInterface $ruleConfiguration,
    ) {}

    public function prepare(array $suppressions, array $thresholdOverrides, array $thresholdDiagnostics): void
    {
        $this->suppressions = $suppressions;
        $this->thresholdOverrides = $thresholdOverrides;
        $this->thresholdDiagnostics = $thresholdDiagnostics;
        $this->usageReportingSeverity = null;
    }

    public function reset(): void
    {
        $this->prepare([], [], []);
    }

    /**
     * The directives **as authored**, one entry per line of source, keyed by
     * file.
     *
     * This is the only view the reporting side is ever given, and the reason
     * is the extraction shape: a directive written on a class docblock is
     * materialised once per declaration that docblock governs — the class and
     * every method in it. Those are bindings of one annotation, not several
     * annotations, so a report that lists them separately says "you made
     * forty-one mistakes" about a single typo. Since a configuration error
     * ends the run past `fail_on`, that is precisely the report a reader
     * learns to skip.
     *
     * @return array<string, list<Suppression>>
     */
    public function authoredSuppressions(): array
    {
        return self::onePerAuthoredSite(
            $this->suppressions,
            static fn(Suppression $s): string => $s->line . "\0" . $s->type->value . "\0" . $s->rule,
        );
    }

    /** @return array<string, list<ThresholdOverride>> */
    public function authoredThresholdOverrides(): array
    {
        return self::onePerAuthoredSite(
            $this->thresholdOverrides,
            static fn(ThresholdOverride $o): string => $o->line . "\0" . $o->rulePattern,
        );
    }

    /** @return array<string, list<ThresholdDiagnostic>> */
    public function authoredThresholdDiagnostics(): array
    {
        return self::onePerAuthoredSite(
            $this->thresholdDiagnostics,
            static fn(ThresholdDiagnostic $d): string => $d->line . "\0" . ($d->code ?? '') . "\0" . $d->message,
        );
    }

    /**
     * Keeps the first binding of each authored site and drops the rest.
     *
     * Which binding survives does not matter: the reporting side never reads
     * a binding's subject, only the file and line the annotation was written
     * at. It is the identity key — line, form, and authored text — that
     * decides what counts as one directive.
     *
     * @template T of object
     *
     * @param array<string, list<T>> $byFile
     * @param callable(T): string $identity
     *
     * @return array<string, list<T>>
     */
    private static function onePerAuthoredSite(array $byFile, callable $identity): array
    {
        $authored = [];

        foreach ($byFile as $file => $items) {
            $seen = [];
            foreach ($items as $item) {
                $key = $identity($item);
                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $authored[$file][] = $item;
            }
        }

        return $authored;
    }

    /**
     * Called by the owning rule as it runs, which is what makes the rule's
     * own enablement the single gate on the post-execution half.
     */
    public function enableUsageReporting(Severity $severity): void
    {
        $this->usageReportingSeverity = $severity;
    }

    public function auditDirectiveUsage(array $findings): array
    {
        $severity = $this->usageReportingSeverity;
        if ($severity === null) {
            return [];
        }

        $selection = $this->ruleConfiguration->selection();
        $stale = [];

        foreach ($this->suppressions as $file => $fileSuppressions) {
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
     * The same identity as {@see authoredSuppressions()}, but keeping every
     * binding rather than one.
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
            ruleName: self::UNUSED_DIRECTIVE_NAME,
            code: self::UNUSED_DIRECTIVE_NAME,
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
     * @param list<string> $only
     * @param list<string> $disabled
     */
    private function isAccountable(Suppression $suppression, array $only, array $disabled): bool
    {
        $target = $suppression->target();
        if ($target->appliesToEveryChannel()) {
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
        foreach ($this->identity->expand($selector) as $channel) {
            $codes[] = $channel->code;
        }

        return $codes;
    }
}
