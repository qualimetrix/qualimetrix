<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Application;

use Closure;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\RefusalInterface;
use Qualimetrix\Analysis\ProjectManifest\Contract\ManifestSnapshotControlInterface;
use Qualimetrix\Core\Environment\EnvironmentFailureInterface;
use Qualimetrix\Infrastructure\Console\CacheLifecycle\CacheRunLifecycle;
use Qualimetrix\Infrastructure\Console\Check\CheckCommand;
use Qualimetrix\Infrastructure\Console\Command\Baseline\ChannelRename\BaselineRenameChannelsCommand;
use Qualimetrix\Infrastructure\Console\Command\Baseline\Explanation\BaselineExplainCommand;
use Qualimetrix\Infrastructure\Console\Command\Baseline\Generation\BaselineGenerateCommand;
use Qualimetrix\Infrastructure\Console\Command\Baseline\Maintenance\BaselineCleanupCommand;
use Qualimetrix\Infrastructure\Console\Command\Baseline\Maintenance\BaselineUpdateCommand;
use Qualimetrix\Infrastructure\Console\Command\Debug\LayerAssignmentCommand;
use Qualimetrix\Infrastructure\Console\Directives\DirectivesCommand;
use Qualimetrix\Infrastructure\Console\GraphExport\GraphExportCommand;
use Qualimetrix\Infrastructure\Console\Hook\HookInstallCommand;
use Qualimetrix\Infrastructure\Console\Hook\HookStatusCommand;
use Qualimetrix\Infrastructure\Console\Hook\HookUninstallCommand;
use Qualimetrix\Infrastructure\Console\RuleListing\RulesCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\ExceptionInterface as DependencyInjectionException;
use Throwable;

final class ProductCommandLoader implements CommandLoaderInterface
{
    /** @var array<string, class-string<Command>> */
    public const array NAMES = [
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

    private ?ContainerInterface $container = null;

    /** @param Closure(): ContainerInterface $containerFor */
    public function __construct(private readonly Closure $containerFor) {}

    public function has(string $name): bool
    {
        return isset(self::NAMES[$name]);
    }

    public function getNames(): array
    {
        return array_keys(self::NAMES);
    }

    /** @throws ContainerBuildFailure */
    public function get(string $name): Command
    {
        if (!$this->has($name)) {
            throw new CommandNotFoundException(\sprintf('Command "%s" does not exist.', $name));
        }

        try {
            $command = $this->container()->get(self::NAMES[$name]);
        } catch (DependencyInjectionException $failure) {
            if ($failure instanceof RefusalInterface || $failure instanceof EnvironmentFailureInterface) {
                throw $failure;
            }
            throw new ContainerBuildFailure($failure->getMessage(), 0, $failure);
        }

        if (!$command instanceof Command) {
            throw new ContainerBuildFailure(\sprintf('Service for command "%s" is not a command.', $name));
        }

        return $command;
    }

    public function manifestSnapshot(): ManifestSnapshotControlInterface
    {
        $snapshot = $this->container()->get(ManifestSnapshotControlInterface::class);
        if (!$snapshot instanceof ManifestSnapshotControlInterface) {
            throw new ContainerBuildFailure('The product container has no manifest snapshot control.');
        }

        return $snapshot;
    }

    public function finishInvocation(): void
    {
        if ($this->container === null) {
            return;
        }

        $runtime = $this->container->get(CacheRunLifecycle::class);
        if (!$runtime instanceof CacheRunLifecycle) {
            throw new ContainerBuildFailure('The product container has no cache lifecycle.');
        }
        $runtime->finishRun();
    }

    private function container(): ContainerInterface
    {
        if ($this->container !== null) {
            return $this->container;
        }

        try {
            return $this->container = ($this->containerFor)();
        } catch (Throwable $failure) {
            throw new ContainerBuildFailure($failure->getMessage(), 0, $failure);
        }
    }
}
