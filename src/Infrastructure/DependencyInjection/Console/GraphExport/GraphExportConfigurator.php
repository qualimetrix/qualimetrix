<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Console\GraphExport;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyGraphBuilderInterface;

use Qualimetrix\Analysis\Evidence\DependencyModel\Contract\DependencyTraversalParticipantInterface;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarFactory;
use Qualimetrix\Analysis\Run\Contract\DependencyGraph\DependencyGraphAnalyzerInterface;
use Qualimetrix\Analysis\Run\Contract\Discovery\ProjectFilesInterface;
use Qualimetrix\Core\Ast\FileParserInterface;
use Qualimetrix\Infrastructure\Console\GraphExport\GraphExportCommand;
use Qualimetrix\Infrastructure\Console\RunSetup\Input\AnalysisPreflight;
use Qualimetrix\Infrastructure\Console\RunTarget\RunTargetSession;
use Qualimetrix\Infrastructure\Console\Terminal\Diagnostics\ErrorStream;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\ContainerConfiguratorInterface;
use Qualimetrix\Infrastructure\Logging\DelegatingLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class GraphExportConfigurator implements ContainerConfiguratorInterface
{
    private const string DEPENDENCY_GRAPH_ANALYZER = 'qmx.run.dependency_graph_analyzer';
    private const string DEPENDENCY_GRAPH_ANALYZER_CLASS = 'Qualimetrix\\Analysis\\Run\\Pipeline\\DependencyGraphAnalyzer';

    public function configure(ContainerBuilder $container): void
    {
        $container->register(self::DEPENDENCY_GRAPH_ANALYZER, self::DEPENDENCY_GRAPH_ANALYZER_CLASS)
                    ->setArguments([
                        new Reference(ProjectFilesInterface::class),
                        new Reference(FileParserInterface::class),
                        new Reference(DependencyTraversalParticipantInterface::class),
                        new Reference(DependencyGraphBuilderInterface::class),
                        new Reference(DeclarationRegistrarFactory::class),
                    ]);
        $container->setAlias(DependencyGraphAnalyzerInterface::class, self::DEPENDENCY_GRAPH_ANALYZER);
        $container->register(GraphExportCommand::class)
                    ->setArguments([
                        new Reference(DependencyGraphAnalyzerInterface::class),
                        new Reference('Qualimetrix\\Reporting\\GraphProjection\\Contract\\DependencyGraphProjectionInterface'),
                        new Reference(AnalysisPreflight::class),
                        new Reference(ErrorStream::class),
                        new Reference(RunTargetSession::class),
                        new Reference(DelegatingLogger::class),
                    ])
                    ->setPublic(true);
    }
}
