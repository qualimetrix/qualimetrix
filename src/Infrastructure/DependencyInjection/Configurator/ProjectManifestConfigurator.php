<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Configurator;

use Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestDecoder;
use Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestReaderInterface;
use Qualimetrix\Analysis\ProjectManifest\Contract\ManifestSnapshotControlInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/** Exact registration of the manifest subject's IO adapter and invocation control. */
final class ProjectManifestConfigurator implements ContainerConfiguratorInterface
{
    public function configure(ContainerBuilder $container): void
    {
        $reader = 'Qualimetrix\\Infrastructure\\Composer\\ComposerManifestReader';
        $container->register(ComposerManifestDecoder::class);
        $container->register($reader, $reader)->setArgument('$decoder', new Reference(ComposerManifestDecoder::class));
        $container->setAlias(ComposerManifestReaderInterface::class, $reader)->setPublic(true);
        $container->setAlias(ManifestSnapshotControlInterface::class, $reader)->setPublic(true);
    }
}
