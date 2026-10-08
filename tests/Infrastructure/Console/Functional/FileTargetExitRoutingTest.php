<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional;

use Exception;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisPipelineInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\DependencyGraphAnalyzerInterface;
use Qualimetrix\Analysis\Run\Contract\Pipeline\DirectiveAuditInterface;
use Qualimetrix\Core\Environment\EnvironmentFailureInterface;
use Qualimetrix\Core\FileTarget\FileTargetFailure;
use Qualimetrix\Core\FileTarget\FileTargetFailureKind;
use Qualimetrix\Infrastructure\Console\AnalysisPreflight;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\Console\Command\DirectivesCommand;
use Qualimetrix\Infrastructure\Console\Command\GraphExportCommand;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\ProfilePresenter;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Qualimetrix\Infrastructure\Console\ResultPresenter;
use Qualimetrix\Infrastructure\Console\RunTarget\RunTargetSession;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Profiler\Contract\ProfileReportInterface;
use Qualimetrix\Reporting\GraphProjection\DependencyGraphProjector;
use Qualimetrix\Subprocess\ChildProcess;
use ReflectionClass;
use ReflectionParameter;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;

require_once \dirname(__DIR__, 4) . '/scripts/subprocess/ChildProcess.php';

#[CoversClass(CheckCommand::class)]
#[CoversClass(GraphExportCommand::class)]
#[CoversClass(DirectivesCommand::class)]
final class FileTargetExitRoutingTest extends TestCase
{
    private string $directory;
    private string $originalCwd;

