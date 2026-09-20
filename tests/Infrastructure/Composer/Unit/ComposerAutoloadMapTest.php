<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Composer\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Composer\ComposerAutoloadMap;
use RuntimeException;

#[CoversClass(ComposerAutoloadMap::class)]
final class ComposerAutoloadMapTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/qmx_autoload_map_' . bin2hex(random_bytes(6));

        if (!mkdir($this->root . '/src', 0o777, true) && !is_dir($this->root . '/src')) {
            throw new RuntimeException('Cannot create the fixture project');
        }
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);
    }

    #[Test]
    public function itSaysSoWhenTheRunHasNoInstallToRead(): void
    {
        $map = new ComposerAutoloadMap();
        $map->pointAt($this->root . '/nowhere', [$this->root . '/nowhere']);

        self::assertFalse($map->isConfigured());
        self::assertNull($map->fileFor('Anything\\At\\All'));
    }

    #[Test]
    public function itPlacesAClassFromTheRootPackagesOwnPsr4(): void
    {
        $this->writeManifest(['autoload' => ['psr-4' => ['App\\' => 'src/']]]);
        $file = $this->write('src/Thing.php', "<?php\n\nnamespace App;\n\nclass Thing {}\n");

        $map = new ComposerAutoloadMap();
        $map->pointAt($this->root, [$this->root . '/src']);

        self::assertTrue($map->isConfigured());
        self::assertSame(realpath($file), realpath((string) $map->fileFor('App\\Thing')));
    }

    #[Test]
    public function itPlacesAClassFromAnInstalledPackage(): void
    {
        $this->writeManifest(['autoload' => ['psr-4' => ['App\\' => 'src/']]]);
        $file = $this->write('vendor/acme/lib/src/Widget.php', "<?php\n\nnamespace Acme;\n\nclass Widget {}\n");
        $this->writeInstalled([[
            'name' => 'acme/lib',
            'autoload' => ['psr-4' => ['Acme\\' => 'src/']],
            'install-path' => '../acme/lib',
        ]]);

        $map = new ComposerAutoloadMap();
        $map->pointAt($this->root, [$this->root . '/src']);

        self::assertSame(realpath($file), realpath((string) $map->fileFor('Acme\\Widget')));
    }

    /**
     * 25 of the 130 packages in this repository's benchmark install declare a
     * classmap and no psr-4 at all, `phpunit/phpunit` among them — and
     * `PHPUnit\Framework\TestCase` is one of the two classes the autoload-based
     * resolution this map replaced ever usefully placed. A psr-4-only map would
     * have dropped half of the only measured benefit.
     *
     * The generated classmap is PHP, so it is parsed rather than included:
     * including it would execute a file from the tree under measurement.
     */
    #[Test]
    public function itPlacesAClassDeclaredOnlyByTheGeneratedClassmap(): void
    {
        $this->writeManifest(['autoload' => ['psr-4' => ['App\\' => 'src/']]]);
        $file = $this->write('vendor/acme/tool/src/Deep/Runner.php', "<?php\n\nnamespace Acme\\Tool;\n\nclass Runner {}\n");
        $this->writeInstalled([[
            'name' => 'acme/tool',
            'autoload' => ['classmap' => ['src/']],
            'install-path' => '../acme/tool',
        ]]);
        // Values are `$vendorDir . '/…'` concatenations, exactly as composer
        // writes them, so the reader has to substitute the variables.
        $this->write('vendor/composer/autoload_classmap.php', <<<'PHP'
            <?php

            $vendorDir = dirname(__DIR__);
            $baseDir = dirname($vendorDir);

            return array(
                'Acme\\Tool\\Runner' => $vendorDir . '/acme/tool/src/Deep/Runner.php',
            );
            PHP);

        $map = new ComposerAutoloadMap();
        $map->pointAt($this->root, [$this->root . '/src']);

        self::assertSame(realpath($file), realpath((string) $map->fileFor('Acme\\Tool\\Runner')));
    }

    #[Test]
    public function itReadsTheVendorDirectoryTheProjectDeclares(): void
    {
        $this->writeManifest([
            'autoload' => ['psr-4' => ['App\\' => 'src/']],
            'config' => ['vendor-dir' => 'lib'],
        ]);
        $file = $this->write('lib/acme/pkg/src/Moved.php', "<?php\n\nnamespace Acme;\n\nclass Moved {}\n");
        $this->write('lib/composer/installed.json', (string) json_encode([
            'packages' => [[
                'name' => 'acme/pkg',
                'autoload' => ['psr-4' => ['Acme\\' => 'src/']],
                'install-path' => '../acme/pkg',
            ]],
        ], \JSON_PRETTY_PRINT));

        $map = new ComposerAutoloadMap();
        $map->pointAt($this->root, [$this->root . '/src']);

        self::assertSame(realpath($file), realpath((string) $map->fileFor('Acme\\Moved')));
    }

    /**
     * A library analysed from inside somebody else's install carries a
     * `composer.json` describing only itself. Anchoring on it alone loses the
     * dependencies: measured on one such package, 8 of 22 parents reached a
     * root that way against 20 when the enclosing install's owner is read too.
     */
    #[Test]
    public function itReadsTheInstallTheAnalysedPathSitsInside(): void
    {
        $this->writeManifest(['autoload' => ['psr-4' => ['App\\' => 'src/']]]);
        $this->write('vendor/acme/lib/composer.json', (string) json_encode(['name' => 'acme/lib', 'autoload' => ['psr-4' => ['Acme\\' => 'src/']]]));
        $this->write('vendor/acme/lib/src/Own.php', "<?php\n\nnamespace Acme;\n\nclass Own {}\n");
        $sibling = $this->write('vendor/other/dep/src/Needed.php', "<?php\n\nnamespace Other;\n\nclass Needed {}\n");
        $this->writeInstalled([
            ['name' => 'acme/lib', 'autoload' => ['psr-4' => ['Acme\\' => 'src/']], 'install-path' => '../acme/lib'],
            ['name' => 'other/dep', 'autoload' => ['psr-4' => ['Other\\' => 'src/']], 'install-path' => '../other/dep'],
        ]);

        $map = new ComposerAutoloadMap();
        $map->pointAt($this->root, [$this->root . '/vendor/acme/lib/src']);

        // The sibling is invisible from the analysed package's own manifest.
        self::assertSame(realpath($sibling), realpath((string) $map->fileFor('Other\\Needed')));
    }

    /**
     * The paths in an install are chosen by the tree being analysed: an
     * `install-path` may contain `..`, and a generated classmap entry is
     * whatever was written into it. A map that handed those back would have the
     * reader open a file anywhere on the machine.
     */
    #[Test]
    public function itRefusesAPathThatEscapesTheProject(): void
    {
        $outside = \dirname($this->root) . '/qmx_outside_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($outside, "<?php\n\nnamespace Escaped;\n\nclass Secret {}\n");

        $this->writeManifest(['autoload' => ['psr-4' => ['App\\' => 'src/']]]);
        $this->write('vendor/composer/autoload_classmap.php', \sprintf(
            "<?php\n\n\$vendorDir = dirname(__DIR__);\n\$baseDir = dirname(\$vendorDir);\n\nreturn array(\n    'Escaped\\\\Secret' => '%s',\n);\n",
            $outside,
        ));

        try {
            $map = new ComposerAutoloadMap();
            $map->pointAt($this->root, [$this->root . '/src']);

            self::assertNull($map->fileFor('Escaped\\Secret'));
        } finally {
            @unlink($outside);
        }
    }

    #[Test]
    public function itRefusesAPsr4TargetThatClimbsOutOfTheProject(): void
    {
        $outsideDirectory = \dirname($this->root) . '/qmx_outside_' . bin2hex(random_bytes(6));
        mkdir($outsideDirectory);
        file_put_contents($outsideDirectory . '/Secret.php', "<?php\n\nnamespace Escaped;\n\nclass Secret {}\n");

        $this->writeManifest(['autoload' => ['psr-4' => ['Escaped\\' => '../' . basename($outsideDirectory) . '/']]]);

        try {
            $map = new ComposerAutoloadMap();
            $map->pointAt($this->root, [$this->root . '/src']);

            self::assertNull($map->fileFor('Escaped\\Secret'));
        } finally {
            @unlink($outsideDirectory . '/Secret.php');
            @rmdir($outsideDirectory);
        }
    }

    /**
     * Parsing costs a multiple of the file's size and the analysed project
     * chooses that size. A classmap past the cap is read as absent rather than
     * as a reason to exhaust the process.
     */
    #[Test]
    public function itDeclinesAClassmapTooLargeToParse(): void
    {
        $this->writeManifest(['autoload' => ['psr-4' => ['App\\' => 'src/']]]);
        $file = $this->write('vendor/acme/big/src/Huge.php', "<?php\n\nnamespace Acme;\n\nclass Huge {}\n");

        $padding = str_repeat("// pad\n", 1_300_000);
        $this->write('vendor/composer/autoload_classmap.php', "<?php\n\n" . $padding . "\n\$vendorDir = dirname(__DIR__);\n\nreturn array(\n    'Acme\\\\Huge' => \$vendorDir . '/acme/big/src/Huge.php',\n);\n");

        $map = new ComposerAutoloadMap();
        $map->pointAt($this->root, [$this->root . '/src']);

        self::assertGreaterThan(8 * 1024 * 1024, (int) filesize($this->root . '/vendor/composer/autoload_classmap.php'));
        self::assertNull($map->fileFor('Acme\\Huge'), 'An oversized classmap was parsed anyway');
        self::assertFileExists($file);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function writeManifest(array $manifest): void
    {
        $this->write('composer.json', (string) json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param list<array<string, mixed>> $packages
     */
    private function writeInstalled(array $packages): void
    {
        $this->write('vendor/composer/installed.json', (string) json_encode(['packages' => $packages], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
    }

    private function write(string $relative, string $contents): string
    {
        $path = $this->root . '/' . $relative;
        $directory = \dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create ' . $directory);
        }

        file_put_contents($path, $contents);

        return $path;
    }

    private static function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);

        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }

        rmdir($path);
    }
}
