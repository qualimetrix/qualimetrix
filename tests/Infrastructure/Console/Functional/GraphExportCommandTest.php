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
use Qualimetrix\Analysis\Run\Discovery\FinderFileDiscovery;
use Qualimetrix\Analysis\Run\Pipeline\DependencyGraphAnalyzer;
use Qualimetrix\Infrastructure\Ast\PhpFileParser;
use Qualimetrix\Infrastructure\Console\Command\GraphExportCommand;
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
        $this->tempDir = sys_get_temp_dir() . '/qmx-test-' . uniqid();
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
        );
    }

    private function createCommandTester(): CommandTester
    {
        $command = new GraphExportCommand($this->createAnalyzer(), new DependencyGraphProjector(), new NullLogger());
        $application = new Application();
        $application->addCommand($command);

        return new CommandTester($command);
    }
}