    protected function setUp(): void
    {
        $cwd = getcwd();
        self::assertNotFalse($cwd);
        $this->originalCwd = $cwd;
        $this->directory = sys_get_temp_dir() . '/qmx-target-exit-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/cache', 0o755, true);
        file_put_contents($this->directory . '/Source.php', '<?php namespace Demo; final class Source {}');
        chdir($this->directory);
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        self::remove($this->directory);
    }

    /** @return iterable<string, array{class-string<CheckCommand|GraphExportCommand>, string}> */
    public static function provideExposedTargets(): iterable
    {
        yield 'check output' => [CheckCommand::class, '--output'];
        yield 'check profile' => [CheckCommand::class, '--profile'];
        yield 'check log' => [CheckCommand::class, '--log-file'];
        yield 'graph output' => [GraphExportCommand::class, '--output'];
    }

    /** @param class-string<CheckCommand|GraphExportCommand> $commandClass */
    #[Test]
    #[DataProvider('provideExposedTargets')]
    public function itReportsEachExposedTargetBeforeAnalysis(string $commandClass, string $option): void
    {
        $parent = $this->directory . '/open<tag>';
        mkdir($parent, 0o755);
        chmod($parent, 0o777);
        $target = $parent . '/target.json';
        file_put_contents($target, 'KEEP');
        $observer = new class {
            public ?CommandTester $tester = null;
            public ?string $warnings = null;
        };
        $contract = $commandClass === CheckCommand::class
            ? AnalysisPipelineInterface::class
            : DependencyGraphAnalyzerInterface::class;
        $analyzer = self::createStub($contract);
        $analyzer->method('analyze')->willReturnCallback(static function () use ($observer): never {
            if ($observer->tester === null) {
                throw new LogicException('Tester must be ready before analysis.');
            }
            $observer->warnings = $observer->tester->getErrorOutput();
            throw new RuntimeException('Stop after observing pre-analysis diagnostics.');
        });
        $original = (new ContainerFactory())->create()->get($commandClass);
        self::assertInstanceOf($commandClass, $original);
        $reflection = new ReflectionClass($original);
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);
        $arguments = array_map(
            static fn(ReflectionParameter $parameter): mixed => $parameter->getName() === 'analyzer'
                ? $analyzer
                : $reflection->getProperty($parameter->getName())->getValue($original),
            $constructor->getParameters(),
        );
        $command = $reflection->newInstanceArgs($arguments);
        $tester = new CommandTester($command);
        $observer->tester = $tester;
        $tester->execute([
            'paths' => [$this->directory . '/Source.php'],
            '--workers' => '0', '--no-cache' => true, '--format' => 'json',
            $option => $target,
        ], ['capture_stderr_separately' => true]);

        self::assertSame(5, $tester->getStatusCode());
        $observed = $observer->warnings;
        self::assertIsString($observed);
        self::assertStringContainsString('Warning:', $observed);
        self::assertStringContainsString($option, $observed);
        self::assertStringContainsString($target, $observed);
        self::assertStringContainsString('open<tag>', $observed);
        self::assertStringContainsString('others', $observed);
    }

    #[Test]
    public function itLeavesOutputLogAndCacheUntouchedWhenTheLateScopeIsInvalid(): void
    {
        $output = $this->directory . '/report.json';
        $log = $this->directory . '/run.log';
        $cacheEntry = $this->directory . '/cache/entry.cache';
        file_put_contents($output, 'KEEP REPORT');
        file_put_contents($cacheEntry, 'KEEP CACHE');
        $command = (new ContainerFactory())->create()->get(CheckCommand::class);
        self::assertInstanceOf(CheckCommand::class, $command);
        $tester = new CommandTester($command);
        $tester->execute([
            'paths' => [$this->directory . '/Source.php'],
            '--format' => 'json',
            '--workers' => '0',
            '--output' => $output,
            '--log-file' => $log,
            '--cache-dir' => $this->directory . '/cache',
            '--clear-cache' => true,
            '--report' => 'git:staged',
        ], ['capture_stderr_separately' => true]);

        self::assertSame(3, $tester->getStatusCode(), $tester->getDisplay() . $tester->getErrorOutput());
        $envelope = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($envelope);
        self::assertStringContainsString('git', $envelope['error']);
        self::assertSame('KEEP REPORT', file_get_contents($output));
        self::assertFileDoesNotExist($log);
        self::assertSame('KEEP CACHE', file_get_contents($cacheEntry));
        self::assertStringNotContainsString('Cache cleared.', $tester->getErrorOutput());
    }

    #[Test]
    public function itRefusesToClearTheCacheContainingTheRequestedReport(): void
    {
        $output = $this->directory . '/cache/report.json';
        $cacheEntry = $this->directory . '/cache/entry.cache';
        file_put_contents($output, 'KEEP REPORT');
        file_put_contents($cacheEntry, 'KEEP CACHE');
        $command = (new ContainerFactory())->create()->get(CheckCommand::class);
        self::assertInstanceOf(CheckCommand::class, $command);
        $tester = new CommandTester($command);
        $tester->execute([
            'paths' => [$this->directory . '/Source.php'],
            '--format' => 'json',
            '--workers' => '0',
            '--output' => $output,
            '--cache-dir' => $this->directory . '/cache',
            '--clear-cache' => true,
        ], ['capture_stderr_separately' => true]);

        self::assertSame(3, $tester->getStatusCode(), $tester->getDisplay() . $tester->getErrorOutput());
        self::assertStringContainsString('cache', $tester->getDisplay() . $tester->getErrorOutput());
        self::assertSame('KEEP REPORT', file_get_contents($output));
        self::assertSame('KEEP CACHE', file_get_contents($cacheEntry));
        self::assertStringNotContainsString('Report written', $tester->getErrorOutput());
    }

    #[Test]
    public function itRefusesToClearTheCacheContainingTheResolvedReportReferent(): void
    {
        $referent = $this->directory . '/cache/report.json';
        $link = $this->directory . '/report-link.json';
        $cacheEntry = $this->directory . '/cache/entry.cache';
        file_put_contents($referent, 'KEEP REPORT');
        file_put_contents($cacheEntry, 'KEEP CACHE');
        symlink('cache/report.json', $link);
        $command = (new ContainerFactory())->create()->get(CheckCommand::class);
        self::assertInstanceOf(CheckCommand::class, $command);
        $tester = new CommandTester($command);
        $tester->execute([
            'paths' => [$this->directory . '/Source.php'],
            '--format' => 'json',
            '--workers' => '0',
            '--output' => $link,
            '--cache-dir' => $this->directory . '/cache',
            '--clear-cache' => true,
        ], ['capture_stderr_separately' => true]);

        self::assertSame(3, $tester->getStatusCode(), $tester->getDisplay() . $tester->getErrorOutput());
        self::assertSame('KEEP REPORT', file_get_contents($referent));
        self::assertSame('KEEP CACHE', file_get_contents($cacheEntry));
        self::assertTrue(is_link($link));
    }

    #[Test]
    public function itClassifiesAnUnsupportedGraphTargetWithoutWritingAReport(): void
    {
        $command = (new ContainerFactory())->create()->get(GraphExportCommand::class);
        self::assertInstanceOf(GraphExportCommand::class, $command);
        $tester = new CommandTester($command);
        $tester->execute([
            'paths' => [$this->directory . '/Source.php'],
            '--format' => 'json',
            '--output' => 'other-scheme://report.json',
        ], ['capture_stderr_separately' => true]);

        self::assertSame(3, $tester->getStatusCode(), $tester->getDisplay() . $tester->getErrorOutput());
        $envelope = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($envelope);
        self::assertStringContainsString('--output', $envelope['error']);
        self::assertSame(['Source.php', 'cache'], array_values(array_diff((array) scandir($this->directory), ['.', '..'])));
    }

    #[Test]
    public function itClassifiesRawFileFailureBeforeCheckReport(): void
    {
        $analyzer = self::createStub(AnalysisPipelineInterface::class);
        $analyzer->method('analyze')->willThrowException(self::fileFailure());
        $original = (new ContainerFactory())->create()->get(CheckCommand::class);
        self::assertInstanceOf(CheckCommand::class, $original);
        $property = static fn(string $name): mixed => (new ReflectionProperty(CheckCommand::class, $name))->getValue($original);
        $command = new CheckCommand(
            $analyzer,
            $property('findingFilterOrchestrator'),
            $property('runtimeConfigurator'),
            $property('resultPresenter'),
            $property('ruleInputValidator'),
            $property('checkScopeResolver'),
            $property('configurationInputAdapter'),
            $property('configurationResolvers'),
            $property('runTargetSession'),
        );

        $this->assertRefusalRoutes($command, ['--workers' => '0', '--no-cache' => true]);
    }

    #[Test]
    public function itClassifiesEnvironmentFailureAfterCheckReport(): void
    {
        $target = $this->directory . '/profile.json';
        $profile = self::createStub(ProfileReportInterface::class);
        $profile->method('isEnabled')->willReturn(true);
        $profile->method('export')->willThrowException(self::fileFailure($target));
        $original = (new ContainerFactory())->create()->get(CheckCommand::class);
        self::assertInstanceOf(CheckCommand::class, $original);
        $presenter = (new ReflectionProperty(CheckCommand::class, 'resultPresenter'))->getValue($original);
        self::assertInstanceOf(ResultPresenter::class, $presenter);
        $part = static fn(string $name): mixed => (new ReflectionProperty(ResultPresenter::class, $name))->getValue($presenter);
        $errorStream = $part('errorStream');
        self::assertInstanceOf(ErrorStream::class, $errorStream);
        $replacement = new ResultPresenter(
            $part('formatterRegistry'),
            $part('profiler'),
            $part('summaryEnricher'),
            new ProfilePresenter($profile, $errorStream),
            $part('exitCodeResolver'),
            $part('findingFilter'),
            $part('formatterContextFactory'),
            $part('ruleConfiguration'),
            $errorStream,
        );
        $dependency = static fn(string $name): mixed => (new ReflectionProperty(CheckCommand::class, $name))->getValue($original);
        $command = new CheckCommand(
            $dependency('analyzer'),
            $dependency('findingFilterOrchestrator'),
            $dependency('runtimeConfigurator'),
            $replacement,
            $dependency('ruleInputValidator'),
            $dependency('checkScopeResolver'),
            $dependency('configurationInputAdapter'),
            $dependency('configurationResolvers'),
            $dependency('runTargetSession'),
        );
        $tester = new CommandTester($command);
        $tester->execute([
            'paths' => [$this->directory . '/Source.php'],
            '--format' => 'json',
            '--workers' => '0',
            '--no-cache' => true,
            '--profile' => $target,
        ], ['capture_stderr_separately' => true]);

        self::assertSame(3, $tester->getStatusCode(), $tester->getDisplay() . $tester->getErrorOutput());
        $report = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertArrayHasKey('summary', $report);
        self::assertArrayNotHasKey('error', $report);
        self::assertStringContainsString('Environment error:', $tester->getErrorOutput());
        self::assertStringContainsString($target, $tester->getErrorOutput());
        self::assertFileDoesNotExist($target);
    }

    #[Test]
    public function itReportsProfileExportAndCleanupFailuresAfterOneJsonReport(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Directory permissions do not bind root.');
        }

        $profileDirectory = $this->directory . '/profile';
        mkdir($profileDirectory, 0o755);
        $target = $profileDirectory . '/profile.json';
        $sourceRoot = \dirname(__DIR__, 4);
        $script = <<<'PHP'
            require $argv[1];

            $parent = $argv[2];
            $target = $argv[3];
            $container = (new \Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory())->create();
            $original = $container->get(\Qualimetrix\Infrastructure\Console\Command\CheckCommand::class);
            $part = static fn(string $name): mixed => (new \ReflectionProperty(\Qualimetrix\Infrastructure\Console\Command\CheckCommand::class, $name))->getValue($original);
            $presenter = $part('resultPresenter');
            $presenterPart = static fn(string $name): mixed => (new \ReflectionProperty(\Qualimetrix\Infrastructure\Console\ResultPresenter::class, $name))->getValue($presenter);
            $errorStream = $presenterPart('errorStream');
            $profile = new class ($parent, $target) implements \Qualimetrix\Infrastructure\Profiler\Contract\ProfileReportInterface {
                public function __construct(private readonly string $parent, private readonly string $target) {}

                public function isEnabled(): bool { return true; }

                public function summary(): \Qualimetrix\Infrastructure\Profiler\Contract\ProfileSummary
                {
                    throw new \LogicException('Profile summary was not requested');
                }

                public function export(\Qualimetrix\Infrastructure\Profiler\Contract\ProfileFormat $format): string
                {
                    $staged = array_filter((array) scandir($this->parent), static fn(string $name): bool => str_starts_with($name, '.qmx-'));
                    if (\count($staged) !== 1 || !chmod($this->parent, 0o555)) {
                        throw new \LogicException('Claimed profile target was not prepared');
                    }

                    throw new \Qualimetrix\Core\FileTarget\FileTargetFailure(
                        \Qualimetrix\Core\FileTarget\FileTargetFailureKind::Unopenable,
                        $this->target,
                        'primary profile delivery failure',
                    );
                }
            };
            $replacement = new \Qualimetrix\Infrastructure\Console\ResultPresenter(
                $presenterPart('formatterRegistry'),
                $presenterPart('profiler'),
                $presenterPart('summaryEnricher'),
                new \Qualimetrix\Infrastructure\Console\ProfilePresenter($profile, $errorStream),
                $presenterPart('exitCodeResolver'),
                $presenterPart('findingFilter'),
                $presenterPart('formatterContextFactory'),
                $presenterPart('ruleConfiguration'),
                $errorStream,
            );
            $command = new \Qualimetrix\Infrastructure\Console\Command\CheckCommand(
                $part('analyzer'),
                $part('findingFilterOrchestrator'),
                $part('runtimeConfigurator'),
                $replacement,
                $part('ruleInputValidator'),
                $part('checkScopeResolver'),
                $part('configurationInputAdapter'),
                $part('configurationResolvers'),
                $part('runTargetSession'),
            );
            $app = new \Qualimetrix\Infrastructure\Console\Application(
                $errorStream,
                $container->get(\Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter::class),
                new \Qualimetrix\Infrastructure\Composer\ComposerManifestReader(),
            );
            $app->addCommand($command);
            exit($app->run(
                new \Symfony\Component\Console\Input\ArgvInput([
                    'qmx', 'check', 'Source.php', '--format=json', '--workers=0', '--no-cache', '--profile=' . $target,
                ]),
                new \Symfony\Component\Console\Output\ConsoleOutput(),
            ));
            PHP;

        try {
            $run = ChildProcess::run([
                \PHP_BINARY,
                '-r',
                $script,
                '--',
                $sourceRoot . '/vendor/autoload.php',
                $profileDirectory,
                $target,
            ], $this->directory);

            self::assertSame(3, $run['exitCode'], $run['stdout'] . $run['stderr']);
            self::assertJson($run['stdout'], 'A second JSON refusal followed the published report.');
            /** @var array<string, mixed> $report */
            $report = json_decode($run['stdout'], true, flags: \JSON_THROW_ON_ERROR);
            self::assertArrayHasKey('summary', $report);
            self::assertArrayNotHasKey('error', $report);
            self::assertStringContainsString('primary profile delivery failure', $run['stderr']);
            self::assertStringContainsString('cannot remove the temporary file', $run['stderr']);
            self::assertStringContainsString('unlink(', $run['stderr']);
            self::assertFileDoesNotExist($target);
            $entries = scandir($profileDirectory);
            self::assertIsArray($entries);
            self::assertCount(1, array_filter($entries, static fn(string $name): bool => str_starts_with($name, '.qmx-')));
        } finally {
            chmod($profileDirectory, 0o755);
        }
    }

    #[Test]
    public function itClassifiesRawFileFailureAtGraphExit(): void
    {
        $analyzer = self::createStub(DependencyGraphAnalyzerInterface::class);
        $analyzer->method('analyze')->willThrowException(self::fileFailure());
        $original = (new ContainerFactory())->create()->get(GraphExportCommand::class);
        self::assertInstanceOf(GraphExportCommand::class, $original);
        $preflight = (new ReflectionProperty(GraphExportCommand::class, 'preflight'))->getValue($original);
        self::assertInstanceOf(AnalysisPreflight::class, $preflight);
        $errorStream = new ErrorStream();
        $command = new GraphExportCommand(
            $analyzer,
            new DependencyGraphProjector(),
            $preflight,
            $errorStream,
            new RunTargetSession(
                new \Qualimetrix\Infrastructure\Console\RunTarget\RunTargets(new \Qualimetrix\Infrastructure\Logging\LoggerFactory()),
                new RefusalPresenter($errorStream),
            ),
        );

        $this->assertRefusalRoutes($command, humanFormat: 'dot');
    }

    #[Test]
    public function itKeepsAnExplicitStandardOutputGraphAsOneJsonDocumentWhenCleanupFails(): void
    {
        $sourceRoot = \dirname(__DIR__, 4);
        $script = <<<'PHP'
            require $argv[1];

            $container = (new \Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory())->create();
            $original = $container->get(\Qualimetrix\Infrastructure\Console\Command\GraphExportCommand::class);
            $part = static fn(string $name): mixed => (new \ReflectionProperty(\Qualimetrix\Infrastructure\Console\Command\GraphExportCommand::class, $name))->getValue($original);
            $errorStream = $part('errorStream');
            $presenter = new \Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter($errorStream);
            $factory = new class implements \Qualimetrix\Infrastructure\Logging\Contract\LoggerFactoryInterface {
                public function create(\Symfony\Component\Console\Output\OutputInterface $diagnostics, ?string $logFile, ?string $level): \Psr\Log\LoggerInterface
                {
                    return new \Psr\Log\NullLogger();
                }

                public function attachFileTarget(\Qualimetrix\Core\FileTarget\HeldTarget $target): void {}

                public function settle(): void {}

                public function reset(): void
                {
                    throw new \InvalidArgumentException('graph cleanup failed');
                }
            };
            $session = new \Qualimetrix\Infrastructure\Console\RunTarget\RunTargetSession(
                new \Qualimetrix\Infrastructure\Console\RunTarget\RunTargets($factory),
                $presenter,
            );
            $command = new \Qualimetrix\Infrastructure\Console\Command\GraphExportCommand(
                $part('analyzer'),
                $part('projection'),
                $part('preflight'),
                $errorStream,
                $session,
            );
            $app = new \Qualimetrix\Infrastructure\Console\Application(
                $errorStream,
                $presenter,
                new \Qualimetrix\Infrastructure\Composer\ComposerManifestReader(),
            );
            $app->addCommand($command);
            exit($app->run(
                new \Symfony\Component\Console\Input\ArgvInput([
                    'qmx', 'graph:export', 'Source.php', '--format=json', '--output=php://stdout', '--workers=0', '--no-cache',
                ]),
                new \Symfony\Component\Console\Output\ConsoleOutput(),
            ));
            PHP;

        $run = ChildProcess::run([
            \PHP_BINARY,
            '-r',
            $script,
            '--',
            $sourceRoot . '/vendor/autoload.php',
        ], $this->directory);

        self::assertSame(5, $run['exitCode'], $run['stdout'] . $run['stderr']);
        self::assertJson($run['stdout'], 'A second JSON refusal followed the completed graph.');
        /** @var array<string, mixed> $graph */
        $graph = json_decode($run['stdout'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('meta', $graph);
        self::assertArrayHasKey('nodes', $graph);
        self::assertArrayNotHasKey('error', $graph);
        self::assertStringContainsString('Internal error:', $run['stderr']);
        self::assertStringContainsString('graph cleanup failed', $run['stderr']);
    }

    #[Test]
    public function itClassifiesRawFileFailureAtDirectivesExit(): void
    {
        $audit = self::createStub(DirectiveAuditInterface::class);
        $audit->method('auditDirectives')->willThrowException(new class ('storage unavailable') extends Exception implements EnvironmentFailureInterface {});
        $original = (new ContainerFactory())->create()->get(DirectivesCommand::class);
        self::assertInstanceOf(DirectivesCommand::class, $original);
        $preflight = (new ReflectionProperty(DirectivesCommand::class, 'preflight'))->getValue($original);
        self::assertInstanceOf(AnalysisPreflight::class, $preflight);
        $errorStream = new ErrorStream();

        $this->assertRefusalRoutes(new DirectivesCommand($audit, $preflight, new RefusalPresenter($errorStream)));
    }

    /** @param array<string, mixed> $options */
    private function assertRefusalRoutes(\Symfony\Component\Console\Command\Command $command, array $options = [], string $humanFormat = 'text'): void
    {
        foreach (['json', $humanFormat] as $format) {
            $tester = new CommandTester($command);
            $tester->execute([
                'paths' => [$this->directory . '/Source.php'],
                '--format' => $format,
                ...$options,
            ], ['capture_stderr_separately' => true]);

            self::assertSame(3, $tester->getStatusCode(), $tester->getDisplay() . $tester->getErrorOutput());
            if ($format === 'json') {
                self::assertSame('', $tester->getErrorOutput());
                $envelope = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
                self::assertSame(3, $envelope['exit_code']);
                self::assertStringContainsString('Environment error:', $envelope['error']);
                self::assertNull($envelope['source']);
            } else {
                self::assertSame('', $tester->getDisplay());
                self::assertStringContainsString('Environment error:', $tester->getErrorOutput());
            }
        }
    }

    private static function fileFailure(string $spelling = 'report.json'): FileTargetFailure
    {
        return new FileTargetFailure(FileTargetFailureKind::Unopenable, $spelling, 'storage unavailable');
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
                self::remove($path . '/' . $entry);
            }
            rmdir($path);

            return;
        }
        unlink($path);
    }
}
