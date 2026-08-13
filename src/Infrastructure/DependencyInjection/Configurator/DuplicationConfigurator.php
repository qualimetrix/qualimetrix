<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Analysis\Configuration\Contract\TransitionalRuntimeConfigurationProviderInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Configures the Duplication evidence capability without exposing its internals.
 *
 * The detector and result provider are registered by string service IDs so
 * Infrastructure does not import Duplication internals. Autoconfiguration
 * connects the detector to the consumer-owned Analysis.Run
 * FileSetInspectionParticipantInterface. The rule is discovered from the
 * capability root with the same lazy,
 * non-autowired prototype as other rules; RuleOptionsCompilerPass supplies its
 * Options value and the provider dependency. Algorithm helpers remain ordinary
 * module objects constructed by the detector and are not container services.
 */
final class DuplicationConfigurator implements ContainerConfiguratorInterface
{
    private const string NAMESPACE = 'Qualimetrix\\Analysis\\Evidence\\Duplication\\';

    private const string DETECTOR = self::NAMESPACE . 'DuplicationDetector';

    private const string RESULT_PROVIDER = self::NAMESPACE . 'DuplicationResultProvider';

    public function __construct(
        private readonly string $srcDir,
    ) {}

    public function configure(ContainerBuilder $container): void
    {
        $this->registerRule($container);

        $container->register(self::RESULT_PROVIDER, self::RESULT_PROVIDER);
        $container->register(self::DETECTOR, self::DETECTOR)
            ->setAutoconfigured(true)
            ->setArguments([
                '$configurationProvider' => new Reference(TransitionalRuntimeConfigurationProviderInterface::class),
                '$resultProvider' => new Reference(self::RESULT_PROVIDER),
                '$logger' => new Reference('Qualimetrix\\Infrastructure\\Logging\\DelegatingLogger'),
            ]);
    }

    private function registerRule(ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator($this->srcDir));
        $prototype = (new Definition())
            ->setAutoconfigured(true)
            ->setAutowired(false)
            ->setLazy(true);

        $loader->registerClasses(
            $prototype,
            self::NAMESPACE,
            $this->srcDir . '/Analysis/Evidence/Duplication/*Rule.php',
        );
    }
}
