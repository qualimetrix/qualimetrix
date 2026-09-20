<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Phar;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Infrastructure\Git\GitRepositoryLocatorInterface;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'hook:install',
    description: 'Install git pre-commit hook for Qualimetrix',
)]
final class HookInstallCommand extends Command
{
    public function __construct(
        private readonly GitRepositoryLocatorInterface $gitRepositoryLocator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'force',
            'f',
            InputOption::VALUE_NONE,
            'Overwrite existing hook',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // The hook is installed as a symlink to a shell script shipped beside
        // the sources, and neither half survives a phar: nothing can symlink
        // into an archive, and building the script's path reaches
        // AbsolutePath with a "phar://" prefix it rejects — which surfaced as
        // an invariant message naming a path the reader never wrote.
        if (Phar::running(false) !== '') {
            $output->writeln('<error>hook:install is not available from the phar.</error>');
            $output->writeln('The hook is a symlink to a shell script, which cannot point inside an archive.');
            $output->writeln('Install Qualimetrix with Composer to use it, or write .git/hooks/pre-commit by hand.');

            return self::FAILURE;
        }

        // Find .git directory
        $gitDir = $this->gitRepositoryLocator->findGitDir();
        if ($gitDir === null) {
            $output->writeln('<error>Not a git repository. Initialize git first with: git init</error>');

            return self::FAILURE;
        }

        // Check if .git/hooks directory exists
        $hooksDir = $gitDir->joinRelative(RelativePath::fromString('hooks'))->value();
        if (!is_dir($hooksDir)) {
            $output->writeln('<error>Git hooks directory not found: ' . $hooksDir . '</error>');

            return self::FAILURE;
        }

        $hookPath = $hooksDir . '/pre-commit';
        $scriptPath = $this->getScriptPath();

        if ($scriptPath === null) {
            $output->writeln('<error>Hook script not found: scripts/pre-commit-hook.sh</error>');

            return self::FAILURE;
        }

        $refusal = $this->clearExistingHook($input, $output, $hookPath);

        if ($refusal !== null) {
            return $refusal;
        }

        // Install hook using symlink (default behavior)
        // Remove existing file/symlink first
        if (file_exists($hookPath)) {
            unlink($hookPath);
        }

        // Create symlink
        $relativeScriptPath = $this->getRelativePath($hooksDir, $scriptPath);
        if (!symlink($relativeScriptPath, $hookPath)) {
            $output->writeln('<error>Failed to create symlink</error>');

            return self::FAILURE;
        }

        $output->writeln('<info>✓ Pre-commit hook installed (symlink)</info>');

        // Make hook executable
        if (!chmod($hookPath, 0755)) {
            $output->writeln('<error>Failed to make hook executable</error>');

            return self::FAILURE;
        }

        $output->writeln(\sprintf('Hook path: %s', $hookPath));
        $output->writeln('');
        $output->writeln('The hook will run Qualimetrix on staged PHP files before each commit.');
        $output->writeln('To bypass the hook, use: git commit --no-verify');

        return self::SUCCESS;
    }

    /**
     * Makes room for a new hook, or refuses.
     *
     * @return int|null a command exit code to return, or null to carry on
     */
    private function clearExistingHook(InputInterface $input, OutputInterface $output, string $hookPath): ?int
    {
        if (!file_exists($hookPath)) {
            return null;
        }

        if ($input->getOption('force') !== true) {
            $output->writeln('<comment>Pre-commit hook already exists.</comment>');
            $output->writeln('Use --force to overwrite.');

            return self::FAILURE;
        }

        $backupPath = $hookPath . '.backup';

        if (!copy($hookPath, $backupPath)) {
            $output->writeln('<error>Failed to backup existing hook</error>');

            return self::FAILURE;
        }

        $output->writeln(\sprintf('<info>Existing hook backed up to: %s</info>', $backupPath));

        return null;
    }

    /**
     * Get absolute path to hook script.
     *
     * @return string|null Absolute path or null if not found
     */
    private function getScriptPath(): ?string
    {
        $currentDir = getcwd();
        if ($currentDir === false) {
            return null;
        }
        $cwd = AbsolutePath::fromString($currentDir);

        $possiblePaths = [
            PathFactory::fromCliArgument('scripts/pre-commit-hook.sh', $cwd),
            AbsolutePath::fromString(__DIR__ . '/../../../../scripts/pre-commit-hook.sh'),
        ];

        foreach ($possiblePaths as $path) {
            if (!$path->exists()) {
                continue;
            }
            try {
                return $path->canonicalize()->value();
            } catch (RuntimeException) {
                continue;
            }
        }

        return null;
    }

    /**
     * Calculate relative path from one directory to another.
     *
     * @param string $from Source directory
     * @param string $to Target file/directory
     *
     * @return string Relative path
     */
    private function getRelativePath(string $from, string $to): string
    {
        $from = str_replace('\\', '/', $from);
        $to = str_replace('\\', '/', $to);

        $fromParts = explode('/', $from);
        $toParts = explode('/', $to);

        // Find common base
        $common = 0;
        $max = min(\count($fromParts), \count($toParts));
        for ($i = 0; $i < $max; ++$i) {
            if ($fromParts[$i] !== $toParts[$i]) {
                break;
            }
            ++$common;
        }

        // Build relative path
        $relativePath = str_repeat('../', \count($fromParts) - $common);
        $relativePath .= implode('/', \array_slice($toParts, $common));

        return $relativePath;
    }
}
