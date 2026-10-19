<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Run;

use Qualimetrix\Analysis\Run\Contract\Discovery\GeneratedFileFilterInterface;

use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectFilesInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectTreeQueryInterface;
use Qualimetrix\Analysis\Run\Discovery\EntryInspector;
use Qualimetrix\Analysis\Run\Discovery\EntryInspectorInterface;
use Qualimetrix\Analysis\Run\Discovery\ProjectFiles;
use Qualimetrix\Analysis\Run\Discovery\ProjectTree;
use Qualimetrix\Analysis\Run\Discovery\ProjectWalk;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\ContainerConfiguratorInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class DiscoveryConfigurator implements ContainerConfiguratorInterface
{
    private const string GENERATED_FILE_FILTER = 'qmx.run.generated_file_filter';
    private const string GENERATED_FILE_FILTER_CLASS = 'Qualimetrix\\Analysis\\Run\\Discovery\\GeneratedFileFilter';

    public function configure(ContainerBuilder $container): void
    {
        $container->register(EntryInspector::class);
        $container->setAlias(EntryInspectorInterface::class, EntryInspector::class);
        $container->register(ProjectWalk::class)->setArgument('$inspector', new Reference(EntryInspectorInterface::class));
        $container->register(ProjectTree::class)->setArgument('$inspector', new Reference(EntryInspectorInterface::class));
        $container->setAlias(ProjectTreeQueryInterface::class, ProjectTree::class);
        $container->register(self::GENERATED_FILE_FILTER, self::GENERATED_FILE_FILTER_CLASS);
        $container->setAlias(GeneratedFileFilterInterface::class, self::GENERATED_FILE_FILTER);
        $container->register(ProjectFiles::class)->setArguments([
            new Reference(ProjectWalk::class),
            new Reference(GeneratedFileFilterInterface::class),
        ]);
        $container->setAlias(ProjectFilesInterface::class, ProjectFiles::class);
    }
}
