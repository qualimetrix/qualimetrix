<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Application;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Qualimetrix\Infrastructure\Console\RuntimeLoggerConfigurator;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\CommandLoader\ContainerCommandLoader;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * `--log-file=` as an unset variable writes it, through the real command.
 *
 * It ran to a full report without a log file (exit 2), and a blank value
 * created a file named by the blank; the refusal has to reach exit 3 before
 * analysis, which a test of the logger factory alone cannot show.
 */
#[CoversClass(RuntimeLoggerConfigurator::class)]
final class LogFileBlankValueRefusalTest extends TestCase
{
    private string $fixture = '';

    private string $workingDirectory = '';

    protected function setUp(): void
    {
        $workingDirectory = getcwd();
        self::assertNotFalse($workingDirectory);
        $this->workingDirectory = $workingDirectory;

        $this->fixture = sys_get_temp_dir() . '/qmx-log-file-blank-' . bin2hex(random_bytes(6));
        mkdir($this->fixture . '/src', 0o755, true);
        file_put_contents($this->fixture . '/src/A.php', "<?php\nnamespace App;\nfinal class A {}\n");
        file_put_contents($this->fixture . '/composer.json', '{"autoload":{"psr-4":{"App\\\\":"src/"}}}');
        chdir($this->fixture);
    }

    protected function tearDown(): void
    {
        chdir($this->workingDirectory);
        foreach ((array) scandir($this->fixture . '/src') as $entry) {
            if (\is_string($entry) && is_file($this->fixture . '/src/' . $entry)) {
                unlink($this->fixture . '/src/' . $entry);
            }
        }
        rmdir($this->fixture . '/src');
        foreach ((array) scandir($this->fixture) as $entry) {
            if (\is_string($entry) && is_file($this->fixture . '/' . $entry)) {
                unlink($this->fixture . '/' . $entry);
            }
        }
        rmdir($this->fixture);
    }

    /** @return iterable<string, array{string}> */
    public static function provideBlankValues(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => [' '];
    }

    #[Test]
    #[DataProvider('provideBlankValues')]
    public function itRefusesABlankLogFileBeforeAnalysis(string $value): void
    {
        $tester = $this->check(['--log-file' => $value]);

        self::assertSame(3, $tester->getStatusCode(), $tester->getErrorOutput() . $tester->getDisplay());
        self::assertStringContainsString(
            \sprintf('Option --log-file names "%s", which is not a file name', $value),
            $tester->getErrorOutput(),
        );
        self::assertSame(['.', '..', 'composer.json', 'src'], scandir($this->fixture), 'nothing may be created for a blank path');
    }

    /** The legitimate neighbour: a named log file is written and the run is not refused. */
    #[Test]
    public function itStillWritesANamedLogFile(): void
    {
        $tester = $this->check(['--log-file' => 'qmx.log', '--format' => 'json']);

        self::assertNotContains($tester->getStatusCode(), [1, 3], $tester->getErrorOutput());
        self::assertFileExists($this->fixture . '/qmx.log');
    }

    /** @param array<string, mixed> $options */
    private function check(array $options): ApplicationTester
    {
        $container = (new ContainerFactory())->create();
        /** @var ErrorStream $errorStream */
        $errorStream = $container->get(ErrorStream::class);
        /** @var RefusalPresenter $refusalPresenter */
        $refusalPresenter = $container->get(RefusalPresenter::class);
        $application = new Application($errorStream, $refusalPresenter);
        $application->setAutoExit(false);
        $application->setCommandLoader(new ContainerCommandLoader($container, ['check' => CheckCommand::class]));

        $tester = new ApplicationTester($application);
        $tester->run(
            ['command' => 'check', 'paths' => ['src'], '--no-cache' => true, '--workers' => '0', ...$options],
            ['capture_stderr_separately' => true],
        );

        return $tester;
    }
}
