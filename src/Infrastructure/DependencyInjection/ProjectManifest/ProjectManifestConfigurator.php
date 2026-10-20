<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\ProjectManifest;

use Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestDecoder;
use Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestReaderInterface;
use Qualimetrix\Analysis\ProjectManifest\Contract\ManifestSnapshotControlInterface;
use Qualimetrix\Infrastructure\Composer\ComposerManifestReader;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\ContainerConfiguratorInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/** Exact registration of the manifest subject's IO adapter and invocation control. */
final class ProjectManifestConfigurator implements ContainerConfiguratorInterface
{
    public function configure(ContainerBuilder $container): void
    {
        $container->register(ComposerManifestDecoder::class);
        $container->register(ComposerManifestReader::class)->setArgument('$decoder', new Reference(ComposerManifestDecoder::class));
        $container->setAlias(ComposerManifestReaderInterface::class, ComposerManifestReader::class)->setPublic(true);
        $container->setAlias(ManifestSnapshotControlInterface::class, ComposerManifestReader::class)->setPublic(true);
    }
}
