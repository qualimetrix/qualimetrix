<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Analysis\Namespace_;

use AiMessDetector\Analysis\Namespace_\TokenizerNamespaceDetector;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[CoversClass(TokenizerNamespaceDetector::class)]
final class TokenizerNamespaceDetectorTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = sys_get_temp_dir() . '/aimd-tokenizer-test-' . uniqid();
        mkdir($this->fixturesDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->fixturesDir);
    }

    #[Test]
    public function itDetectsSimpleNamespace(): void
    {
        $file = $this->createFile('Test.php', '<?php namespace App;');

        $detector = new TokenizerNamespaceDetector();

        self::assertSame('App', $detector->detect(new SplFileInfo($file)));
    }

    #[Test]
    public function itDetectsNestedNamespace(): void
    {
        $file = $this->createFile('Test.php', '<?php namespace App\\Service\\User;');

        $detector = new TokenizerNamespaceDetector();

        self::assertSame('App\\Service\\User', $detector->detect(new SplFileInfo($file)));
    }

    #[Test]
    public function itDetectsBracedNamespace(): void
    {
        $file = $this->createFile('Test.php', '<?php namespace App\\Core { class Test {} }');

        $detector = new TokenizerNamespaceDetector();

        self::assertSame('App\\Core', $detector->detect(new SplFileInfo($file)));
    }

    #[Test]
    public function itReturnsEmptyForGlobalNamespace(): void
    {
        $file = $this->createFile('Test.php', '<?php class GlobalClass {}');

        $detector = new TokenizerNamespaceDetector();

        self::assertSame('', $detector->detect(new SplFileInfo($file)));
    }

    #[Test]
    public function itHandlesNamespaceWithComments(): void
    {
        $file = $this->createFile(
            'Test.php',
            <<<'PHP'
<?php
// Some comment
/* Another comment */
namespace App\Domain\Model;

class Entity {}
PHP
        );

        $detector = new TokenizerNamespaceDetector();

        self::assertSame('App\\Domain\\Model', $detector->detect(new SplFileInfo($file)));
    }

    #[Test]
    public function itHandlesDeclareBefore(): void
    {
        $file = $this->createFile(
            'Test.php',
            <<<'PHP'
<?php
declare(strict_types=1);

namespace App\Infrastructure;

class Service {}
PHP
        );

        $detector = new TokenizerNamespaceDetector();

        self::assertSame('App\\Infrastructure', $detector->detect(new SplFileInfo($file)));
    }

    #[Test]
    public function itReturnsEmptyForNonExistentFile(): void
    {
        $detector = new TokenizerNamespaceDetector();

        self::assertSame('', $detector->detect(new SplFileInfo('/non/existent/file.php')));
    }

    #[Test]
    public function itReturnsEmptyForEmptyFile(): void
    {
        $file = $this->createFile('Empty.php', '');

        $detector = new TokenizerNamespaceDetector();

        self::assertSame('', $detector->detect(new SplFileInfo($file)));
    }

    #[Test]
    public function itHandlesMultilineNamespace(): void
    {
        $file = $this->createFile(
            'Test.php',
            <<<'PHP'
<?php
namespace
    App\Service;
PHP
        );

        $detector = new TokenizerNamespaceDetector();

        self::assertSame('App\\Service', $detector->detect(new SplFileInfo($file)));
    }

    private function createFile(string $name, string $content): string
    {
        $path = $this->fixturesDir . '/' . $name;
        file_put_contents($path, $content);

        return $path;
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
