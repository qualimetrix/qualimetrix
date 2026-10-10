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
use Qualimetrix\Subprocess\ChildProcess;
use Symfony\Component\Console\Tester\CommandTester;

require_once \dirname(__DIR__, 5) . '/scripts/subprocess/ChildProcess.php';

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
        yield 'a name ending in a slash' => [['--profile' => '{dir}/nodir/'], '--profile'];
        yield 'a symbolic link loop' => [['--profile' => '{dir}/loop.json'], '--profile'];
    }

    /** @param array<string, string> $options */
    #[Test]
    #[DataProvider('provideImpossibleExports')]
    public function itRefusesAnImpossibleExportBeforeAnalysis(array $options, string $option): void
    {
        if (($options['--profile'] ?? null) === '{dir}/loop.json') {
            symlink('loop.json', $this->directory . '/loop.json');
        }
        $tester = $this->runCheck($options);

        self::assertSame(3, $tester->getStatusCode(), $tester->getDisplay() . $tester->getErrorOutput());
        /** @var array{error: string, exit_code: int, position: mixed, source: mixed} $envelope */
        $envelope = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['error', 'exit_code', 'position', 'source'], array_keys($envelope), 'Analysis ran: a report precedes the refusal.');
        self::assertStringContainsString($option, $envelope['error']);
        self::assertSame([], array_values(array_filter(self::filesIn($this->directory), is_file(...))));
    }

    #[Test]
    public function itRefusesAProfileWhoseParentCannotHoldAReplacementSibling(): void
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
        self::assertStringContainsString('cannot create a temporary file', $tester->getDisplay());
        self::assertSame('', file_get_contents($sealed . '/p.json'));
        self::assertSame(['p.json'], array_values(array_diff((array) scandir($sealed), ['.', '..'])));
    }

    /** A native profile write can fail after the report; stdout remains one JSON document. */
    #[Test]
    public function itKeepsTheReportTheOnlyStdoutDocumentWhenTheExportFailsAfterIt(): void
    {
        $target = $this->directory . '/p.json';
        $hits = $this->directory . '/profile-write-hits.txt';
        file_put_contents($target, 'old profile');
        file_put_contents($this->directory . '/Source.php', '<?php namespace Demo; final class Source {}');
        $hook = $this->directory . '/profile-write-hook.php';
        $source = <<<'PHP'
            <?php
            namespace {
                $GLOBALS['qmx_profile_target'] = __TARGET__;
                $GLOBALS['qmx_profile_hits'] = 0;
                register_shutdown_function(static function (): void {
                    \file_put_contents(__HITS__, (string) $GLOBALS['qmx_profile_hits']);
                });
                function qmxProfileWrite($stream, string $bytes): int|false
                {
                    $uri = \stream_get_meta_data($stream)['uri'] ?? '';
                    if (\realpath(\dirname($uri)) !== \realpath(\dirname($GLOBALS['qmx_profile_target'])) || !\str_starts_with(\basename($uri), '.qmx-')) {
                        return \fwrite($stream, $bytes);
                    }
                    ++$GLOBALS['qmx_profile_hits'];
                    return 0;
                }
            }
            namespace Qualimetrix\Infrastructure\Console {
                function fwrite($stream, string $bytes): int|false
                {
                    return \qmxProfileWrite($stream, $bytes);
                }
            }
            namespace Qualimetrix\Core\FileTarget {
                function fwrite($stream, string $bytes): int|false
                {
                    return \qmxProfileWrite($stream, $bytes);
                }
            }
            PHP;
        file_put_contents($hook, str_replace(
            ['__TARGET__', '__HITS__'],
            [var_export($target, true), var_export($hits, true)],
            $source,
        ));
        $run = ChildProcess::run([
            \PHP_BINARY,
            '-d',
            'auto_prepend_file=' . $hook,
            \dirname(__DIR__, 5) . '/bin/qmx',
            'check',
            'Source.php',
            '--format=json',
            '--workers=0',
            '--no-cache',
            '--profile=' . $target,
        ], $this->directory);

        self::assertSame(3, $run['exitCode'], $run['stdout'] . $run['stderr']);
        self::assertGreaterThan(0, (int) file_get_contents($hits), 'The native profile write hook was not reached.');
        /** @var array<string, mixed> $report */
        $report = json_decode($run['stdout'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('error', $report, 'The refusal was written to stdout as a second document.');
        self::assertArrayHasKey('summary', $report);
        self::assertStringContainsString('Environment error:', $run['stderr']);
        self::assertStringContainsString('--profile', $run['stderr']);
        self::assertSame('old profile', file_get_contents($target));
        $entries = scandir($this->directory);
        self::assertIsArray($entries);
        self::assertSame([], array_values(array_filter($entries, static fn(string $name): bool => str_starts_with($name, '.qmx-'))));
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
        $application = new Application(new ErrorStream(), $refusalPresenter, new \Qualimetrix\Infrastructure\Composer\ComposerManifestReader());
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
