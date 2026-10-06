<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Qualimetrix\Infrastructure\Console\Hook\PreCommitHook;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'hook:install',
    description: 'Install git pre-commit hook for Qualimetrix',
)]
final class HookInstallCommand extends AbstractHookCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this->addOption(
            'force',
            'f',
            InputOption::VALUE_NONE,
            'Overwrite existing hook',
        );
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $hookPath = $this->hookPath();

        $binary = $this->runningBinaryLocator->path();
        if ($binary === null) {
            throw $this->refusal(
                'Could not determine the path of the running qmx binary. The hook has to name it, so nothing was written.',
            );
        }

        $this->clearExistingHook($input, $output, $hookPath);

        $this->files->write($hookPath, PreCommitHook::script($binary), $output);

        $output->writeln('<info>✓ Pre-commit hook installed</info>');
        $output->writeln(\sprintf('Hook path: %s', $hookPath));
        $output->writeln(\sprintf('Runs: %s', $binary));
        $output->writeln('');
        $output->writeln('The hook will run Qualimetrix on staged PHP files before each commit.');
        $output->writeln('To bypass the hook, use: git commit --no-verify');

        return self::SUCCESS;
    }

    /** Makes room for a new hook, or refuses. */
    private function clearExistingHook(InputInterface $input, OutputInterface $output, string $hookPath): void
    {
        if (!$this->files->exists($hookPath)) {
            return;
        }
        if ($input->getOption('force') !== true) {
            throw $this->refusal(\sprintf(
                'Pre-commit hook already exists: %s. Use --force to overwrite it; a hook that is not a Qualimetrix hook is backed up first.',
                $hookPath,
            ));
        }

        $backupPath = $hookPath . '.backup';
        switch ($this->files->backUp($hookPath, $output)) {
            case 'broken':
                $output->writeln('<comment>Existing hook is a broken symlink; nothing to back up.</comment>');
                break;
            case 'ours':
                $output->writeln('<comment>Replacing a Qualimetrix hook; the existing backup is left alone.</comment>');
                break;
            case 'already':
                $output->writeln(\sprintf('<comment>The backup %s already holds this hook; it is left as it is.</comment>', $backupPath));
                break;
            case 'occupied':
                throw $this->refusal(\sprintf(
                    'The backup %s already holds a different hook, and it is the only copy hook:uninstall --restore-backup can restore. '
                    . 'Move it away, then run hook:install --force again.',
                    $backupPath,
                ));
            case 'created':
                $output->writeln(\sprintf('<info>Existing hook backed up to: %s</info>', $backupPath));
                break;
        }

        $this->files->removeLink($hookPath);
    }
}
