<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\CodeSmell;

use Qualimetrix\Infrastructure\DependencyInjection\Configurator\ContainerConfiguratorInterface;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Resource\GlobResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/** Registers the exact collector and rule roots owned by CodeSmell. */
final class CodeSmellConfigurator implements ContainerConfiguratorInterface
{
    private const string NAMESPACE = 'Qualimetrix\\Analysis\\Evidence\\CodeSmell\\';

    public function __construct(private readonly string $srcDir) {}

    public function configure(ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator($this->srcDir));
        foreach (['' => '*', 'ParameterCount\\' => 'ParameterCount/*', 'RepeatedExpression\\' => 'RepeatedExpression/*', 'UnreachableCode\\' => 'UnreachableCode/*', 'UnusedPrivate\\' => 'UnusedPrivate/*'] as $namespace => $root) {
            $loader->registerClasses(
                (new Definition())->setAutoconfigured(true)->setAutowired(true),
                self::NAMESPACE . $namespace,
                $this->srcDir . '/Analysis/Evidence/CodeSmell/' . $root . 'Collector.php',
            );
        }
        foreach ($this->ruleResources() as $resource) {
            // Keep a glob prefix so the loader derives the file's PSR-4 directory.
            $loader->registerClasses(
                (new Definition())->setAutoconfigured(true)->setAutowired(false)->setLazy(true),
                self::NAMESPACE . $resource['namespace'],
                \dirname($resource['path']) . '/{' . $resource['name'] . '}',
            );
        }
    }

    /** @return list<array{namespace: string, path: string, name: string}> */
    private function ruleResources(): array
    {
        $resources = [];
        foreach (['' => '*', 'BooleanArgument\\' => 'BooleanArgument/*', 'ControlFlow\\' => 'ControlFlow/*', 'RepeatedExpression\\' => 'RepeatedExpression/*', 'ParameterCount\\' => 'ParameterCount/*', 'UnreachableCode\\' => 'UnreachableCode/*', 'UnusedPrivate\\' => 'UnusedPrivate/*'] as $namespace => $root) {
            $files = new GlobResource($this->srcDir . '/Analysis/Evidence/CodeSmell', '/' . $root . 'Rule.php', false);
            foreach ($files as $path => $file) {
                $name = $file->getBasename();
                if (str_starts_with($name, 'Abstract')) {
                    continue;
                }
                $resources[] = ['namespace' => $namespace, 'path' => $path, 'name' => $name];
            }
        }
        // Channel order breaks ties in published directive-name suggestions.
        usort($resources, static fn(array $left, array $right): int => $left['name'] <=> $right['name']);

        return $resources;
    }
}
