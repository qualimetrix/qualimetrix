<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Functional\Console\Command;

use AiMessDetector\Infrastructure\Console\Command\CheckCommand;
use AiMessDetector\Infrastructure\DependencyInjection\ContainerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(CheckCommand::class)]
final class CheckCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        // Create temporary directory for test files
        $this->tempDir = sys_get_temp_dir() . '/aimd-test-' . uniqid();
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
    public function itAnalyzesSimplePhpFile(): void
    {
        // Create a simple PHP file
        $testFile = $this->tempDir . '/SimpleClass.php';
        file_put_contents($testFile, '<?php class SimpleClass { public function method(): int { return 42; } }');

        // Create command from DI container
        $commandTester = $this->createCommandTester();
        $commandTester->execute([
            'paths' => [$this->tempDir],
            '--no-progress' => true,
        ]);

        // Assert success (exit code 0 - no violations)
        $this->assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        // Text format shows "0 error(s), 0 warning(s) in X file(s)"
        $this->assertStringContainsString('0 error(s), 0 warning(s)', $output);
    }

    #[Test]
    public function itDetectsComplexityViolations(): void
    {
        // Create a PHP file with high complexity
        $complexCode = '<?php
class ComplexClass {
    public function complexMethod($a, $b, $c) {
        if ($a > 0) {
            if ($b > 0) {
                if ($c > 0) {
                    for ($i = 0; $i < 10; $i++) {
                        if ($i % 2 == 0) {
                            echo "even";
                        } else {
                            echo "odd";
                        }
                    }
                } else {
                    echo "c negative";
                }
            } else {
                echo "b negative";
            }
        } else {
            echo "a negative";
        }
        return $a + $b + $c;
    }
}';
        $testFile = $this->tempDir . '/ComplexClass.php';
        file_put_contents($testFile, $complexCode);

        // Create command from DI container
        $commandTester = $this->createCommandTester();
        $commandTester->execute([
            'paths' => [$this->tempDir],
            '--no-progress' => true,
        ]);

        // Assert warnings (exit code 1 or 2 - has warnings or errors)
        $this->assertContains($commandTester->getStatusCode(), [1, 2]);
        $output = $commandTester->getDisplay();
        // Output format: "X error(s), Y warning(s) in Z file(s)"
        $this->assertMatchesRegularExpression('/\d+ (error|warning)\(s\)/', $output);
        $this->assertStringContainsString('ComplexClass', $output);
    }

    #[Test]
    public function itSupportsJsonFormat(): void
    {
        // Create a simple PHP file
        $testFile = $this->tempDir . '/SimpleClass.php';
        file_put_contents($testFile, '<?php class SimpleClass {}');

        // Create command from DI container
        $commandTester = $this->createCommandTester();
        $commandTester->execute([
            'paths' => [$this->tempDir],
            '--format' => 'json',
            '--no-progress' => true,
        ]);

        // Assert success
        $this->assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();

        // Verify JSON output
        $json = json_decode($output, true);
        $this->assertIsArray($json);
        // JSON format uses 'files' key for violations grouped by file, and 'summary' for stats
        $this->assertArrayHasKey('files', $json);
        $this->assertArrayHasKey('summary', $json);
    }

    #[Test]
    public function itHandlesNonExistentPath(): void
    {
        // Try to analyze non-existent path
        $nonExistentPath = $this->tempDir . '/non-existent';

        // Create command from DI container
        $commandTester = $this->createCommandTester();
        $commandTester->execute([
            'paths' => [$nonExistentPath],
            '--no-progress' => true,
        ]);

        // Assert success (no files found, but not an error)
        $this->assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        // Output shows "0 error(s), 0 warning(s) in 0 file(s)"
        $this->assertStringContainsString('0 error(s), 0 warning(s)', $output);
    }

    #[Test]
    public function itRespectsExcludeOption(): void
    {
        // Create directory structure
        $srcDir = $this->tempDir . '/src';
        $vendorDir = $this->tempDir . '/vendor';
        mkdir($srcDir);
        mkdir($vendorDir);

        // Create files in both directories
        file_put_contents($srcDir . '/Class.php', '<?php class MyClass {}');
        file_put_contents($vendorDir . '/Dependency.php', '<?php class Dependency {}');

        // Create command from DI container
        $commandTester = $this->createCommandTester();
        $commandTester->execute([
            'paths' => [$this->tempDir],
            '--exclude' => ['vendor'],
            '--no-progress' => true,
        ]);

        // Assert success
        $this->assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        // Output shows "0 error(s), 0 warning(s) in 1 file(s)"
        $this->assertStringContainsString('0 error(s), 0 warning(s)', $output);
        // Files analyzed should only be from src/
        $this->assertStringContainsString('1 file', $output);
    }

    #[Test]
    public function itSupportsBaselineGeneration(): void
    {
        // Create a PHP file with violation
        $complexCode = '<?php
class ComplexClass {
    public function complexMethod($a) {
        if ($a > 0) {
            if ($a > 1) {
                if ($a > 2) {
                    if ($a > 3) {
                        if ($a > 4) {
                            return "very high";
                        }
                    }
                }
            }
        }
        return "low";
    }
}';
        $testFile = $this->tempDir . '/ComplexClass.php';
        file_put_contents($testFile, $complexCode);

        $baselinePath = $this->tempDir . '/baseline.json';

        // Create command from DI container
        $commandTester = $this->createCommandTester();
        $commandTester->execute([
            'paths' => [$this->tempDir],
            '--generate-baseline' => $baselinePath,
            '--no-progress' => true,
        ]);

        // Assert baseline was generated
        $this->assertFileExists($baselinePath);
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Baseline', $output);
        $this->assertStringContainsString('written to', $output);
    }

    #[Test]
    public function itUsesBaseline(): void
    {
        // Create a PHP file with violation
        $complexCode = '<?php
class ComplexClass {
    public function complexMethod($a) {
        if ($a > 0) {
            if ($a > 1) {
                if ($a > 2) {
                    if ($a > 3) {
                        if ($a > 4) {
                            return "very high";
                        }
                    }
                }
            }
        }
        return "low";
    }
}';
        $testFile = $this->tempDir . '/ComplexClass.php';
        file_put_contents($testFile, $complexCode);

        $baselinePath = $this->tempDir . '/baseline.json';

        // First, generate baseline
        $commandTester = $this->createCommandTester();
        $commandTester->execute([
            'paths' => [$this->tempDir],
            '--generate-baseline' => $baselinePath,
            '--no-progress' => true,
        ]);

        // Now analyze with baseline - should show no new violations
        $commandTester2 = $this->createCommandTester();
        $commandTester2->execute([
            'paths' => [$this->tempDir],
            '--baseline' => $baselinePath,
            '--no-progress' => true,
        ]);

        // Assert no violations (all in baseline)
        $this->assertSame(0, $commandTester2->getStatusCode());
        $output = $commandTester2->getDisplay();
        // Output shows "0 error(s), 0 warning(s)" when all violations are in baseline
        $this->assertStringContainsString('0 error(s), 0 warning(s)', $output);
    }

    /**
     * Creates a CommandTester for CheckCommand from DI container.
     */
    private function createCommandTester(): CommandTester
    {
        $containerFactory = new ContainerFactory();
        $container = $containerFactory->create();

        /** @var CheckCommand $command */
        $command = $container->get(CheckCommand::class);

        $application = new Application();
        $application->addCommand($command);

        return new CommandTester($command);
    }

    /**
     * Recursively remove a directory.
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
