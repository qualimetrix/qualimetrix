<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Directive;

use Qualimetrix\Analysis\Evidence\Measurement\Contract\SymbolLevel;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclaration;
use Qualimetrix\Analysis\Finding\Contract\Rule\AbstractRule;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleCategory;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Violation;

/**
 * Reports inline `@qmx` directives that stopped doing anything.
 *
 * One channel, `annotation.unused-directive`: a suppression or override that
 * addressed something real and simply did not fire this run. That is ordinary
 * debt cleanup a project may schedule, which is why it is a rule's finding and
 * not a validator's.
 *
 * The loud half — a directive that can never work — belongs to
 * {@see InlineDirectiveValidator}, which runs in this rule's slot and under
 * this rule's name.
 *
 * The rule emits nothing itself. What it does is arm the usage report: the
 * question "did this suppression suppress anything" is answerable only after
 * every rule has produced its findings, so the finding is assembled later, by
 * {@see InlineDirectivePolicy}, and only when this rule ran and was enabled.
 * One switch, not two.
 */
final class InlineDirectiveRule extends AbstractRule
{
    public const string NAME = InlineDirectivePolicy::PRODUCER_RULE_NAME;
    public const string DOCS_PAGE = 'rules/annotation.md';

    public const int REMEDIATION_MINUTES = 15;

    public function __construct(
        RuleOptionsInterface $options,
        private readonly InlineDirectivePolicy $policy,
    ) {
        parent::__construct($options);
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDescription(): string
    {
        return 'Reports inline @qmx directives that address nothing, cannot apply, or no longer do anything.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::Annotation;
    }

    /** @return list<string> */
    public function requires(): array
    {
        return [];
    }

    /**
     * @return class-string<InlineDirectiveOptions>
     */
    public static function getOptionsClass(): string
    {
        return InlineDirectiveOptions::class;
    }

    /**
     * A threshold override is a statement about this rule's own options, and
     * this rule has no numeric threshold to retune.
     */
    public const bool SUPPORTS_THRESHOLD_OVERRIDE = false;

    /**
     * An `occurrence` at the level of the file the annotation is written in:
     * the channel reports on the configuration surface rather than on a
     * measured quantity, but a leftover annotation is still code debt, so it
     * stays declared by a rule.
     *
     * @return array<string, ChannelDeclaration>
     */
    public static function channelDeclarations(): array
    {
        $name = InlineDirectivePolicy::UNUSED_DIRECTIVE_NAME;

        return [$name . '#' . $name => ChannelDeclaration::occurrence(SymbolLevel::File)];
    }

    /**
     * @return list<Violation>
     */
    public function analyze(AnalysisContext $context): array
    {
        \assert($this->options instanceof InlineDirectiveOptions);

        if (!$this->options->isEnabled()) {
            return [];
        }

        $this->policy->enableUsageReporting($this->options->unusedDirectiveSeverity);

        return [];
    }
}
