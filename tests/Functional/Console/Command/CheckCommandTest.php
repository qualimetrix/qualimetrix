<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Functional\Console\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(CheckCommand::class)]
final class CheckCommandTest extends TestCase
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
    public function itAnalyzesSimplePhpFile(): void
    {
        // Create a simple PHP file
        $testFile = $this->tempDir . '/SimpleClass.php';
        file_put_contents($testFile, '<?php class SimpleClass { public function method(): int { return 42; } }');

        // Create command from DI container
        $commandTester = $this->createCommandTester();
        $commandTester->execute([
            'paths' => [$this->tempDir],
            '--format' => 'text',
            '--no-progress' => true,
            '--disable-rule' => ['computed.health'],
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
            '--format' => 'text',
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
            '--disable-rule' => ['computed.health'],
        ]);

        // Assert success
        $this->assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();

        // Verify JSON output
        $json = json_decode($output, true);
        $this->assertIsArray($json);
        // JSON format uses summary structure with meta, health, worst offenders, and violations
        $this->assertArrayHasKey('meta', $json);
        $this->assertArrayHasKey('summary', $json);
        $this->assertArrayHasKey('violations', $json);
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
            '--format' => 'text',
            '--no-progress' => true,
        ]);

        // Assert config/input error (exit code 3)
        $this->assertSame(3, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('does not exist', $output);
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
            '--format' => 'text',
            '--no-progress' => true,
            '--disable-rule' => ['computed.health'],
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
        $output = $commandTester2->getDisplay();
        $this->assertSame(0, $commandTester2->getStatusCode(), "Baseline should suppress all violations. Output:\n" . $output);
    }

    #[Test]
    public function itSupportsCheckstyleFormat(): void
    {
        $testFile = $this->tempDir . '/SimpleClass.php';
        file_put_contents($testFile, '<?php class SimpleClass { public function method(): int { return 42; } }');

        $commandTester = $this->createCommandTester();
        $commandTester->execute([
            'paths' => [$this->tempDir],
            '--format' => 'checkstyle',
            '--no-progress' => true,
            '--disable-rule' => ['computed.health'],
        ]);

        $this->assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('<?xml', $output);
        $this->assertStringContainsString('<checkstyle', $output);
    }

    #[Test]
    public function itSupportsSarifFormat(): void
    {
        $testFile = $this->tempDir . '/SimpleClass.php';
        file_put_contents($testFile, '<?php class SimpleClass { public function method(): int { return 42; } }');

        $commandTester = $this->createCommandTester();
        $commandTester->execute([
            'paths' => [$this->tempDir],
            '--format' => 'sarif',
            '--no-progress' => true,
            '--disable-rule' => ['computed.health'],
        ]);

        $this->assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $json = json_decode($output, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('$schema', $json);
        $this->assertSame('2.1.0', $json['version']);
        $this->assertArrayHasKey('runs', $json);
    }

    #[Test]
    public function itSupportsGitlabFormat(): void
    {
        $testFile = $this->tempDir . '/SimpleClass.php';
        file_put_contents($testFile, '<?php class SimpleClass { public function method(): int { return 42; } }');

        $commandTester = $this->createCommandTester();
        $commandTester->execute([
            'paths' => [$this->tempDir],
            '--format' => 'gitlab',
            '--no-progress' => true,
            '--disable-rule' => ['computed.health'],
        ]);

        $this->assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        // GitLab format outputs a JSON array (empty when no violations)
        $json = json_decode($output, true);
        $this->assertIsArray($json);
    }

    #[Test]
    public function itSupportsHealthFormat(): void
    {
        $testFile = $this->tempDir . '/SimpleClass.php';
        file_put_contents($testFile, '<?php class SimpleClass { public function method(): int { return 42; } }');

        $commandTester = $this->createCommandTester();
        $commandTester->execute([
            'paths' => [$this->tempDir],
            '--format' => 'health',
            '--no-progress' => true,
            '--disable-rule' => ['computed.health'],
        ]);

        $this->assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Health Report', $output);
    }

    #[Test]
    public function itSupportsSummaryFormat(): void
    {
        $testFile = $this->tempDir . '/SimpleClass.php';
        file_put_contents($testFile, '<?php class SimpleClass { public function method(): int { return 42; } }');

        $commandTester = $this->createCommandTester();
        $commandTester->execute([
            'paths' => [$this->tempDir],
            '--format' => 'summary',
            '--no-progress' => true,
            '--disable-rule' => ['computed.health'],
        ]);

        $this->assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        // Summary format shows file count and violation summary
        $this->assertStringContainsString('1 file', $output);
    }

    #[Test]
    public function itSupportsGithubActionsFormat(): void
    {
        // GitHub Actions format only produces output when there are violations
        $testFile = $this->tempDir . '/SimpleClass.php';
        file_put_contents($testFile, '<?php class SimpleClass { public function method(): int { return 42; } }');

        $commandTester = $this->createCommandTester();
        $commandTester->execute([
            'paths' => [$this->tempDir],
            '--format' => 'github',
            '--no-progress' => true,
            '--disable-rule' => ['computed.health'],
        ]);

        // No violations -> empty output, exit code 0
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    #[Test]
    public function itSupportsGithubActionsFormatWithViolations(): void
    {
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
                }
            }
        }
        return $a + $b + $c;
    }
}';
        $testFile = $this->tempDir . '/ComplexClass.php';
        file_put_contents($testFile, $complexCode);

        $commandTester = $this->createCommandTester();
        $commandTester->execute([
            'paths' => [$this->tempDir],
            '--format' => 'github',
            '--no-progress' => true,
        ]);

        $this->assertContains($commandTester->getStatusCode(), [1, 2]);
        $output = $commandTester->getDisplay();
        // GitHub Actions format uses ::warning or ::error prefix
        $this->assertMatchesRegularExpression('/::(warning|error)\s/', $output);
    }

    #[Test]
    public function itRunsHealthFormatWithComputedMetrics(): void
    {
        // End-to-end test: full pipeline with health scores computed
        $testFile = $this->tempDir . '/SimpleClass.php';
        file_put_contents($testFile, '<?php
namespace App;

class SimpleClass {
    private int $value;

    public function getValue(): int { return $this->value; }
    public function setValue(int $v): void { $this->value = $v; }
}');

        $commandTester = $this->createCommandTester();
        $commandTester->execute([
            'paths' => [$this->tempDir],
            '--format' => 'health',
            '--no-progress' => true,
            // Do NOT disable computed.health — test full pipeline
        ]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Health Report', $output);
        // Health dimensions should be present
        $this->assertMatchesRegularExpression('/Complexity|Cohesion|Coupling|Maintainability|Overall/i', $output);
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
