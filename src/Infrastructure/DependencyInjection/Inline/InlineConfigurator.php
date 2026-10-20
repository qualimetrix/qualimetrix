<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Inline;

use Qualimetrix\Analysis\Finding\Contract\Channel\ChannelDeclarationRegistryInterface;

use Qualimetrix\Analysis\Finding\Contract\Channel\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\InlineDirectivePolicyInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\ThresholdDirectiveAuditInterface;
use Qualimetrix\Analysis\Policy\Inline\Extraction\SuppressionExtractor;
use Qualimetrix\Analysis\Policy\Inline\Threshold\ThresholdOverrideExtractor;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\ContainerConfiguratorInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class InlineConfigurator implements ContainerConfiguratorInterface
{
    private const string SOURCE_CONTROL_EXTRACTOR_CLASS = 'Qualimetrix\\Analysis\\Policy\\Inline\\Extraction\\SourceControlExtractor';
    private const string INLINE_REFUSED_DIRECTIVES_CLASS = 'Qualimetrix\\Analysis\\Policy\\Inline\\Directive\\RefusedDirectives';
    private const string INLINE_DIRECTIVE_POLICY_CLASS = 'Qualimetrix\\Analysis\\Policy\\Inline\\Directive\\InlineDirectivePolicy';
    private const string INLINE_DIRECTIVE_USAGE_CLASS = 'Qualimetrix\\Analysis\\Policy\\Inline\\Directive\\Audit\\DirectiveUsage';
    private const string INLINE_THRESHOLD_AUDIT_CLASS = 'Qualimetrix\\Analysis\\Policy\\Inline\\Directive\\Audit\\ThresholdDirectiveAudit';
    private const string INLINE_DIRECTIVE_RULE_CLASS = 'Qualimetrix\\Analysis\\Policy\\Inline\\Directive\\UnusedDirectiveRule';
    private const string INLINE_DIRECTIVE_VALIDATOR_CLASS = 'Qualimetrix\\Analysis\\Policy\\Inline\\Directive\\InlineDirectiveValidator';

    public function configure(ContainerBuilder $container): void
    {
        $container->register(ThresholdOverrideExtractor::class)
                    ->setArguments(['$validators' => []]);
        $container->register(SuppressionExtractor::class);
        $privateExtractorId = self::SOURCE_CONTROL_EXTRACTOR_CLASS;
        $container->register($privateExtractorId, $privateExtractorId)
                    ->setArguments([
                        new Reference(SuppressionExtractor::class),
                        new Reference(ThresholdOverrideExtractor::class),
                    ]);
        $this->registerInlineDirectivePolicy($container);
    }

    /**
         * The inline-directive capability's own run state, plus the rule that
         * reports on it. The rule is registered here rather than by a scan
         * because Inline owns exactly one rule and an open-ended pattern would
         * silently enrol a future sibling.
         */
    private function registerInlineDirectivePolicy(ContainerBuilder $container): void
    {
        $container->register(self::INLINE_REFUSED_DIRECTIVES_CLASS, self::INLINE_REFUSED_DIRECTIVES_CLASS)
            ->setArguments([new Reference(ChannelIdentityInterface::class)]);
        $container->register(self::INLINE_DIRECTIVE_USAGE_CLASS, self::INLINE_DIRECTIVE_USAGE_CLASS)
            ->setArguments([
                new Reference(ChannelIdentityInterface::class),
                new Reference(RuleConfigurationInterface::class),
                new Reference(ChannelDeclarationRegistryInterface::class),
                new Reference(self::INLINE_REFUSED_DIRECTIVES_CLASS),
            ]);

        // The threshold half is a service of its own rather than a method on
        // the policy: it needs no run state at all — the run hands it the
        // context it already prepared — while the policy is exactly that state.
        $container->register(self::INLINE_THRESHOLD_AUDIT_CLASS, self::INLINE_THRESHOLD_AUDIT_CLASS)
            ->setArguments([
                new Reference(RuleConfigurationInterface::class),
                new Reference(self::INLINE_REFUSED_DIRECTIVES_CLASS),
            ]);
        $container->setAlias(ThresholdDirectiveAuditInterface::class, self::INLINE_THRESHOLD_AUDIT_CLASS);

        // The usage accounting is injected rather than built by the policy:
        // the policy is the run's directive store, and the collaborators the
        // accounting needs are not the store's.
        $container->register(self::INLINE_DIRECTIVE_POLICY_CLASS, self::INLINE_DIRECTIVE_POLICY_CLASS)
            ->setArguments([new Reference(self::INLINE_DIRECTIVE_USAGE_CLASS), new Reference(self::INLINE_REFUSED_DIRECTIVES_CLASS)])
            ->setPublic(true);
        $container->setAlias(InlineDirectivePolicyInterface::class, self::INLINE_DIRECTIVE_POLICY_CLASS)
            ->setPublic(true);

        // Registered before the rule, and that order is load-bearing: channels
        // enter the universe in the order their producers are registered, and
        // this family's published order has the three directive diagnostics
        // ahead of `annotation.unused-directive`. See
        // ChannelDeclarationCompilerPass.
        $container->register(self::INLINE_DIRECTIVE_VALIDATOR_CLASS, self::INLINE_DIRECTIVE_VALIDATOR_CLASS)
            ->setArguments([
                new Reference(self::INLINE_DIRECTIVE_POLICY_CLASS),
                new Reference(self::INLINE_REFUSED_DIRECTIVES_CLASS),
            ])
            ->setAutoconfigured(true)
            ->setAutowired(false)
            ->setLazy(true);

        $container->register(self::INLINE_DIRECTIVE_RULE_CLASS, self::INLINE_DIRECTIVE_RULE_CLASS)
            ->setAutoconfigured(true)
            ->setAutowired(false)
            ->setLazy(true);
    }
}
