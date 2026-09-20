<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Analysis\Evidence\Design\Inheritance\Contract\ExternalParentSourceInterface;
use Qualimetrix\Infrastructure\Composer\Contract\AnalysedInstallAnchorInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * Registers the exact collector and rule roots owned by Design.
 *
 * The rules are named one by one rather than globbed, and the order they are
 * named in is the order their channels enter the universe — which is
 * published, because a "did you mean" answer breaks ties between equidistant
 * names by it. Three of these rules are equidistant siblings
 * (`param`/`return`/`property` type coverage), so under a glob the published
 * order of a finding's text would be decided by alphabetical filenames. The
 * order below is the decision the fixture
 * `governance/Channel/Fixtures/order.txt` records. Collectors
 * declare no channels, so they stay globbed.
 */
final class DesignConfigurator implements ContainerConfiguratorInterface
{
    private const string NAMESPACE = 'Qualimetrix\\Analysis\\Evidence\\Design\\';

    private const string AUTOLOAD_MAP = 'Qualimetrix\\Infrastructure\\Composer\\ComposerAutoloadMap';

    private const string PARENT_READER = 'Qualimetrix\\Infrastructure\\Composer\\DeclaredParentReader';

    /**
     * In published-channel order.
     *
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
