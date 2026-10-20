<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\Baseline;

use Qualimetrix\Analysis\Finding\Contract\Channel\ChannelDeclarationRegistryInterface;

use Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface;
use Qualimetrix\Analysis\Policy\Baseline\Capture\BaselineGenerator;
use Qualimetrix\Analysis\Policy\Baseline\ChannelRename\BaselineChannelRenamer;
use Qualimetrix\Analysis\Policy\Baseline\DocumentAccess\BaselineDocumentReader;
use Qualimetrix\Analysis\Policy\Baseline\DocumentReading\BaselineLoader;
use Qualimetrix\Analysis\Policy\Baseline\DocumentWriting\BaselineWriter;
use Qualimetrix\Analysis\Policy\Baseline\EntryMaintenance\BaselineCleaner;
use Qualimetrix\Analysis\Policy\Baseline\EntryMaintenance\BaselineUpdater;
use Qualimetrix\Analysis\Policy\Baseline\Explanation\BoundaryExplanationService;
use Qualimetrix\Analysis\Policy\Baseline\RunCoverage\RunRuleCoverage;
use Qualimetrix\Infrastructure\Console\Baseline\BaselineRunInterface;
use Qualimetrix\Infrastructure\Console\Baseline\ChannelRename\BaselineRenameChannelsCommand;
use Qualimetrix\Infrastructure\Console\Baseline\Explanation\BaselineConfiguredThresholds;
use Qualimetrix\Infrastructure\Console\Baseline\Explanation\BaselineExplainCommand;
use Qualimetrix\Infrastructure\Console\Baseline\Generation\BaselineGenerateCommand;
use Qualimetrix\Infrastructure\Console\Baseline\Maintenance\BaselineCleanupCommand;
use Qualimetrix\Infrastructure\Console\Baseline\Maintenance\BaselineUpdateCommand;
use Qualimetrix\Infrastructure\Console\Baseline\Measurement\BaselineRun;
use Qualimetrix\Infrastructure\Console\BaselineCommand\MeasuredFindingSet;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Qualimetrix\Infrastructure\Console\Terminal\Diagnostics\ErrorStream;
use Qualimetrix\Infrastructure\DependencyInjection\Configurator\ContainerConfiguratorInterface;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * @qmx-threshold coupling.cbo 28 -- Baseline command registration must bind the capture, review and acceptance operations of the same baseline lifecycle.
 */
final class BaselineCommandsConfigurator implements ContainerConfiguratorInterface
{
    public function configure(ContainerBuilder $container): void
    {
        $container->register(MeasuredFindingSet::class)
                    ->setAutowired(true);
        $this->registerBaselineCommands($container);
    }

    /**
         * The five commands of the baseline lifecycle, plus the run they all
         * measure against.
         *
         * `BaselineRun` is registered once and injected into every one of them:
         * that is what makes "one measured set" a property of the wiring rather
         * than a rule each command has to remember.
         */
    private function registerBaselineCommands(ContainerBuilder $container): void
    {
        $container->register(BaselineRun::class)
            ->setAutowired(true);

        $container->setAlias(BaselineRunInterface::class, BaselineRun::class);

        $container->register(BaselineConfiguredThresholds::class)
            ->setArguments([
                new Reference(RuleRegistryInterface::class),
                new Reference(RuleConfigurationInterface::class),
            ]);

        $container->register(BaselineGenerateCommand::class)
            ->setArguments([
                new Reference(BaselineRun::class),
                new Reference(BaselineGenerator::class),
                new Reference(BaselineWriter::class),
                new Reference(ErrorStream::class),
            ])
            ->addMethodCall('setRefusalPresenter', [new Reference(RefusalPresenter::class)])
            ->setPublic(true);

        $container->register(BaselineUpdateCommand::class)
            ->setArguments([
                new Reference(BaselineRun::class),
                new Reference(BaselineLoader::class),
                new Reference(BaselineDocumentReader::class),
                new Reference(BaselineUpdater::class),
                new Reference(BaselineWriter::class),
                new Reference(RunRuleCoverage::class),
            ])
            ->addMethodCall('setRefusalPresenter', [new Reference(RefusalPresenter::class)])
            ->setPublic(true);

        $container->register(BaselineCleanupCommand::class)
            ->setArguments([
                new Reference(BaselineRun::class),
                new Reference(BaselineLoader::class),
                new Reference(BaselineDocumentReader::class),
                new Reference(BaselineCleaner::class),
                new Reference(BaselineWriter::class),
                new Reference(ChannelDeclarationRegistryInterface::class),
                new Reference(RunRuleCoverage::class),
            ])
            ->addMethodCall('setRefusalPresenter', [new Reference(RefusalPresenter::class)])
            ->setPublic(true);

        // The one baseline command with no measured run: a carry substitutes a
        // declared name in a file and consults no analysis, so it takes the
        // renamer and nothing else.
        $container->register(BaselineRenameChannelsCommand::class)
            ->setArguments([
                new Reference(BaselineChannelRenamer::class),
                new Reference(BaselineDocumentReader::class),
            ])
            ->addMethodCall('setRefusalPresenter', [new Reference(RefusalPresenter::class)])
            ->setPublic(true);

        $container->register(BaselineExplainCommand::class)
            ->setArguments([
                new Reference(BaselineRun::class),
                new Reference(BaselineLoader::class),
                new Reference(BaselineDocumentReader::class),
                new Reference(BoundaryExplanationService::class),
                new Reference(BaselineConfiguredThresholds::class),
                new Reference(ChannelDeclarationRegistryInterface::class),
            ])
            ->addMethodCall('setRefusalPresenter', [new Reference(RefusalPresenter::class)])
            ->setPublic(true);
    }
}
