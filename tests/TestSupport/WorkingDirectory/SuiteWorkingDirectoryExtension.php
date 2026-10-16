<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\TestSupport\WorkingDirectory;

use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Event\TestRunner\ExecutionStarted;
use PHPUnit\Event\TestRunner\ExecutionStartedSubscriber;
use PHPUnit\Event\TestSuite\Finished;
use PHPUnit\Event\TestSuite\FinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use RuntimeException;

final class SuiteWorkingDirectoryExtension implements Extension
{
    private static bool $bootstrapped = false;
    private static string $failure = '';
    private string $launchDirectory = '';
    private string $window = '';

    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        self::$bootstrapped = true;
        $facade->registerSubscribers(
            new class ($this) implements ExecutionStartedSubscriber {
                public function __construct(private readonly SuiteWorkingDirectoryExtension $extension) {}
                public function notify(ExecutionStarted $event): void
                {
                    $this->extension->start();
                }
            },
            new class ($this) implements FinishedSubscriber {
                public function __construct(private readonly SuiteWorkingDirectoryExtension $extension) {}
                public function notify(Finished $event): void
                {
                    $this->extension->finishClass($event);
                }
            },
            new class ($this) implements ExecutionFinishedSubscriber {
                public function __construct(private readonly SuiteWorkingDirectoryExtension $extension) {}
                public function notify(ExecutionFinished $event): void
                {
                    $this->extension->finish();
                }
            },
        );
    }

    public function start(): void
    {
        $directory = getcwd();
        if ($directory === false) {
            $this->refuse('Cannot read PHPUnit launch directory');
        }
        $this->launchDirectory = $directory;
        $processRoot = \constant('QMX_TEST_PROCESS_ROOT');
        if (!\is_string($processRoot)) {
            $this->refuse('Cannot read PHPUnit process root');
        }
        $this->window = $processRoot . '/cwd';
        if (!mkdir($this->window, 0o700) || !chdir($this->window)) {
            $this->refuse('Cannot enter PHPUnit execution window: ' . $this->window);
        }
    }

    public function finishClass(Finished $event): void
    {
        if (!$event->testSuite()->isForTestClass() || getcwd() === $this->window) {
            return;
        }
        $actual = getcwd();
        chdir($this->window);
        $this->refuse('Class ' . $event->testSuite()->name() . ' leaked cwd: ' . ($actual === false ? 'unreadable' : $actual));
    }

    public function finish(): void
    {
        try {
            $entries = self::entries($this->window);
            $declared = self::declaredResidue();
            $unknown = array_diff($entries, $declared);
            if ($unknown !== []) {
                $this->refuse('Suite cwd not empty: ' . $this->window . ': ' . implode(', ', $unknown));
            }
            $report = getenv('QMX_SUITE_RESIDUE_REPORT');
            if ($report !== false) {
                $observed = array_intersect($entries, $declared);
                if (file_put_contents($report, implode("\n", $observed) . ($observed === [] ? '' : "\n")) === false) {
                    $this->refuse('Cannot write suite residue report: ' . $report);
                }
            }
        } catch (RuntimeException $error) {
            self::$failure = $error->getMessage();
            throw $error;
        } finally {
            if (!chdir($this->launchDirectory)) {
                self::$failure = 'Cannot restore PHPUnit launch directory: ' . $this->launchDirectory;
                throw new RuntimeException(self::$failure);
            }
        }
    }

    public static function finishProcess(string $processRoot): void
    {
        if (!self::$bootstrapped) {
            self::removeDirectory($processRoot);
            fwrite(\STDERR, "Suite extension was not bootstrapped\n");
            exit(1);
        }
        if (self::$failure !== '') {
            fwrite(\STDERR, self::$failure . "\n");
            fwrite(\STDERR, 'Preserved PHPUnit process root: ' . $processRoot . "\n");
            exit(1);
        }
        self::removeDirectory($processRoot);
    }

    /** @return list<string> */
    private static function declaredResidue(): array
    {
        $lines = file(__DIR__ . '/declared-residue.tsv', \FILE_IGNORE_NEW_LINES);
        if ($lines === false || array_shift($lines) !== "entry\treason") {
            throw new RuntimeException('Cannot read declared suite residue');
        }
        $entries = [];
        foreach ($lines as $line) {
            $fields = explode("\t", $line);
            if (\count($fields) !== 2 || trim($fields[1]) === '' || $fields[0] === ''
                || str_contains($fields[0], '/') || str_contains($fields[0], '\\')
                || \in_array($fields[0], ['.', '..'], true) || \in_array($fields[0], $entries, true)) {
                throw new RuntimeException('Invalid declared suite residue: ' . $line);
            }
            $entries[] = $fields[0];
        }
        return $entries;
    }

    /** @return list<string> */
    private static function entries(string $directory): array
    {
        $entries = scandir($directory);
        if ($entries === false) {
            throw new RuntimeException('Cannot read suite cwd: ' . $directory);
        }
        return array_values(array_diff($entries, ['.', '..']));
    }

    private function refuse(string $message): never
    {
        self::$failure = $message;
        throw new RuntimeException($message);
    }

    private static function removeDirectory(string $directory): void
    {
        foreach (self::entries($directory) as $entry) {
            $path = $directory . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                self::removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
