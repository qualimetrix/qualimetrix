<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Infrastructure\Logging\Contract\LoggerFactoryInterface;
use Qualimetrix\Infrastructure\Logging\LoggerHolder;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Creates and publishes the logger for one console run. */
final readonly class RuntimeLoggerConfigurator
{
    /** The levels the logger factory can build; anything else is refused, not substituted. */
    private const array LEVELS = [LogLevel::DEBUG, LogLevel::INFO, LogLevel::WARNING, LogLevel::ERROR];

    public function __construct(
        private LoggerFactoryInterface $loggerFactory,
        private LoggerHolder $loggerHolder,
        private ErrorStream $errorStream,
    ) {}

    public function configure(InputInterface $input, OutputInterface $output): LoggerInterface
    {
        $logFile = CommandLineSpelling::option($input, 'log-file');
        $logLevel = CommandLineSpelling::option($input, 'log-level') ?? LogLevel::INFO;

        // The refusal quotes what was typed, not its folded form: an answer
        // naming a value the user never wrote reads as a different miss.
        $given = $logLevel;
        $logLevel = strtolower($logLevel);
        if (!\in_array($logLevel, self::LEVELS, true)) {
            throw ConfigurationRefusal::aboutCommandLineInput(
                '--log-level',
                \sprintf(
                    'Invalid value "%s" for --log-level. Expected one of: %s.',
                    $given,
                    implode(', ', self::LEVELS),
                ),
            );
        }

        $logger = $this->loggerFactory->create($this->errorStream->writer($output), $logFile, $logLevel);
        $this->loggerHolder->setLogger($logger);

        return $logger;
    }
}
