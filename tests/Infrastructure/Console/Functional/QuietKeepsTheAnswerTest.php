<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\ProductIdentity;
use Qualimetrix\Infrastructure\Console\Application\Application;
use Qualimetrix\Infrastructure\Console\Application\ConsoleDiagnostics;
use Qualimetrix\Infrastructure\Console\Baseline\Explanation\BaselineExplainCommand;
use Qualimetrix\Infrastructure\Console\Hook\HookStatusCommand;
use Qualimetrix\Infrastructure\Console\Refusal\OutOfMemoryHint;
use Qualimetrix\Infrastructure\Console\Terminal\ReportPublication\OutputHelper;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Subprocess\ChildProcess;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Filesystem\Filesystem;

require_once \dirname(__DIR__, 4) . '/scripts/subprocess/ChildProcess.php';

#[CoversClass(OutputHelper::class)]
final class QuietKeepsTheAnswerTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function formats(): iterable
    {
        foreach (['summary', 'text', 'json', 'checkstyle', 'sarif', 'gitlab', 'github', 'metrics', 'health', 'html', 'suppressed'] as $format) {
            yield $format => [$format];
        }
    }

    #[Test]
    #[DataProvider('formats')]
    public function itKeepsEachCheckAnswerUnderQuietAndSilent(string $format): void
    {
        $directory = self::fixtureDirectory();
        try {
            $tester = null;
            $input = [
                'command' => 'check',
                'paths' => ['Source.php'],
                '--workers' => '0',
                '--no-cache' => true,
                '--no-progress' => true,
                '--format' => $format,
            ];
            $normal = self::runThroughApplication($directory, $input, $tester);
            self::assertSame(0, $normal['exitCode'], $normal['stdout'] . $normal['stderr']);
            self::assertNotSame('', $normal['stdout']);
            self::assertSame('', $normal['stderr']);
            foreach (['-q', '--silent'] as $flag) {
                $quiet = self::runThroughApplication($directory, [...$input, $flag => true], $tester);
                self::assertSame(0, $quiet['exitCode'], $quiet['stdout'] . $quiet['stderr']);
                self::assertSame('', $quiet['stderr']);
                self::assertSame(self::stableAnswer($normal['stdout'], $format), self::stableAnswer($quiet['stdout'], $format));
            }
        } finally {
            self::removeFixture($directory);
        }
    }

    #[Test]
    public function itKeepsAnAnswerForShellQuietAndSilentVerbosity(): void
    {
        $directory = self::fixtureDirectory();
        try {
            $args = ['check', 'Source.php', '--workers=0', '--no-cache', '--no-progress', '--format=json'];
            $normal = self::runCommand($directory, $args);
            self::assertSame(0, $normal['exitCode']);
            foreach (['-1', '-2'] as $verbosity) {
                $environment = getenv();
                self::assertIsArray($environment);
                $environment['SHELL_VERBOSITY'] = $verbosity;
                $quiet = self::runCommand($directory, $args, $environment);
                self::assertSame(0, $quiet['exitCode'], $quiet['stdout'] . $quiet['stderr']);
                self::assertSame('', $quiet['stderr']);
                self::assertSame(self::stableAnswer($normal['stdout'], 'json'), self::stableAnswer($quiet['stdout'], 'json'));
            }
        } finally {
            self::removeFixture($directory);
        }
    }

    #[Test]
    public function itKeepsReportsForFindingsAndIncompleteAnalysis(): void
    {
        $directory = self::fixtureDirectory();
        try {
            foreach ([
                ['<?php eval("1+1;");', 2, 'code-smell.eval'],
                ['<?php function', 4, 'Failed file: Source.php'],
            ] as [$source, $exit, $needle]) {
                self::assertNotFalse(file_put_contents($directory . '/Source.php', $source));
                $args = ['check', 'Source.php', '--workers=0', '--no-cache', '--no-progress', '--format=text', '--fail-on=warning'];
                $normal = self::runCommand($directory, $args);
                $quiet = self::runCommand($directory, [...$args, '-q']);
                self::assertSame($exit, $normal['exitCode']);
                self::assertSame($exit, $quiet['exitCode']);
                self::assertSame($normal['stdout'], $quiet['stdout']);
                self::assertStringContainsString($needle, $quiet['stdout']);
                self::assertSame('', $quiet['stderr']);
            }
        } finally {
            self::removeFixture($directory);
        }
    }

    #[Test]
    public function itKeepsListingAnswersAndMutesActionStatus(): void
    {
        $directory = self::fixtureDirectory();
        try {
            $init = ChildProcess::run(['git', 'init', '-q'], $directory);
            self::assertSame(0, $init['exitCode'], $init['stdout'] . $init['stderr']);
            foreach ([
                ['command' => 'rules'],
                ['command' => 'directives', 'paths' => ['Source.php'], '--no-progress' => true],
                ['command' => 'graph:export', 'paths' => ['Source.php'], '--workers' => '0', '--no-cache' => true, '--format' => 'json'],
                ['command' => 'hook:status'],
                ['command' => 'baseline:explain', 'subject' => 'declaration:class:Source@Source.php', 'paths' => ['Source.php'], '--workers' => '0', '--no-cache' => true, '--no-progress' => true],
                ['command' => 'debug:layer-assignment', 'fqn' => 'Source', '--no-progress' => true],
            ] as $args) {
                $normal = self::runThroughApplication($directory, $args);
                $quiet = self::runThroughApplication($directory, [...$args, '-q' => true]);
                self::assertSame($normal['exitCode'], $quiet['exitCode']);
                self::assertNotSame('', $normal['stdout']);
                $answerFormat = $args['command'] === 'graph:export' ? 'graph' : 'exact';
                self::assertSame(self::stableAnswer($normal['stdout'], $answerFormat), self::stableAnswer($quiet['stdout'], $answerFormat));
            }

            $baseline = $directory . '/baseline.json';
            $action = self::runThroughApplication($directory, ['command' => 'baseline:generate', 'baseline' => $baseline, 'paths' => ['Source.php'], '--workers' => '0', '--no-cache' => true, '--no-progress' => true, '-q' => true]);
            self::assertSame(0, $action['exitCode'], $action['stdout'] . $action['stderr']);
            self::assertSame('', $action['stdout']);
            self::assertSame('', $action['stderr']);
            self::assertFileExists($baseline);
            self::assertSame('', self::runThroughApplication($directory, ['command' => 'list', '-q' => true])['stdout']);
        } finally {
            self::removeFixture($directory);
        }
    }

    #[Test]
    public function itKeepsTheStyledDocsPointerInsideDecoratedAnswers(): void
    {
        $directory = self::fixtureDirectory();
        try {
            self::assertSame(0, ChildProcess::run(['git', 'init', '-q'], $directory)['exitCode']);
            foreach ([
                ['command' => 'directives', 'paths' => ['Source.php']],
                ['command' => 'hook:status'],
                ['command' => 'baseline:explain', 'subject' => 'declaration:class:Source@Source.php', 'paths' => ['Source.php'], '--workers' => '0', '--no-cache' => true, '--no-progress' => true],
            ] as $args) {
                $normal = self::runThroughApplication($directory, [...$args, '--ansi' => true]);
                $quiet = self::runThroughApplication($directory, [...$args, '--ansi' => true, '-q' => true]);
                self::assertSame(0, $normal['exitCode'], $normal['stdout'] . $normal['stderr']);
                self::assertSame(0, $quiet['exitCode'], $quiet['stdout'] . $quiet['stderr']);
                self::assertSame($normal['stdout'], $quiet['stdout']);
                self::assertSame(1, substr_count($normal['stdout'], ProductIdentity::pointerText()));
                self::assertStringContainsString("\033[33m" . ProductIdentity::pointerText() . "\033[39m", $normal['stdout']);
            }
        } finally {
            self::removeFixture($directory);
        }
    }

    #[Test]
    public function itPublishesHookAndBaselineAnswersWithoutASecondFormattedStreamWrite(): void
    {
        $directory = self::fixtureDirectory();
        $previous = getcwd();
        self::assertNotFalse($previous);
        $previousErrorReporting = error_reporting();
        $previousDisplayErrors = \ini_get('display_errors');
        $hint = OutOfMemoryHint::register();
        try {
            self::assertSame(0, ChildProcess::run(['git', 'init', '-q'], $directory)['exitCode']);
            self::assertTrue(chdir($directory));
            $container = (new ContainerFactory())->create();
            foreach ([
                [HookStatusCommand::class, []],
                [BaselineExplainCommand::class, [
                    'subject' => 'declaration:class:Source@Source.php',
                    'paths' => ['Source.php'],
                    '--workers' => '0',
                    '--no-cache' => true,
                    '--no-progress' => true,
                ]],
            ] as [$commandClass, $arguments]) {
                $command = $container->get($commandClass);
                self::assertInstanceOf(\Symfony\Component\Console\Command\Command::class, $command);
                $stream = fopen('php://temp', 'w+');
                self::assertIsResource($stream);
                try {
                    $output = new class ($stream, OutputInterface::VERBOSITY_NORMAL, true) extends StreamOutput {
                        public int $formattedWrites = 0;

                        protected function doWrite(string $message, bool $newline): void
                        {
                            ++$this->formattedWrites;
                            parent::doWrite($message, $newline);
                        }
                    };
                    self::assertSame(0, $command->run(new ArrayInput($arguments, $command->getDefinition()), $output));
                    self::assertSame(0, $output->formattedWrites, $commandClass . ' wrote after publishing the answer');
                    rewind($stream);
                    $answer = stream_get_contents($stream);
                    self::assertIsString($answer);
                    self::assertSame(1, substr_count($answer, ProductIdentity::pointerText()));
                    self::assertStringContainsString("\033[33m" . ProductIdentity::pointerText() . "\033[39m", $answer);
                } finally {
                    fclose($stream);
                }
            }
        } finally {
            $hint->unbind();
            error_reporting($previousErrorReporting);
            if ($previousDisplayErrors !== false) {
                ini_set('display_errors', $previousDisplayErrors);
            }
            chdir($previous);
            self::removeFixture($directory);
        }
    }

    /** @param list<string> $args
     * @param ?array<string, string> $environment
     *
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private static function runCommand(string $directory, array $args, ?array $environment = null): array
    {
        return ChildProcess::run([\PHP_BINARY, \dirname(__DIR__, 4) . '/bin/qmx', ...$args], $directory, environment: $environment);
    }

    /**
     * Application input parsing and real commands own answer parity; native
     * processes remain for inherited verbosity and non-success reports.
     *
     * CheckCommand resets runtime state and the application refreshes its manifest
     * snapshot on every run, so one format's three invocations may share a tester.
     *
     * @param array<string, mixed> $input
     *
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private static function runThroughApplication(string $directory, array $input, ?ApplicationTester &$tester = null): array
    {
        $previousDirectory = getcwd();
        $previousErrorReporting = error_reporting();
        $previousDisplayErrors = \ini_get('display_errors');
        $terminalEnvironment = ['LINES' => getenv('LINES'), 'COLUMNS' => getenv('COLUMNS')];
        if ($previousDirectory === false || !chdir($directory)) {
            throw new RuntimeException('Could not enter the command fixture directory.');
        }

        $hint = OutOfMemoryHint::register();
        try {
            if ($tester === null) {
                $application = Application::forProduct(
                    static fn(ConsoleDiagnostics $diagnostics) => (new ContainerFactory())->create($diagnostics),
                    $hint,
                );
                $application->setAutoExit(false);
                $tester = new ApplicationTester($application);
            }
            $tester->run($input, ['capture_stderr_separately' => true]);

            return [
                'stdout' => $tester->getDisplay(),
                'stderr' => $tester->getErrorOutput(),
                'exitCode' => $tester->getStatusCode(),
            ];
        } finally {
            $hint->unbind();
            error_reporting($previousErrorReporting);
            if ($previousDisplayErrors !== false) {
                ini_set('display_errors', $previousDisplayErrors);
            }
            foreach ($terminalEnvironment as $name => $value) {
                putenv($name . ($value === false ? '' : '=' . $value));
            }
            chdir($previousDirectory);
        }
    }

    #[Test]
    public function itKeepsNumericCountsMetricsVersionsAndPathsObservableInParityChecks(): void
    {
        $answer = '{"meta":{"version":"1.0.0","timestamp":"2026-10-14T10:00:00+00:00"},'
            . '"summary":{"filesAnalyzed":10,"duration":0.018,"violationCount":2},'
            . '"metrics":{"health.overall":85},"paths":["src/Foo2.php"]}';
        $stable = self::stableAnswer($answer, 'json');
        self::assertSame($stable, self::stableAnswer(str_replace('10:00:00', '10:00:01', $answer), 'json'));
        self::assertSame($stable, self::stableAnswer(str_replace('"duration":0.018', '"duration":0.019', $answer), 'json'));
        foreach ([
            ['"filesAnalyzed":10', '"filesAnalyzed":11'],
            ['"health.overall":85', '"health.overall":84'],
            ['"version":"1.0.0"', '"version":"1.0.1"'],
            ['src/Foo2.php', 'src/Foo3.php'],
        ] as [$before, $after]) {
            self::assertNotSame($stable, self::stableAnswer(str_replace($before, $after, $answer), 'json'));
        }

        $summary = 'Qualimetrix dev-main — 10 files analyzed, 0.1s';
        self::assertSame(self::stableAnswer($summary, 'summary'), self::stableAnswer('Qualimetrix dev-main — 10 files analyzed, 0.2s', 'summary'));
        self::assertNotSame(self::stableAnswer($summary, 'summary'), self::stableAnswer('Qualimetrix dev-main — 11 files analyzed, 0.1s', 'summary'));
    }

    private static function stableAnswer(string $answer, string $format): string
    {
        return match ($format) {
            'json' => self::maskJsonTime($answer, [['meta', 'timestamp'], ['summary', 'duration']]),
            'metrics' => self::maskJsonTime($answer, [['timestamp'], ['summary', 'duration']]),
            'suppressed', 'graph' => self::maskJsonTime($answer, [['meta', 'timestamp']]),
            'summary' => self::maskSummaryDuration($answer),
            'html' => self::maskHtmlGeneratedAt($answer),
            default => $answer,
        };
    }

    /** @param list<list<string>> $paths */
    private static function maskJsonTime(string $answer, array $paths): string
    {
        $document = json_decode($answer, true, flags: \JSON_THROW_ON_ERROR);
        foreach ($paths as $path) {
            $node = $document;
            foreach ($path as $key) {
                self::assertIsArray($node);
                self::assertArrayHasKey($key, $node);
                $node = $node[$key];
            }

            $field = $path[\count($path) - 1];
            if ($field === 'duration') {
                self::assertIsNumeric($node);
                $pattern = '/("duration"\s*:\s*)-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?/';
            } else {
                self::assertIsString($node);
                $pattern = '/("' . preg_quote($field, '/') . '"\s*:\s*)"[^"]*"/';
            }
            self::assertSame(1, preg_match_all($pattern, $answer));
            $masked = preg_replace($pattern, '${1}"<variable time>"', $answer, 1);
            self::assertIsString($masked);
            $answer = $masked;
        }

        return $answer;
    }

    private static function maskSummaryDuration(string $answer): string
    {
        $pattern = '/^(Qualimetrix [^\r\n]+ — \d+ files? analyzed, )\d+(?:\.\d+)?s$/m';
        self::assertSame(1, preg_match_all($pattern, $answer));
        $masked = preg_replace($pattern, '${1}<duration>s', $answer, 1);
        self::assertIsString($masked);

        return $masked;
    }

    private static function maskHtmlGeneratedAt(string $answer): string
    {
        self::assertSame(1, preg_match('/<script type="application\/json" id="report-data">(.*?)<\/script>/s', $answer, $matches, \PREG_OFFSET_CAPTURE));
        $payload = $matches[1][0];
        $offset = $matches[1][1];
        $masked = self::maskJsonTime($payload, [['project', 'generatedAt']]);

        return substr($answer, 0, $offset) . $masked . substr($answer, $offset + \strlen($payload));
    }

    private static function fixtureDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/qmx-quiet-answer-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory));
        self::assertNotFalse(file_put_contents($directory . '/Source.php', '<?php final class Source {}'));

        return $directory;
    }

    private static function removeFixture(string $directory): void
    {
        (new Filesystem())->remove($directory);
    }
}
