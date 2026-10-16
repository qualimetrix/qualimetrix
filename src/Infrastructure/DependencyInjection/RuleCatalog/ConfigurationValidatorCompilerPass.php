<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\RuleCatalog;

use LogicException;

use Qualimetrix\Analysis\Finding\Contract\ConfigurationValidatorInterface;
use Qualimetrix\Analysis\Finding\RuleMaterialization;
use Qualimetrix\Infrastructure\DependencyInjection\CompilerPass\ConsumerBoundPassInterface;
use Qualimetrix\Infrastructure\Rule\ConfigurationValidatorRegistry;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
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
final class ConfigurationValidatorCompilerPass implements CompilerPassInterface, ConsumerBoundPassInterface
{
    public const string TAG = 'qmx.configuration_validator';

    public static function consumerServiceIds(): array
    {
        return [ConfigurationValidatorRegistry::class, RuleMaterialization::class];
    }

    public function process(ContainerBuilder $container): void
    {
        [$validators, $validatorClasses] = $this->collectValidators($container);

        if ($container->hasDefinition(ConfigurationValidatorRegistry::class)) {
            $container->getDefinition(ConfigurationValidatorRegistry::class)
                ->setArgument('$validatorClasses', $validatorClasses);
        }

        if (!$container->hasDefinition(RuleMaterialization::class)) {
            return;
        }

        $container->getDefinition(RuleMaterialization::class)
            ->setArgument('$validators', $validators);
    }

    /** @return array{list<array{producer: string, create: ServiceClosureArgument}>, list<class-string>} */
    private function collectValidators(ContainerBuilder $container): array
    {
        $validators = [];
        $validatorClasses = [];

        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $_tags) {
            $class = $this->validatedClass($container, $id);

            $container->getDefinition($id)->setShared(false);
            $validators[] = [
                'producer' => $class::producerRuleName(),
                'create' => new ServiceClosureArgument(new Reference($id)),
            ];
            $validatorClasses[] = $class;
        }

        return [$validators, $validatorClasses];
    }

    /** @return class-string<ConfigurationValidatorInterface> */
    private function validatedClass(ContainerBuilder $container, string $id): string
    {
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

        if (!is_a($class, ConfigurationValidatorInterface::class, true)) {
            throw new LogicException(\sprintf(
                'Service "%s" is tagged "%s" but its class %s does not implement %s.',
                $id,
                self::TAG,
                $class,
                ConfigurationValidatorInterface::class,
            ));
        }

        return $class;
    }
}
