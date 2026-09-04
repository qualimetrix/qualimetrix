<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Qualimetrix\Infrastructure\Logging\Contract\LoggerFactoryInterface;
use Qualimetrix\Infrastructure\Logging\LoggerHolder;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Creates and publishes the logger for one console run. */
final readonly class RuntimeLoggerConfigurator
{
    public function __construct(
        private LoggerFactoryInterface $loggerFactory,
        private LoggerHolder $loggerHolder,
        private ErrorStream $errorStream = new ErrorStream(),
    ) {}

    public function configure(InputInterface $input, OutputInterface $output): LoggerInterface
    {
        $logFile = $input->hasOption('log-file') ? $input->getOption('log-file') : null;
        $logLevel = $input->hasOption('log-level') ? $input->getOption('log-level') : null;

        if (!\is_string($logFile) && $logFile !== null) {
            $logFile = null;
        }

        if (!\is_string($logLevel)) {
            $logLevel = LogLevel::INFO;
        }

        $logLevel = strtolower($logLevel);
        if (!\in_array($logLevel, ['debug', 'info', 'warning', 'error'], true)) {
            $logLevel = LogLevel::INFO;
        }

        $logger = $this->loggerFactory->create($this->errorStream->writer($output), $logFile, $logLevel);
        $this->loggerHolder->setLogger($logger);

        return $logger;
    }
}
