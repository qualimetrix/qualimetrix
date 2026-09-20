<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Qualimetrix\Infrastructure\Console\RunningBinaryLocatorInterface;
use Qualimetrix\Infrastructure\Git\GitRepositoryLocatorInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What the three hook commands share: the dependencies they are built with
 * and the question they all start by asking.
 *
 * Each of them used to locate the repository, refuse when there is none, and
 * spell `hooks/pre-commit` for itself. The three copies of that had already
 * drifted — one checked that the hooks directory exists and two did not, and
 * the refusal read differently in each.
 */
abstract class AbstractHookCommand extends Command
{
    public function __construct(
        private readonly GitRepositoryLocatorInterface $gitRepositoryLocator,
        protected readonly RunningBinaryLocatorInterface $runningBinaryLocator,
    ) {
        parent::__construct();
    }

    /**
     * The repository's pre-commit hook, wherever git would look for it.
     *
     * @return string|null null when there is no such place, in which case the
     *                     reason has already been written to $output
     */
    final protected function hookPath(OutputInterface $output): ?string
    {
        $hooksDir = $this->gitRepositoryLocator->findHooksDir();

        if ($hooksDir === null) {
            $output->writeln('<error>Not a git repository</error>');
            $output->writeln('');
            $output->writeln('Initialize git first: git init');

            return null;
        }

        if (!is_dir($hooksDir->value())) {
            $output->writeln('<error>Git hooks directory not found: ' . $hooksDir->value() . '</error>');
            $output->writeln('');
            $output->writeln('This is where git looks, so create it or change core.hooksPath.');

            return null;
        }

        return $hooksDir->value() . '/pre-commit';
    }

    /**
     * Whether git would run something at this path.
     *
     * `file_exists` alone follows a symlink and answers false for a broken
     * one, and git runs a broken symlink all the same — it just fails.
     */
    final protected static function hookExists(string $hookPath): bool
    {
        return is_link($hookPath) || file_exists($hookPath);
    }
}
