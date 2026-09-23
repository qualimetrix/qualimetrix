<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Application;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * A profile export that cannot happen is refused before analysis, like an
 * unwritable `--output`.
 *
 * `--profile-format` was checked only after the whole analysis had run, and a
 * refused format or a failed write printed a line and kept the analysis exit
 * code — a run reported as complete with its requested artifact missing.
 */
#[CoversClass(CheckCommand::class)]
final class CheckCommandProfileExportTest extends TestCase
{
    private const string FIXTURE = 'tests/Infrastructure/Console/Fixtures/parses_with_no_findings.php';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/qmx-profile-export-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/target-dir', 0777, true);
        mkdir($this->directory . '/sealed', 0777, true);
    }

    protected function tearDown(): void
    {
        self::remove($this->directory);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function provideImpossibleExports(): iterable
    {
        yield 'unknown format' => [['--profile' => '{dir}/p.json', '--profile-format' => 'bogus'], '--profile-format'];
        yield 'unknown format without an export' => [['--profile-format' => 'bogus'], '--profile-format'];
        yield 'empty format' => [['--profile' => '{dir}/p.json', '--profile-format' => ''], '--profile-format'];
        yield 'missing directory' => [['--profile' => '{dir}/missing/p.json'], '--profile'];
        yield 'empty path' => [['--profile' => ''], '--profile'];
        yield 'a directory as the target' => [['--profile' => '{dir}/target-dir'], '--profile'];
    }

    /** @param array<string, string> $options */
    #[Test]
    #[DataProvider('provideImpossibleExports')]
    public function itRefusesAnImpossibleExportBeforeAnalysis(array $options, string $option): void
    {
        $tester = $this->runCheck($options);

        self::assertSame(3, $tester->getStatusCode(), $tester->getDisplay() . $tester->getErrorOutput());
        /** @var array{error: string, exit_code: int, position: mixed} $envelope */
        $envelope = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['error', 'exit_code', 'position'], array_keys($envelope), 'Analysis ran: a report precedes the refusal.');
        self::assertStringContainsString($option, $envelope['error']);
        self::assertSame([], array_values(array_filter(self::filesIn($this->directory), is_file(...))));
    }

    /**
     * The export is written beside its target and renamed over it, so an
     * existing, writable target file in a directory that cannot be written
     * cannot be exported to — and is refused before analysis, not after it.
     */
    #[Test]
    public function itRefusesAWritableTargetInADirectoryItCannotWriteBeforeAnalysis(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Directory permissions do not bind root.');
        }

        $sealed = $this->directory . '/sealed';
        touch($sealed . '/p.json');
        chmod($sealed, 0o555);

        try {
            $tester = $this->runCheck(['--profile' => '{dir}/sealed/p.json']);
        } finally {
            chmod($sealed, 0o755);
        }

        self::assertSame(3, $tester->getStatusCode(), $tester->getDisplay() . $tester->getErrorOutput());
        /** @var array{error: string, exit_code: int, position: mixed} $envelope */
        $envelope = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['error', 'exit_code', 'position'], array_keys($envelope), 'Analysis ran: a report precedes the refusal.');
        self::assertStringContainsString('--profile', $envelope['error']);
    }

    /**
     * A write that fails after the report is on stdout cannot turn stdout into
     * two documents: the report stays the only one, and the refusal is a line
     * on stderr with exit code 3.
     *
     * The failure is planted where no precheck can see it: the temporary file
     * the export writes first is taken by a directory of the same name.
     */
    #[Test]
    public function itKeepsTheReportTheOnlyStdoutDocumentWhenTheExportFailsAfterIt(): void
    {
        mkdir($this->directory . '/p.json.tmp.' . getmypid());

        $tester = $this->runCheck(['--profile' => '{dir}/p.json']);

        self::assertSame(3, $tester->getStatusCode(), $tester->getDisplay() . $tester->getErrorOutput());
        /** @var array<string, mixed> $report */
        $report = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('error', $report, 'The refusal was written to stdout as a second document.');
        self::assertArrayHasKey('summary', $report);
        self::assertStringContainsString('Configuration error:', $tester->getErrorOutput());
        self::assertStringContainsString('--profile', $tester->getErrorOutput());
        self::assertFileDoesNotExist($this->directory . '/p.json');
    }

    /** @return iterable<string, array{string}> */
    public static function provideFormats(): iterable
    {
        yield 'json' => ['json'];
        yield 'chrome-tracing' => ['chrome-tracing'];
    }

    /** The lawful neighbours: both accepted formats still export. */
    #[Test]
    #[DataProvider('provideFormats')]
    public function itExportsInAnAcceptedFormat(string $format): void
    {
        $tester = $this->runCheck(['--profile' => '{dir}/p.json', '--profile-format' => $format]);

        self::assertNotSame(3, $tester->getStatusCode(), $tester->getDisplay() . $tester->getErrorOutput());
        self::assertFileExists($this->directory . '/p.json');
        self::assertStringContainsString('Profile exported to', $tester->getErrorOutput());
    }

    /** @return list<string> */
    private static function filesIn(string $directory): array
    {
        $files = glob($directory . '/*');

        return $files === false ? [] : $files;
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (self::filesIn($path) as $child) {
                self::remove($child);
            }
            rmdir($path);

            return;
        }

        unlink($path);
    }

    /** @param array<string, string> $options */
    private function runCheck(array $options): CommandTester
    {
        $container = (new ContainerFactory())->create();
        /** @var CheckCommand $command */
        $command = $container->get(CheckCommand::class);
        /** @var RefusalPresenter $refusalPresenter */
        $refusalPresenter = $container->get(RefusalPresenter::class);
        $application = new Application(new ErrorStream(), $refusalPresenter);
        $application->addCommand($command);

        $input = ['paths' => [self::FIXTURE], '--format' => 'json', '--no-cache' => true, '--workers' => '0'];
        foreach ($options as $name => $value) {
            $input[$name] = str_replace('{dir}', $this->directory, $value);
        }

        $tester = new CommandTester($command);
        $tester->execute($input, ['capture_stderr_separately' => true]);

        return $tester;
    }
}
