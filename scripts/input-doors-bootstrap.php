<?php

declare(strict_types=1);

/**
 * Shared boot of the product's console application for the input-door stand.
 *
 * The command map is the one `bin/qmx` installs; a command absent from it is
 * invisible to the grid, which is the named blind spot of the reflection
 * method (`01-oracle.md` §3, case "д").
 */

namespace Qualimetrix\InputDoors;

use Qualimetrix\Infrastructure\Console\Application;
use Qualimetrix\Infrastructure\Console\Command\BaselineCleanupCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineExplainCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineGenerateCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineRenameChannelsCommand;
use Qualimetrix\Infrastructure\Console\Command\BaselineUpdateCommand;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\Command\Debug\LayerAssignmentCommand;
use Qualimetrix\Infrastructure\Console\Command\DirectivesCommand;
use Qualimetrix\Infrastructure\Console\Command\GraphExportCommand;
use Qualimetrix\Infrastructure\Console\Command\HookInstallCommand;
use Qualimetrix\Infrastructure\Console\Command\HookStatusCommand;
use Qualimetrix\Infrastructure\Console\Command\HookUninstallCommand;
use Qualimetrix\Infrastructure\Console\Command\RulesCommand;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use RuntimeException;
use Symfony\Component\Console\CommandLoader\ContainerCommandLoader;

/** @return array<string, class-string> */
function commandMap(): array
{
    return [
        'check' => CheckCommand::class,
        'baseline:generate' => BaselineGenerateCommand::class,
        'baseline:update' => BaselineUpdateCommand::class,
        'baseline:cleanup' => BaselineCleanupCommand::class,
        'baseline:explain' => BaselineExplainCommand::class,
        'baseline:rename-channels' => BaselineRenameChannelsCommand::class,
        'debug:layer-assignment' => LayerAssignmentCommand::class,
        'directives' => DirectivesCommand::class,
        'graph:export' => GraphExportCommand::class,
        'hook:install' => HookInstallCommand::class,
        'hook:uninstall' => HookUninstallCommand::class,
        'hook:status' => HookStatusCommand::class,
        'rules' => RulesCommand::class,
    ];
}

function application(): Application
{
    $container = (new ContainerFactory())->create();
    $errorStream = $container->get(ErrorStream::class);
    $refusalPresenter = $container->get(RefusalPresenter::class);

    if (!$errorStream instanceof ErrorStream || !$refusalPresenter instanceof RefusalPresenter) {
        throw new RuntimeException('the container did not yield the console collaborators bin/qmx wires');
    }

    $application = new Application($errorStream, $refusalPresenter);
    $application->setCommandLoader(new ContainerCommandLoader($container, commandMap()));

    return $application;
}
