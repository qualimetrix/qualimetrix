<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\RuntimePolicy;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitectureConfigurationWarning;
use Qualimetrix\Infrastructure\Cache\CacheFactory;
use Qualimetrix\Infrastructure\Console\Progress\ProgressConfigurator;
use Qualimetrix\Infrastructure\Profiler\Contract\ProfileSessionControlInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** @qmx-threshold coupling.instability 0.833335 -- This subject implements an owner-declared outward protocol with intentionally few incoming readers; the finite boundary preserves visibility of additional dependencies. */
final readonly class RuntimeEffects
{
    public function __construct(
        private RuntimeLoggerConfigurator $runtimeLoggerConfigurator,
        private ProgressConfigurator $progressConfigurator,
        private ProfileSessionControlInterface $profileSession,
        private RuntimeLimitsController $runtimeLimitsController,
        private CacheFactory $cacheFactory,
    ) {}

    public function reset(): void
    {
        $this->profileSession->disable();
        $this->progressConfigurator->reset();
        $this->runtimeLimitsController->reset();
        $this->runtimeLoggerConfigurator->reset();
    }

    /** @param list<ArchitectureConfigurationWarning> $warnings */
    public function apply(RuntimeLimits $limits, array $warnings, InputInterface $input, OutputInterface $output): void
    {
        $this->runtimeLimitsController->apply($limits);
        $logger = $this->runtimeLoggerConfigurator->configure($input, $output);
        $this->cacheFactory->configureLogger($logger);
        foreach ($warnings as $warning) {
            $logger->warning($warning->message, $warning->context);
        }
        $this->progressConfigurator->configure($input, $output);
        $this->configureProfiler($input);
    }

    public function prepare(ConfigurationDocument $document): RuntimeLimits
    {
        return RuntimeLimits::fromResolvedValue($document->resolved()->get(ConfigSchema::MEMORY_LIMIT));
    }

    private function configureProfiler(InputInterface $input): void
    {
        if (!$input->hasOption('profile')) {
            return;
        }

        $profileOption = $input->getOption('profile');
        if ($profileOption === false) {
            return;
        }

        // Enable profiler if --profile or --profile=file was provided
        $this->profileSession->enable();
    }
}
