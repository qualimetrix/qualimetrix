<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

        $this->tempDir = sys_get_temp_dir() . '/qmx-path-input-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0777, true);
        mkdir($this->tempDir . '/src', 0777, true);
        file_put_contents(
            $this->tempDir . '/src/SimpleClass.php',
            '<?php class SimpleClass { public function noop(): int { return 1; } }',
        );
    }

    protected function tearDown(): void
    {
        // Redundant, and deliberately kept: PHPUnit restores the working
        // directory itself after tearDown(), on a failed case too, so nothing
        // here or in any later case depends on this line. It said the opposite
        // until someone measured the runner -- do not copy it elsewhere as a
        // leak guard, because there is no leak to guard.
        @chdir($this->originalCwd);

        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    #[Test]
    public function itAcceptsAbsolutePath(): void
    {
        chdir($this->tempDir);
        $tester = $this->createCommandTester();
        $tester->execute([
            'paths' => [$this->tempDir . '/src'],
            '--format' => 'text',
            '--no-progress' => true,
            '--disable-rule' => ['computed', 'health.*', 'architecture.layer-violation', 'coupling.class-rank'],
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
            '--disable-rule' => ['computed', 'health.*', 'architecture.layer-violation', 'coupling.class-rank'],
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
            '--disable-rule' => ['computed', 'health.*', 'architecture.layer-violation', 'coupling.class-rank'],
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
            '--disable-rule' => ['computed', 'health.*', 'architecture.layer-violation', 'coupling.class-rank'],
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('1 file', $tester->getDisplay());
    }

    #[Test]
    public function itAcceptsSymlinkedPath(): void
    {
        chdir($this->tempDir);
        $link = $this->tempDir . '/link-to-src';
        symlink($this->tempDir . '/src', $link);

        $tester = $this->createCommandTester();
        $tester->execute([
            'paths' => [$link],
            '--format' => 'text',
            '--no-progress' => true,
            '--disable-rule' => ['computed', 'health.*', 'architecture.layer-violation', 'coupling.class-rank'],
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('1 file', $tester->getDisplay());
    }

    #[Test]
    public function itRejectsNonExistentPath(): void
    {
        chdir($this->tempDir);
        $tester = $this->createCommandTester();
        $tester->execute([
            'paths' => [$this->tempDir . '/no-such-directory'],
            '--format' => 'text',
            '--no-progress' => true,
        ], ['capture_stderr_separately' => true]);

        // CheckCommand::EXIT_CONFIG_ERROR
        self::assertSame(3, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('does not exist', $tester->getErrorOutput());
    }

    #[Test]
    public function itAcceptsEverySpellingOfTheProjectRoot(): void
    {
        chdir($this->tempDir);

        foreach (['.', './', $this->tempDir] as $path) {
            $tester = $this->createCommandTester();
            $tester->execute([
                'paths' => [$path],
                '--format' => 'text',
                '--no-progress' => true,
                '--disable-rule' => ['computed', 'health.*', 'architecture.layer-violation', 'coupling.class-rank'],
            ]);

            self::assertSame(0, $tester->getStatusCode(), $path);
            self::assertStringContainsString('1 file', $tester->getDisplay());
        }

        file_put_contents($this->tempDir . '/qmx.yaml', "paths: [.]\n");
        $fromConfig = $this->createCommandTester();
        $fromConfig->execute([
            '--format' => 'text',
            '--no-progress' => true,
            '--disable-rule' => ['computed', 'health.*', 'architecture.layer-violation', 'coupling.class-rank'],
        ]);
        self::assertSame(0, $fromConfig->getStatusCode());
        self::assertStringContainsString('1 file', $fromConfig->getDisplay());

        unlink($this->tempDir . '/qmx.yaml');
        $fallback = $this->createCommandTester();
        $fallback->execute([
            '--format' => 'text',
            '--no-progress' => true,
            '--disable-rule' => ['computed', 'health.*', 'architecture.layer-violation', 'coupling.class-rank'],
        ]);
        self::assertSame(0, $fallback->getStatusCode());
        self::assertStringContainsString('1 file', $fallback->getDisplay());
    }

    #[Test]
    public function itRefusesAnExistingCliPathOutsideTheProjectRoot(): void
    {
        $outside = $this->tempDir . '-outside';
        mkdir($outside);
        file_put_contents($outside . '/Other.php', '<?php class Other {}');
        chdir($this->tempDir);

        try {
            $tester = $this->createCommandTester();
            $tester->execute([
                'paths' => ['../' . basename($outside)],
                '--format' => 'json',
                '--no-progress' => true,
            ], ['capture_stderr_separately' => true]);

            self::assertSame(3, $tester->getStatusCode());
            $envelope = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($envelope);
            self::assertSame('cli', $envelope['source'][0]['kind']);
            self::assertStringContainsString($this->tempDir, $envelope['error']);
            self::assertStringContainsString('--working-dir', $envelope['error']);
        } finally {
            unlink($outside . '/Other.php');
            rmdir($outside);
        }
    }

    #[Test]
    public function itRefusesAnExistingConfiguredPathOutsideTheProjectRoot(): void
    {
        $outside = $this->tempDir . '-outside';
        mkdir($outside);
        file_put_contents($outside . '/Other.php', '<?php class Other {}');
        file_put_contents($this->tempDir . '/qmx.yaml', 'paths: ["../' . basename($outside) . '"]' . "\n");
        chdir($this->tempDir);

        try {
            $tester = $this->createCommandTester();
            $tester->execute(['--format' => 'json', '--no-progress' => true], ['capture_stderr_separately' => true]);

            self::assertSame(3, $tester->getStatusCode());
            $envelope = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($envelope);
            self::assertSame('file', $envelope['source'][0]['kind']);
            self::assertStringContainsString($this->tempDir, $envelope['error']);
            self::assertStringContainsString('--working-dir', $envelope['error']);
        } finally {
            unlink($outside . '/Other.php');
            rmdir($outside);
        }
    }

    /**
     * The refusal names the layer that wrote the missing path, not the command
     * line whatever wrote it: a path from `qmx.yaml` or a preset is fixed there.
     *
     * @return iterable<string, array{array<string, string>, array<string, mixed>, array{kind: string, name: non-empty-string}}>
     */
    public static function provideWritersOfAMissingPath(): iterable
    {
        yield 'command line' => [[], ['paths' => ['no-such-directory']], ['kind' => 'cli', 'name' => 'paths']];
        yield 'configuration file' => [['qmx.yaml' => "paths: [no-such-directory]\n"], [], ['kind' => 'file', 'name' => 'qmx.yaml']];
        yield 'preset' => [['team.yaml' => "paths: [no-such-directory]\n"], ['--preset' => ['./team.yaml']], ['kind' => 'preset', 'name' => './team.yaml']];
    }

    /**
     * @param array<string, string> $files
     * @param array<string, mixed> $arguments
     * @param array{kind: string, name: non-empty-string} $source
     */
    #[Test]
    #[DataProvider('provideWritersOfAMissingPath')]
    public function itNamesTheLayerThatWroteAMissingPath(array $files, array $arguments, array $source): void
    {
        foreach ($files as $name => $content) {
            file_put_contents($this->tempDir . '/' . $name, $content);
        }
        chdir($this->tempDir);

        $tester = $this->createCommandTester();
        $tester->execute($arguments + ['--format' => 'json', '--no-progress' => true], ['capture_stderr_separately' => true]);

        self::assertSame(3, $tester->getStatusCode());
        $envelope = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($envelope);
        self::assertIsString($envelope['error']);
        self::assertStringContainsString('does not exist', $envelope['error']);
        self::assertIsArray($envelope['source']);
        self::assertCount(1, $envelope['source']);
        self::assertSame($source['kind'], $envelope['source'][0]['kind']);
        // A discovered configuration file is named by its absolute path.
        self::assertStringEndsWith($source['name'], $envelope['source'][0]['name']);
    }

    #[Test]
    public function itRefusesANamedRootThatIsAVendorDirectoryBeforeAnyReport(): void
    {
        mkdir($this->tempDir . '/lib/vendor', 0777, true);
        file_put_contents($this->tempDir . '/lib/vendor/Hidden.php', '<?php class Hidden {}');
        chdir($this->tempDir);

        $tester = $this->createCommandTester();
        $tester->execute([
            'paths' => ['lib/vendor'],
            '--format' => 'json',
            '--no-progress' => true,
        ], ['capture_stderr_separately' => true]);

        self::assertSame(3, $tester->getStatusCode());
        // The refusal envelope is the only stdout document: no report was started.
        $envelope = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($envelope);
        self::assertSame(['error', 'exit_code', 'position', 'source'], array_keys($envelope));
        self::assertIsString($envelope['error']);
        self::assertStringContainsString('"lib/vendor" is a vendor, node_modules or .git directory', $envelope['error']);
    }

    #[Test]
    public function itAnalysesANamedRootThatContainsAVendorDirectory(): void
    {
        mkdir($this->tempDir . '/src/vendor', 0777, true);
        file_put_contents($this->tempDir . '/src/vendor/Hidden.php', '<?php class Hidden {}');
        chdir($this->tempDir);

        $tester = $this->createCommandTester();
        $tester->execute([
            'paths' => ['src'],
            '--format' => 'text',
            '--no-progress' => true,
            '--disable-rule' => ['computed', 'health.*', 'architecture.layer-violation', 'coupling.class-rank'],
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('1 file', $tester->getDisplay());
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
