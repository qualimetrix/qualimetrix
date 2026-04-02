<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Command;

use Qualimetrix\Infrastructure\Git\GitRepositoryLocator;
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
        private readonly GitRepositoryLocator $gitRepositoryLocator,
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
        // Find .git directory
        $gitDir = $this->gitRepositoryLocator->findGitDir();
        if ($gitDir === null) {
            $output->writeln('<error>Not a git repository. Initialize git first with: git init</error>');

            return self::FAILURE;
        }

        // Check if .git/hooks directory exists
        $hooksDir = $gitDir . '/hooks';
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

        // Check if hook already exists
        if (file_exists($hookPath)) {
            if (!$input->getOption('force')) {
                $output->writeln('<comment>Pre-commit hook already exists.</comment>');
                $output->writeln('Use --force to overwrite.');

                return self::FAILURE;
            }

            // Backup existing hook
            $backupPath = $hookPath . '.backup';
            if (copy($hookPath, $backupPath)) {
                $output->writeln(\sprintf('<info>Existing hook backed up to: %s</info>', $backupPath));
            } else {
                $output->writeln('<error>Failed to backup existing hook</error>');

                return self::FAILURE;
            }
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

        // Try to find scripts/pre-commit-hook.sh
        $possiblePaths = [
            $currentDir . '/scripts/pre-commit-hook.sh',
            __DIR__ . '/../../../../scripts/pre-commit-hook.sh',
        ];

        foreach ($possiblePaths as $path) {
            $realPath = realpath($path);
            if ($realPath !== false && file_exists($realPath)) {
                return $realPath;
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
