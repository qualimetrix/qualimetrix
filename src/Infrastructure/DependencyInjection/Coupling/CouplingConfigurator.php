<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Coupling;

use Qualimetrix\Analysis\Evidence\Coupling\Contract\Configuration\CouplingConfiguratorInterface;

use Qualimetrix\Infrastructure\DependencyInjection\Configurator\ContainerConfiguratorInterface;
use Qualimetrix\Infrastructure\DependencyInjection\Registration\EvidenceRegistration;
use Symfony\Component\Config\Resource\GlobResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** Registers Coupling through its public configuration contract and exact roots. */
final class CouplingConfigurator implements ContainerConfiguratorInterface
{
    private const string NAMESPACE = 'Qualimetrix\\Analysis\\Evidence\\Coupling\\';
    private const string ANALYSIS = self::NAMESPACE . 'CouplingAnalysis';
    private const string SECTION = self::NAMESPACE . 'Configuration\\CouplingSection';

    public function __construct(private readonly string $srcDir) {}

    public function configure(ContainerBuilder $container): void
    {
        $loader = EvidenceRegistration::loader($container, $this->srcDir);
        foreach ([
            ['', '{Abstractness}'],
            ['ClassRank\\', 'ClassRank/*'],
            ['', '{Coupling}'],
            ['Distance\\', 'Distance/*'],
            ['Rfc\\', 'Rfc/*'],
        ] as [$namespace, $root]) {
            $loader->registerClasses(
                EvidenceRegistration::collectors(),
                self::NAMESPACE . $namespace,
                $this->srcDir . '/Analysis/Evidence/Coupling/' . $root . 'Collector.php',
            );
        }
        foreach ($this->ruleResources() as $resource) {
            $loader->registerClasses(
                EvidenceRegistration::rules(),
                self::NAMESPACE . $resource['namespace'],
                \dirname($resource['path']) . '/{' . $resource['name'] . '}',
            );
        }

        $container->register(self::ANALYSIS, self::ANALYSIS);
        $container->setAlias(CouplingConfiguratorInterface::class, self::ANALYSIS)
            ->setPublic(true);
        $container->register(self::SECTION)->setAutoconfigured(true);
    }

    /** @return list<array{namespace: string, path: string, name: string}> */
    private function ruleResources(): array
    {
        $resources = [];
        foreach (['' => '*', 'Cbo\\' => 'Cbo/*', 'Distance\\' => 'Distance/*', 'ClassRank\\' => 'ClassRank/*'] as $namespace => $root) {
            $files = new GlobResource($this->srcDir . '/Analysis/Evidence/Coupling', '/' . $root . 'Rule.php', false);
            foreach ($files as $path => $file) {
                $resources[] = ['namespace' => $namespace, 'path' => $path, 'name' => $file->getBasename()];
            }
        }
        usort($resources, static fn(array $left, array $right): int => $left['name'] <=> $right['name']);

        return $resources;
    }
}
