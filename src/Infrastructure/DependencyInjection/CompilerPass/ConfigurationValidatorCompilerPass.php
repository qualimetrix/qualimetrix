<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\CompilerPass;

use LogicException;
use Qualimetrix\Infrastructure\Rule\ConfigurationValidatorRegistry;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Collects every service tagged `qmx.configuration_validator` and hands the
 * list to the Finding-owned executor, which runs each one in its producer
 * rule's slot.
 *
 * Mirrors {@see RuleCompilerPass} deliberately: a validator, like a rule, may
 * declare constructor dependencies only the container can resolve, so
 * consumers never build one themselves.
 */
final class ConfigurationValidatorCompilerPass implements CompilerPassInterface
{
    public const string TAG = 'qmx.configuration_validator';

    private const string VALIDATOR_INTERFACE = 'Qualimetrix\\Analysis\\Finding\\Contract\\ConfigurationValidatorInterface';
    private const string RULE_EXECUTION = 'Qualimetrix\\Analysis\\Finding\\RuleExecution';

    public function process(ContainerBuilder $container): void
    {
        $validators = [];
        $validatorClasses = [];

        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $tags) {
            $class = $container->getDefinition($id)->getClass();

            // Refused, not skipped. A validator dropped here runs never and
            // declares nothing, and no second reader would notice the loss:
            // ChannelDeclarationCompilerPass reads the same tag and would be
            // missing the same member, so the two-witness check would agree
            // about a universe both are short of.
            if ($class === null) {
                throw new LogicException(\sprintf(
                    'Service "%s" is tagged "%s" but its definition names no class. A validator is read by'
                    . ' reflection off its class; without one it would silently neither run nor declare anything.',
                    $id,
                    self::TAG,
                ));
            }

            if (!is_a($class, self::VALIDATOR_INTERFACE, true)) {
                throw new LogicException(\sprintf(
                    'Service "%s" is tagged "%s" but its class %s does not implement %s.',
                    $id,
                    self::TAG,
                    $class,
                    self::VALIDATOR_INTERFACE,
                ));
            }

            $validators[] = new Reference($id);
            $validatorClasses[] = $class;
        }

        if ($container->hasDefinition(ConfigurationValidatorRegistry::class)) {
            $container->getDefinition(ConfigurationValidatorRegistry::class)
                ->setArgument('$validatorClasses', $validatorClasses);
        }

        if (!$container->hasDefinition(self::RULE_EXECUTION)) {
            return;
        }

        $container->getDefinition(self::RULE_EXECUTION)
            ->setArgument('$configurationValidators', $validators);
    }
}
