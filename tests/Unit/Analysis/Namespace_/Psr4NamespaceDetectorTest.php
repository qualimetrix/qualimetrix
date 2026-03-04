<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Analysis\Namespace_;

use AiMessDetector\Analysis\Namespace_\Psr4NamespaceDetector;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversClass(Psr4NamespaceDetector::class)]
final class Psr4NamespaceDetectorTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = sys_get_temp_dir() . '/aimd-psr4-test-' . uniqid();
        mkdir($this->fixturesDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->fixturesDir);
    }

    #[Test]
    public function itDetectsNamespaceFromPsr4Mapping(): void
    {
        $this->createStructure([
            'composer.json' => json_encode([
                'autoload' => [
                    'psr-4' => [
                        'App\\' => 'src/',
                    ],
                ],
            ], \JSON_THROW_ON_ERROR),
            'src/Service/UserService.php' => '<?php namespace App\\Service; class UserService {}',
        ]);

        $detector = new Psr4NamespaceDetector($this->fixturesDir . '/composer.json');
        $file = new SplFileInfo($this->fixturesDir . '/src/Service/UserService.php');

        self::assertSame('App\\Service', $detector->detect($file));
    }

    #[Test]
    public function itDetectsRootNamespace(): void
    {
        $this->createStructure([
            'composer.json' => json_encode([
                'autoload' => [
                    'psr-4' => [
                        'App\\' => 'src/',
                    ],
                ],
            ], \JSON_THROW_ON_ERROR),
            'src/Application.php' => '<?php namespace App; class Application {}',
        ]);

        $detector = new Psr4NamespaceDetector($this->fixturesDir . '/composer.json');
        $file = new SplFileInfo($this->fixturesDir . '/src/Application.php');

        self::assertSame('App', $detector->detect($file));
    }

    #[Test]
    public function itHandlesNestedNamespaces(): void
    {
        $this->createStructure([
            'composer.json' => json_encode([
                'autoload' => [
                    'psr-4' => [
                        'MyApp\\' => 'src/',
                    ],
                ],
            ], \JSON_THROW_ON_ERROR),
            'src/Domain/User/Repository/UserRepository.php' => '<?php namespace MyApp\\Domain\\User\\Repository; class UserRepository {}',
        ]);

        $detector = new Psr4NamespaceDetector($this->fixturesDir . '/composer.json');
        $file = new SplFileInfo($this->fixturesDir . '/src/Domain/User/Repository/UserRepository.php');

        self::assertSame('MyApp\\Domain\\User\\Repository', $detector->detect($file));
    }

    #[Test]
    public function itHandlesAutoloadDev(): void
    {
        $this->createStructure([
            'composer.json' => json_encode([
                'autoload' => [
                    'psr-4' => [
                        'App\\' => 'src/',
                    ],
                ],
                'autoload-dev' => [
                    'psr-4' => [
                        'App\\Tests\\' => 'tests/',
                    ],
                ],
            ], \JSON_THROW_ON_ERROR),
            'tests/Unit/ServiceTest.php' => '<?php namespace App\\Tests\\Unit; class ServiceTest {}',
        ]);

        $detector = new Psr4NamespaceDetector($this->fixturesDir . '/composer.json');
        $file = new SplFileInfo($this->fixturesDir . '/tests/Unit/ServiceTest.php');

        self::assertSame('App\\Tests\\Unit', $detector->detect($file));
    }

    #[Test]
    public function itPrioritizesMoreSpecificPaths(): void
    {
        $this->createStructure([
            'composer.json' => json_encode([
                'autoload' => [
                    'psr-4' => [
                        'App\\' => 'src/',
                        'App\\Core\\' => 'src/Core/',
                    ],
                ],
            ], \JSON_THROW_ON_ERROR),
            'src/Core/Entity.php' => '<?php namespace App\\Core; class Entity {}',
        ]);

        $detector = new Psr4NamespaceDetector($this->fixturesDir . '/composer.json');
        $file = new SplFileInfo($this->fixturesDir . '/src/Core/Entity.php');

        self::assertSame('App\\Core', $detector->detect($file));
    }

    #[Test]
    public function itReturnsEmptyStringForUnmappedFile(): void
    {
        $this->createStructure([
            'composer.json' => json_encode([
                'autoload' => [
                    'psr-4' => [
                        'App\\' => 'src/',
                    ],
                ],
            ], \JSON_THROW_ON_ERROR),
            'lib/Legacy.php' => '<?php class Legacy {}',
        ]);

        $detector = new Psr4NamespaceDetector($this->fixturesDir . '/composer.json');
        $file = new SplFileInfo($this->fixturesDir . '/lib/Legacy.php');

        self::assertSame('', $detector->detect($file));
    }

    #[Test]
    public function itReturnsEmptyStringForNonExistentComposerJson(): void
    {
        $this->createStructure([
            'src/Test.php' => '<?php namespace App; class Test {}',
        ]);

        $detector = new Psr4NamespaceDetector($this->fixturesDir . '/composer.json');
        $file = new SplFileInfo($this->fixturesDir . '/src/Test.php');

        self::assertSame('', $detector->detect($file));
    }

    #[Test]
    public function itReturnsEmptyStringForFileWithInvalidPath(): void
    {
        $this->createStructure([
            'composer.json' => json_encode([
                'autoload' => [
                    'psr-4' => [
                        'App\\' => 'src/',
                    ],
                ],
            ], \JSON_THROW_ON_ERROR),
        ]);

        $detector = new Psr4NamespaceDetector($this->fixturesDir . '/composer.json');
        $file = new SplFileInfo('/non/existent/file.php');

        self::assertSame('', $detector->detect($file));
    }

    /**
     * @param array<string, string> $structure
     */
    private function createStructure(array $structure): void
    {
        foreach ($structure as $path => $content) {
            $fullPath = $this->fixturesDir . '/' . $path;
            $dir = \dirname($fullPath);

            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents($fullPath, $content);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
