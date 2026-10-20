<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Design;

use Qualimetrix\Analysis\Evidence\Design\Inheritance\Contract\ExternalParentSourceInterface;

use Qualimetrix\Infrastructure\Composer\Contract\AnalysedInstallAnchorInterface;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\ContainerConfiguratorInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/** Registers the exact collector and rule roots owned by Design. */
final class DesignConfigurator implements ContainerConfiguratorInterface
{
    private const string NAMESPACE = 'Qualimetrix\\Analysis\\Evidence\\Design\\';

    private const string AUTOLOAD_MAP = 'Qualimetrix\\Infrastructure\\Composer\\ComposerAutoloadMap';

    private const string PARENT_READER = 'Qualimetrix\\Infrastructure\\Composer\\DeclaredParentReader';

    /**
     * @var list<string>
     */
    private const array RULES = [
        'DataClass\\DataClassRule',
        'GodClass\\GodClassRule',
        'Inheritance\\InheritanceRule',
        'Inheritance\\NocRule',
        'TypeCoverage\\ParamTypeCoverageRule',
        'TypeCoverage\\ReturnTypeCoverageRule',
        'TypeCoverage\\PropertyTypeCoverageRule',
    ];

    public function __construct(private readonly string $srcDir) {}

    public function configure(ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator($this->srcDir));
        $loader->registerClasses(
            (new Definition())->setAutoconfigured(true)->setAutowired(true),
            self::NAMESPACE,
            $this->srcDir . '/Analysis/Evidence/Design/**/*Collector.php',
        );

        // Neither of these ends in `Collector`, so the glob above does not see
        // them. They are registered here rather than anywhere else because they
        // exist for one consumer: DIT's walk out of the analysed path.
        $this->registerExternalAncestry($container);

        foreach (self::RULES as $rule) {
            $container->register(self::NAMESPACE . $rule)
                ->setAutoconfigured(true)
                ->setAutowired(false)
                ->setLazy(true);
        }
    }

    /**
     * DIT's walk out of the analysed path, and the adapter that reads for it.
     *
     * Named as strings, like the rules above: a configurator that imported
     * these would be importing another owner's internals, which the manifest
     * refuses. Registration is composition, not consumption.
     */
    private function registerExternalAncestry(ContainerBuilder $container): void
    {
        $container->register(self::AUTOLOAD_MAP)->setAutowired(true);
        $container->register(self::PARENT_READER)->setAutowired(true);
        $container->register(self::NAMESPACE . 'Inheritance\\ExternalAncestry')->setAutowired(true);

        $container->setAlias(ExternalParentSourceInterface::class, self::PARENT_READER);
        $container->setAlias(AnalysedInstallAnchorInterface::class, self::PARENT_READER);
    }
}
