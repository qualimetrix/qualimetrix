<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Infrastructure\Logging\Contract\LogFileUnavailable;
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

        // Null when not written: the factory then lets verbosity choose the
        // console level. A default here would be indistinguishable from a
        // written `--log-level=info`, which must hold at every verbosity.
        $logLevel = self::level(CommandLineSpelling::option($input, 'log-level'));

        try {
            $logger = $this->loggerFactory->create($this->errorStream->writer($output), $logFile, $logLevel);
        } catch (LogFileUnavailable $unavailable) {
            throw ConfigurationRefusal::aboutCommandLineInput(
                '--log-file',
                \sprintf('Option --log-file names "%s", %s.', $unavailable->path, $unavailable->reason),
                $unavailable,
            );
        }
        $this->loggerHolder->setLogger($logger);

        return $logger;
    }

    /** Puts back the logger a container starts with, so a run whose configuration is refused early cannot keep the last run's. */
    public function reset(): void
    {
        $this->loggerHolder->reset();
    }

    private static function level(?string $given): ?string
    {
        if ($given === null) {
            return null;
        }

        // The refusal quotes what was typed, not its folded form: an answer
        // naming a value the user never wrote reads as a different miss.
        $level = strtolower($given);
        if (!\in_array($level, self::LEVELS, true)) {
            throw ConfigurationRefusal::aboutCommandLineInput(
                '--log-level',
                \sprintf(
                    'Invalid value "%s" for --log-level. Expected one of: %s.',
                    $given,
                    implode(', ', self::LEVELS),
                ),
            );
        }

        return $level;
    }
}
