<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

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
 * `tests/Analysis/Finding/Fixtures/Channels/order.txt` records. Collectors
 * declare no channels, so they stay globbed.
 */
final class DesignConfigurator implements ContainerConfiguratorInterface
{
    private const string NAMESPACE = 'Qualimetrix\\Analysis\\Evidence\\Design\\';

    /**
     * In published-channel order.
     *
     * @var list<string>
     */
    private const array RULES = [
        'DataClassRule',
        'GodClassRule',
        'InheritanceRule',
        'NocRule',
        'ParamTypeCoverageRule',
        'ReturnTypeCoverageRule',
        'PropertyTypeCoverageRule',
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

        foreach (self::RULES as $rule) {
            $container->register(self::NAMESPACE . $rule)
                ->setAutoconfigured(true)
                ->setAutowired(false)
                ->setLazy(true);
        }
    }
}
