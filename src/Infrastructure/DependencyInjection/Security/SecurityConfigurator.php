<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Security;

use Qualimetrix\Infrastructure\DependencyInjection\Configurator\ContainerConfiguratorInterface;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Resource\GlobResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/** Registers the exact collector and rule roots owned by Security. */
final class SecurityConfigurator implements ContainerConfiguratorInterface
{
    private const string NAMESPACE = 'Qualimetrix\\Analysis\\Evidence\\Security\\';

    public function __construct(private readonly string $srcDir) {}

    public function configure(ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator($this->srcDir));
        $loader->registerClasses(
            (new Definition())->setAutoconfigured(true)->setAutowired(true),
            self::NAMESPACE,
            $this->srcDir . '/Analysis/Evidence/Security/**/*Collector.php',
        );
        foreach ($this->ruleResources() as $resource) {
            $loader->registerClasses(
                (new Definition())->setAutoconfigured(true)->setAutowired(false)->setLazy(true),
                self::NAMESPACE . $resource['namespace'],
                \dirname($resource['path']) . '/{' . $resource['name'] . '}',
                $this->srcDir . '/Analysis/Evidence/Security/DirectSinks/Abstract*Rule.php',
            );
        }
    }

    /** @return list<array{namespace: string, path: string, name: string}> */
    private function ruleResources(): array
    {
        $resources = [];
        foreach (['' => '*', 'DirectSinks\\' => 'DirectSinks/*'] as $namespace => $root) {
            $files = new GlobResource($this->srcDir . '/Analysis/Evidence/Security', '/' . $root . 'Rule.php', false);
            foreach ($files as $path => $file) {
                $name = $file->getBasename();
                $resources[] = ['namespace' => $namespace, 'path' => $path, 'name' => $name];
            }
        }
        usort($resources, static fn(array $left, array $right): int => $left['name'] <=> $right['name']);

        return $resources;
    }
}
