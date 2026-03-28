<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Qualimetrix\Baseline\BaselineGenerator;
use Qualimetrix\Baseline\BaselineWriter;
use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Core\Violation\Violation;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Handles baseline generation when requested via CLI option.
 */
final readonly class BaselinePresenter
{
    public function __construct(
        private BaselineGenerator $baselineGenerator,
        private BaselineWriter $baselineWriter,
        private ConfigurationProviderInterface $configurationProvider,
    ) {}

    /**
     * Generates baseline file if requested.
     *
     * Returns true when a baseline was successfully written, false when skipped.
     *
     * @param list<Violation> $violations
     */
    public function generateBaselineIfRequested(
        array $violations,
        InputInterface $input,
        OutputInterface $output,
    ): bool {
        $generateBaselinePath = $input->getOption('generate-baseline');
        if (!\is_string($generateBaselinePath) || $generateBaselinePath === '') {
            return false;
        }

        $baseline = $this->baselineGenerator->generate($violations);
        $projectRoot = $this->configurationProvider->getConfiguration()->projectRoot;
        $this->baselineWriter->write($baseline, $generateBaselinePath, $projectRoot);

        $output->writeln(\sprintf(
            '<info>Baseline with %d violations written to %s</info>',
            $baseline->count(),
            $generateBaselinePath,
        ));

        return true;
    }
}
