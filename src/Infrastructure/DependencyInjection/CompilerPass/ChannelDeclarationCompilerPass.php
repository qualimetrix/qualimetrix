<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\CompilerPass;

use LogicException;
use Qualimetrix\Core\Rule\ChannelDeclarationReader;
use Qualimetrix\Core\Rule\RuleInterface;
use Qualimetrix\Core\Rule\RuleNameReader;
use Qualimetrix\Core\Violation\ChannelDeclaration;
use Qualimetrix\Infrastructure\Rule\ChannelDeclarationRegistry;
use Qualimetrix\Infrastructure\Rule\RuleChannelRegistry;
use Qualimetrix\Rules\ComputedMetric\ComputedMetricRule;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Collects static channel declarations from every tagged rule service and
 * injects the assembled map into {@see ChannelDeclarationRegistry}, along
 * with the one rule name that marks the run-time `computed.*` / `health.*`
 * family.
 *
 * Mirrors {@see RuleRegistryCompilerPass}: it walks the same
 * `qmx.rule`-tagged services, reads metadata by reflection through
 * {@see ChannelDeclarationReader} (which — like {@see RuleNameReader} —
 * never instantiates the rule class), and hands the container a finished
 * map. `Core` may not depend on `Rules`, so this compile-time assembly step
 * is the only place a static map can be built at all; the registry itself
 * receives the result rather than going looking for it.
 *
 * Each rule's `channelDeclarations()` already returns full channel keys
 * (`ruleName#violationCode`, per {@see ChannelDeclarationReader}), so this
 * pass does no pairing of its own — it only detects the same key declared
 * twice.
 *
 * `ComputedMetricRule::NAME` is read here, in
 * `Infrastructure\DependencyInjection`, where depending on rule classes to
 * wire the container is already normal (see {@see RuleCompilerPass},
 * {@see RuleOptionsCompilerPass}), and passed to the registry as a plain
 * string constructor argument. This keeps `Infrastructure\Rule` — the
 * registry's own layer — free of a `rules` dependency edge it would
 * otherwise need just to read one constant.
 */
final class ChannelDeclarationCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ChannelDeclarationRegistry::class)) {
            return;
        }

        $definition = $container->getDefinition(ChannelDeclarationRegistry::class);

        /** @var array<string, ChannelDeclaration> $declarations */
        $declarations = [];
        /** @var array<string, list<string>> $channelKeysByProducer */
        $channelKeysByProducer = [];

        foreach ($container->findTaggedServiceIds(RuleRegistryCompilerPass::TAG) as $id => $tags) {
            $serviceDefinition = $container->getDefinition($id);
            $class = $serviceDefinition->getClass();

            if ($class === null) {
                continue;
            }

            // Narrows $class to class-string<RuleInterface> for
            // ChannelDeclarationReader::read() below (mirrors
            // RuleOptionsCompilerPass's is_a() narrowing). Unlike that pass,
            // a failing check here throws rather than skips: every service
            // reaching this loop is already tagged qmx.rule, which
            // autoconfiguration only applies to RuleInterface implementers —
            // a mismatch would mean the tag and the class have drifted apart,
            // and the rule's declarations should not silently vanish from
            // the registry because of it.
            if (!is_a($class, RuleInterface::class, true)) {
                throw new LogicException(\sprintf(
                    'Service "%s" is tagged "%s" but its class %s does not implement %s.',
                    $id,
                    RuleRegistryCompilerPass::TAG,
                    $class,
                    RuleInterface::class,
                ));
            }

            $ruleDeclarations = ChannelDeclarationReader::read($class);
            if ($ruleDeclarations === []) {
                continue;
            }

            $producerRuleName = RuleNameReader::read($class);
            foreach ($ruleDeclarations as $key => $declaration) {
                if (isset($declarations[$key])) {
                    throw new LogicException(\sprintf(
                        'Duplicate channel declaration for "%s" — declared by more than one rule class (last seen: %s).',
                        $key,
                        $class,
                    ));
                }

                $declarations[$key] = $declaration;
                $channelKeysByProducer[$producerRuleName][] = $key;
            }
        }

        $definition->setArgument('$staticDeclarations', $declarations);
        $definition->setArgument('$computedMetricRuleName', ComputedMetricRule::NAME);

        if ($container->hasDefinition(RuleChannelRegistry::class)) {
            $container->getDefinition(RuleChannelRegistry::class)
                ->setArgument('$staticChannelKeysByProducer', $channelKeysByProducer)
                ->setArgument('$computedMetricRuleName', ComputedMetricRule::NAME);
        }
    }
}
