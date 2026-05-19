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

/**
 * Pins the CLI-input contract introduced in ADR 0015 Phase 2:
 * raw `paths` arguments flow through {@see \Qualimetrix\Core\Path\PathFactory::fromCliArgument()},
 * so absolute / relative / `./`-prefixed / symlinked forms that point at the
 * same canonical location produce equivalent analyses, and nonexistent paths
 * surface a configuration-error exit code.
 */
#[CoversClass(CheckCommand::class)]
final class CheckCommandPathInputTest extends TestCase
{
    private string $tempDir;

    private string $originalCwd;

    protected function setUp(): void
    {
        $cwd = getcwd();
        self::assertNotFalse($cwd);
        $this->originalCwd = $cwd;

        $this->tempDir = sys_get_temp_dir() . '/qmx-path-input-' . uniqid();
        mkdir($this->tempDir, 0777, true);
        mkdir($this->tempDir . '/src', 0777, true);
        file_put_contents(
            $this->tempDir . '/src/SimpleClass.php',
            '<?php class SimpleClass { public function noop(): int { return 1; } }',
        );
    }

    protected function tearDown(): void
    {
        // chdir() leaks across tests; restore eagerly even if assertions fail.
        @chdir($this->originalCwd);

        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    #[Test]
    public function itAcceptsAbsolutePath(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute([
            'paths' => [$this->tempDir . '/src'],
            '--format' => 'text',
            '--no-progress' => true,
            '--disable-rule' => ['computed.health', 'architecture.layer-violation'],
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('1 file', $tester->getDisplay());
    }

    #[Test]
    public function itAcceptsRelativePath(): void
    {
        chdir($this->tempDir);

        $tester = $this->createCommandTester();
        $tester->execute([
            'paths' => ['src'],
            '--format' => 'text',
            '--no-progress' => true,
            '--disable-rule' => ['computed.health', 'architecture.layer-violation'],
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('1 file', $tester->getDisplay());
    }

    #[Test]
    public function itAcceptsDotSlashPrefixedPath(): void
    {
        chdir($this->tempDir);

        $tester = $this->createCommandTester();
        $tester->execute([
            'paths' => ['./src'],
            '--format' => 'text',
            '--no-progress' => true,
            '--disable-rule' => ['computed.health', 'architecture.layer-violation'],
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('1 file', $tester->getDisplay());
    }

    #[Test]
    public function itAcceptsCurrentDirectoryShorthand(): void
    {
        chdir($this->tempDir . '/src');

        $tester = $this->createCommandTester();
        $tester->execute([
            'paths' => ['.'],
            '--format' => 'text',
            '--no-progress' => true,
            '--disable-rule' => ['computed.health', 'architecture.layer-violation'],
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('1 file', $tester->getDisplay());
    }

    #[Test]
    public function itAcceptsSymlinkedPath(): void
    {
        $link = $this->tempDir . '/link-to-src';
        symlink($this->tempDir . '/src', $link);

        $tester = $this->createCommandTester();
        $tester->execute([
            'paths' => [$link],
            '--format' => 'text',
            '--no-progress' => true,
            '--disable-rule' => ['computed.health', 'architecture.layer-violation'],
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('1 file', $tester->getDisplay());
    }

    #[Test]
    public function itRejectsNonExistentPath(): void
    {
        $tester = $this->createCommandTester();
        $tester->execute([
            'paths' => [$this->tempDir . '/no-such-directory'],
            '--format' => 'text',
            '--no-progress' => true,
        ]);

        // CheckCommand::EXIT_CONFIG_ERROR
        self::assertSame(3, $tester->getStatusCode());
        self::assertStringContainsString('does not exist', $tester->getDisplay());
    }

    private function createCommandTester(): CommandTester
    {
        $container = (new ContainerFactory())->create();

        /** @var CheckCommand $command */
        $command = $container->get(CheckCommand::class);

        $application = new Application();
        $application->addCommand($command);

        return new CommandTester($command);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            rmdir($dir);

            return;
        }

        foreach (array_diff($items, ['.', '..']) as $item) {
            $path = $dir . '/' . $item;
            if (is_link($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        rmdir($dir);
    }
}
