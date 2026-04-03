<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use InvalidArgumentException;
use Qualimetrix\Core\Version;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Qualimetrix CLI application.
 *
 * Supports `--working-dir` / `-d` global option to change the effective
 * working directory before any command runs (same pattern as Composer).
 */
final class Application extends BaseApplication
{
    public const string NAME = 'Qualimetrix';

    public function __construct()
    {
        parent::__construct(self::NAME, Version::get());
    }

    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        $workingDir = $input->getParameterOption(['--working-dir', '-d']);

        if (\is_string($workingDir) && $workingDir !== '') {
            $resolved = realpath($workingDir);

            if ($resolved === false || !is_dir($resolved)) {
                throw new InvalidArgumentException(\sprintf(
                    'Invalid working directory: %s',
                    $workingDir,
                ));
            }

            if (!chdir($resolved)) {
                throw new InvalidArgumentException(\sprintf(
                    'Failed to change working directory to: %s',
                    $resolved,
                ));
            }
        }

        return parent::doRun($input, $output);
    }

    protected function getDefaultInputDefinition(): InputDefinition
    {
        $definition = parent::getDefaultInputDefinition();

        $definition->addOption(new InputOption(
            'working-dir',
            'd',
            InputOption::VALUE_REQUIRED,
            'Use the given directory as working directory',
        ));

        return $definition;
    }
}
