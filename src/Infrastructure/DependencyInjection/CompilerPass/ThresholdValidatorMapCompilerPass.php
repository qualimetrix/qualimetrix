<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\CompilerPass;

use Qualimetrix\Analysis\Finding\Contract\Rule\RuleDefinitionInterface;
use Qualimetrix\Analysis\Policy\Inline\Contract\RuleValidatorMapFactory;
use Qualimetrix\Analysis\Policy\Inline\Contract\ThresholdOverrideExtractor;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Builds the rule-name => OverrideValidatorInterface map from tagged rule
 * services and injects it into the {@see ThresholdOverrideExtractor}.
 *
 * Runs after {@see RuleRegistryCompilerPass} so the tag list is stable.
 * The Extractor itself is registered separately in the DI configurator;
 * this pass only supplies its `$validators` constructor argument.
 */
final class ThresholdValidatorMapCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ThresholdOverrideExtractor::class)) {
            return;
        }

        $ruleClasses = [];

        foreach ($container->findTaggedServiceIds(RuleRegistryCompilerPass::TAG) as $id => $tags) {
            $class = $container->getDefinition($id)->getClass();

            if ($class !== null) {
                $ruleClasses[] = $class;
            }
        }

        /** @var list<class-string<RuleDefinitionInterface>> $ruleClasses */
        $validators = RuleValidatorMapFactory::build($ruleClasses);

        $container->getDefinition(ThresholdOverrideExtractor::class)
            ->setArgument('$validators', $validators);
    }
}
