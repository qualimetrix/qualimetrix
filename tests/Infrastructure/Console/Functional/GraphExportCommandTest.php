<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Qualimetrix\Analysis\Evidence\DependencyModel\DependencyGraphBuilder;
use Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\DependencyResolver;
use Qualimetrix\Analysis\Evidence\DependencyModel\Extraction\DependencyVisitor;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\DeclarationRegistrarFactory;
use Qualimetrix\Analysis\Run\Contract\Pipeline\DependencyGraphAnalysisResult;
use Qualimetrix\Analysis\Run\Contract\Pipeline\DependencyGraphAnalyzerInterface;
use Qualimetrix\Analysis\Run\Discovery\FinderFileDiscovery;
use Qualimetrix\Analysis\Run\Pipeline\DependencyGraphAnalyzer;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Ast\PhpFileParser;
use Qualimetrix\Infrastructure\Console\Command\GraphExportCommand;
use Qualimetrix\Infrastructure\Console\ErrorStream;
use Qualimetrix\Infrastructure\Console\Refusal\RefusalPresenter;
use Qualimetrix\Reporting\GraphProjection\DependencyGraphProjector;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(GraphExportCommand::class)]
final class GraphExportCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        // Create temporary directory for test files
        $this->tempDir = sys_get_temp_dir() . '/qmx-test-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        // Clean up temporary directory
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    #[Test]
    public function itExportsDependencyGraphToDotFormat(): void
    {
        // Create test PHP files with dependencies
        $classA = $this->tempDir . '/ClassA.php';
        file_put_contents($classA, '<?php namespace Test; class ClassA { public function useB(ClassB $b) {} }');

        $classB = $this->tempDir . '/ClassB.php';
        file_put_contents($classB, '<?php namespace Test; class ClassB {}');

        // Create command
        $command = new GraphExportCommand(
            $this->createAnalyzer(),
            new DependencyGraphProjector(),
            new ErrorStream(),
            new RefusalPresenter(new ErrorStream()),
            new NullLogger(),
        );

        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute([
            'paths' => [$this->tempDir],
        ]);

        // Assert success
        self::assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();

        // Verify DOT format output
        self::assertStringContainsString('digraph', $output);
        self::assertStringContainsString('ClassA', $output);
        self::assertStringContainsString('ClassB', $output);
    }

    #[Test]
    public function itExportsGraphToFile(): void
    {
        // Create test PHP files with dependencies
        // Note: classes without dependencies are not included in the graph
        $classA = $this->tempDir . '/ClassA.php';
        file_put_contents($classA, '<?php namespace Test; class ClassA { public function use(ClassB $b) {} }');

        $classB = $this->tempDir . '/ClassB.php';
        file_put_contents($classB, '<?php namespace Test; class ClassB {}');

        $outputFile = $this->tempDir . '/graph.dot';

        // Create command
        $command = new GraphExportCommand(
            $this->createAnalyzer(),
            new DependencyGraphProjector(),
            new ErrorStream(),
            new RefusalPresenter(new ErrorStream()),
            new NullLogger(),
        );

        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute([
            'paths' => [$this->tempDir],
            '--output' => $outputFile,
        ]);

        // Assert success
        self::assertSame(0, $commandTester->getStatusCode());
        self::assertFileExists($outputFile);

        $content = file_get_contents($outputFile);
        self::assertIsString($content);
        self::assertStringContainsString('digraph', $content);
        self::assertStringContainsString('ClassA', $content);
    }

    #[Test]
    public function itFailsWhenNoFilesFound(): void
    {
        // Create empty directory
        $emptyDir = $this->tempDir . '/empty';
        mkdir($emptyDir);

        // Create command
        $command = new GraphExportCommand(
            $this->createAnalyzer(),
            new DependencyGraphProjector(),
            new ErrorStream(),
            new RefusalPresenter(new ErrorStream()),
            new NullLogger(),
        );

        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute([
            'paths' => [$emptyDir],
        ]);

        // Assert failure
        self::assertSame(1, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        self::assertStringContainsString('No files found', $output);
    }

    #[Test]
    public function itSupportsNamespaceFiltering(): void
    {
        // Create test PHP files in different namespaces with dependencies
        // Note: classes without dependencies are not included in the graph
        $classA = $this->tempDir . '/ClassA.php';
        file_put_contents($classA, '<?php namespace App\\Service; use App\\Controller\\ClassB; class ClassA { public function use(ClassB $b) {} }');

        $classB = $this->tempDir . '/ClassB.php';
        file_put_contents($classB, '<?php namespace App\\Controller; class ClassB {}');

        // Create command
        $command = new GraphExportCommand(
            $this->createAnalyzer(),
            new DependencyGraphProjector(),
            new ErrorStream(),
            new RefusalPresenter(new ErrorStream()),
            new NullLogger(),
        );

        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute([
            'paths' => [$this->tempDir],
            '--namespace' => ['App\\Service'],
        ]);

        // Assert success
        self::assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();

        // Verify only Service namespace is included
        self::assertStringContainsString('ClassA', $output);
        self::assertStringNotContainsString('ClassB', $output);
    }

    #[Test]
    public function itSupportsDirectionOption(): void
    {
        // Create test PHP files with dependency
        // Note: classes without dependencies are not included in the graph
        $classA = $this->tempDir . '/ClassA.php';
        file_put_contents($classA, '<?php namespace Test; class ClassA { public function use(ClassB $b) {} }');

        $classB = $this->tempDir . '/ClassB.php';
        file_put_contents($classB, '<?php namespace Test; class ClassB {}');

        // Create command
        $command = new GraphExportCommand(
            $this->createAnalyzer(),
            new DependencyGraphProjector(),
            new ErrorStream(),
            new RefusalPresenter(new ErrorStream()),
            new NullLogger(),
        );

        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute([
            'paths' => [$this->tempDir],
            '--direction' => 'TB',
        ]);

        // Assert success
        self::assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        self::assertStringContainsString('rankdir=TB', $output);
    }

    #[Test]
    public function itSupportsNoClustersOption(): void
    {
        // Create test PHP files with dependency
        // Note: classes without dependencies are not included in the graph
        $classA = $this->tempDir . '/ClassA.php';
        file_put_contents($classA, '<?php namespace Test; class ClassA { public function use(ClassB $b) {} }');

        $classB = $this->tempDir . '/ClassB.php';
        file_put_contents($classB, '<?php namespace Test; class ClassB {}');

        // Create command
        $command = new GraphExportCommand(
            $this->createAnalyzer(),
            new DependencyGraphProjector(),
            new ErrorStream(),
            new RefusalPresenter(new ErrorStream()),
            new NullLogger(),
        );

        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute([
            'paths' => [$this->tempDir],
            '--no-clusters' => true,
        ]);

        // Assert success
        self::assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();

        // When no clusters, there should be no "subgraph cluster_" in output
        self::assertStringNotContainsString('subgraph cluster_', $output);
    }

    #[Test]
    public function itExportsDependencyGraphAsJsonToStdout(): void
    {
        file_put_contents(
            $this->tempDir . '/Service.php',
            '<?php namespace App; final class Service { public function use(Model $model): void {} }',
        );

        $tester = $this->createCommandTester();
        $tester->execute(['paths' => [$this->tempDir], '--format' => 'json']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertJson($tester->getDisplay());
        self::assertStringContainsString('App\\\\Service', $tester->getDisplay());
    }

    #[Test]
    public function itExportsDependencyGraphAsJsonToFile(): void
    {
        file_put_contents(
            $this->tempDir . '/Service.php',
            '<?php namespace App; final class Service { public function use(Model $model): void {} }',
        );
        $destination = $this->tempDir . '/graph.json';

        $tester = $this->createCommandTester();
        $tester->execute([
            'paths' => [$this->tempDir],
            '--format' => 'json',
            '--output' => $destination,
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertFileExists($destination);
        self::assertJson((string) file_get_contents($destination));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function provideIncompleteExportCases(): iterable
    {
        yield 'DOT stdout' => ['dot', false];
        yield 'JSON stdout' => ['json', false];
        yield 'DOT output file' => ['dot', true];
        yield 'JSON output file' => ['json', true];
    }

    #[Test]
    #[DataProvider('provideIncompleteExportCases')]
    public function itRefusesAllFailedAnalysisWithoutCreatingAnArtifact(string $format, bool $toFile): void
    {
        file_put_contents($this->tempDir . '/Broken.php', '<?php broken syntax');
        $destination = $this->tempDir . '/graph.' . $format;
        $arguments = ['paths' => [$this->tempDir], '--format' => $format];
        if ($toFile) {
            $arguments['--output'] = $destination;
        }

        $tester = $this->createCommandTester();
        $tester->execute($arguments, ['capture_stderr_separately' => true]);

        self::assertSame(4, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('Analysis incomplete: 1 of 1', $tester->getErrorOutput());
        self::assertStringContainsString('Broken.php:', $tester->getErrorOutput());
        self::assertFileDoesNotExist($destination);
    }

    #[Test]
    public function itPreservesExistingOutputBytesOnPartialAnalysis(): void
    {
        file_put_contents(
            $this->tempDir . '/Good.php',
            '<?php namespace App; final class Good { public function use(Model $model): void {} }',
        );
        file_put_contents($this->tempDir . '/Broken.php', '<?php broken syntax');
        $destination = $this->tempDir . '/graph.json';
        file_put_contents($destination, "existing bytes\n");

        $tester = $this->createCommandTester();
        $tester->execute([
            'paths' => [$this->tempDir],
            '--format' => 'json',
            '--output' => $destination,
        ], ['capture_stderr_separately' => true]);

        self::assertSame(4, $tester->getStatusCode());
        self::assertSame("existing bytes\n", file_get_contents($destination));
        self::assertStringContainsString('Analysis incomplete: 1 of 2', $tester->getErrorOutput());
    }

    /**
     * `--direction`/`--format` are refused before `analyzeDependencyGraph()`
     * runs at all (`01-refusal-packages.md`, P01-5 DoD): the evidence is a
     * call counter on a substituted analyzer, not a timing comparison —
     * `01-refusal-evidence.md` §10 names the counter as the required proof.
     * The positive case at the end of this test is what makes the counter
     * meaningful: a spy that is never shown to fire proves nothing.
     */
    #[Test]
    public function itRefusesABogusDirectionOrFormatWithoutRunningAnalysis(): void
    {
        file_put_contents(
            $this->tempDir . '/ClassA.php',
            '<?php namespace Test; class ClassA { public function use(ClassB $b) {} }',
        );

        $analyzer = new CountingDependencyGraphAnalyzer($this->createAnalyzer());

        foreach ([['--direction' => 'NE'], ['--format' => 'yaml']] as $badOption) {
            $tester = $this->createCommandTesterWithAnalyzer($analyzer);
            $exit = $tester->execute(
                ['paths' => [$this->tempDir], ...$badOption],
                ['capture_stderr_separately' => true],
            );

            self::assertSame(3, $exit, (string) json_encode($badOption));
            self::assertSame(0, $analyzer->calls, 'the analyzer must not run for a refused ' . array_key_first($badOption));
        }

        // The positive control: the same spy, given valid input, does run —
        // proving the assertions above test a refusal, not a spy that never
        // fires.
        $tester = $this->createCommandTesterWithAnalyzer($analyzer);
        $exit = $tester->execute(['paths' => [$this->tempDir]]);
        self::assertSame(0, $exit);
        self::assertSame(1, $analyzer->calls);
    }

    /**
     * `--direction=bogus --format=json` must give a parseable envelope on
     * stdout, not zero bytes — this is the defect the round exists to fix,
     * reproduced in the command P01-5 owns (`01-refusal-packages.md` DoD).
     */
    #[Test]
    public function itRefusesABogusDirectionWithAParseableJsonEnvelope(): void
    {
        file_put_contents($this->tempDir . '/ClassA.php', '<?php namespace Test; class ClassA {}');

        $tester = $this->createCommandTester();
        $exit = $tester->execute([
            'paths' => [$this->tempDir],
            '--direction' => 'bogus',
            '--format' => 'json',
        ]);

        self::assertSame(3, $exit);
        $decoded = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('error', $decoded);
        self::assertStringContainsString('Unknown direction "bogus"', $decoded['error']);
        self::assertSame(3, $decoded['exit_code']);
    }

    #[Test]
    public function itRefusesAnUnwritableOutputPathBeforeAnalysis(): void
    {
        file_put_contents($this->tempDir . '/ClassA.php', '<?php namespace Test; class ClassA {}');
        $unwritableDir = $this->tempDir . '/locked';
        mkdir($unwritableDir, 0o555);

        $analyzer = new CountingDependencyGraphAnalyzer($this->createAnalyzer());
        $tester = $this->createCommandTesterWithAnalyzer($analyzer);
        $exit = $tester->execute(
            ['paths' => [$this->tempDir], '--output' => $unwritableDir . '/graph.dot'],
            ['capture_stderr_separately' => true],
        );

        self::assertSame(3, $exit);
        self::assertSame(0, $analyzer->calls);

        chmod($unwritableDir, 0o755);
    }

    /**
     * Recursively remove a directory.
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff((scandir($dir) !== false ? scandir($dir) : []), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function createAnalyzer(): DependencyGraphAnalyzer
    {
        return new DependencyGraphAnalyzer(
            new FinderFileDiscovery([]),
            new PhpFileParser(),
            new DependencyVisitor(new DependencyResolver()),
            new DependencyGraphBuilder(),
            new DeclarationRegistrarFactory(),
        );
    }

    private function createCommandTester(): CommandTester
    {
        return $this->createCommandTesterWithAnalyzer($this->createAnalyzer());
    }

    private function createCommandTesterWithAnalyzer(DependencyGraphAnalyzerInterface $analyzer): CommandTester
    {
        $command = new GraphExportCommand(
            $analyzer,
            new DependencyGraphProjector(),
            new ErrorStream(),
            new RefusalPresenter(new ErrorStream()),
            new NullLogger(),
        );
        $application = new Application();
        $application->addCommand($command);

        return new CommandTester($command);
    }
}

/**
 * Counts `analyze()` calls without changing what it returns — the DoD's
 * evidence for "refused before analysis runs" is a call count, not a timing
 * comparison (`01-refusal-packages.md`).
 *
 * @internal
 */
final class CountingDependencyGraphAnalyzer implements DependencyGraphAnalyzerInterface
{
    public int $calls = 0;

    public function __construct(private readonly DependencyGraphAnalyzerInterface $delegate) {}

    public function analyze(array $paths, AbsolutePath $projectRoot): DependencyGraphAnalysisResult
    {
        ++$this->calls;

        return $this->delegate->analyze($paths, $projectRoot);
    }
}
