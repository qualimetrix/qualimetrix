<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Measurement\Unit\Namespace_;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Measurement\Namespace_\ProjectNamespaceResolver;
use Qualimetrix\Core\Path\AbsolutePath;

#[CoversClass(ProjectNamespaceResolver::class)]
final class ProjectNamespaceResolverTest extends TestCase
{
    /**
     */
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/qmx_test_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    private function removeDirectory(string $dir): void
    {
        $items = glob($dir . '/{,.}[!.,!..]*', \GLOB_MARK | \GLOB_BRACE);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if (is_dir($item)) {
                $this->removeDirectory($item);
            } else {
                unlink($item);
            }
        }

        rmdir($dir);
    }

    #[Test]
    public function itReplacesPrefixesWithAcceptedRecordsFromTheCurrentProject(): void
    {
        $decoder = new \Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestDecoder();
        $root = AbsolutePath::fromString($this->tempDir);
        $resolver = new ProjectNamespaceResolver(['Old']);
        $resolver->bind($decoder->decode($root, '{"autoload":{"psr-4":{"Current\\\\":"src","Dropped\\\\":false}}}'));
        self::assertSame(['Current'], $resolver->getProjectPrefixes());
        self::assertFalse($resolver->isProjectNamespace('Old\\Thing'));
        self::assertFalse($resolver->isProjectNamespace('Dropped\\Thing'));
        $resolver->bind($decoder->decode($root, '{"autoload":{"psr-4":{"Next\\\\":"next"}}}'));
        self::assertSame(['Next'], $resolver->getProjectPrefixes());
    }

    #[Test]
    public function itOverridePrefixesTakePrecedence(): void
    {
        $resolver = new ProjectNamespaceResolver(
            prefixes: ['App\\', 'Tests\\'],
        );

        self::assertTrue($resolver->isProjectNamespace('App\\Service\\UserService'));
        self::assertTrue($resolver->isProjectNamespace('Tests\\Unit\\CoreTest'));
        self::assertFalse($resolver->isProjectNamespace('Symfony\\Component\\Console'));

        self::assertSame(['Tests', 'App'], $resolver->getProjectPrefixes());
    }

    #[Test]
    public function itExtractsPrefixesFromComposerJson(): void
    {
        $composerJson = <<<JSON
{
    "autoload": {
        "psr-4": {
            "App\\\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Tests\\\\": "tests/"
        }
    }
}
JSON;

        $path = $this->tempDir . '/composer.json';
        file_put_contents($path, $composerJson);

        $resolver = new ProjectNamespaceResolver();
        $resolver->bind((new \Qualimetrix\Infrastructure\Composer\ComposerManifestReader())->read(AbsolutePath::fromString(\dirname($path))));

        self::assertTrue($resolver->isProjectNamespace('App\\Service'));
        self::assertTrue($resolver->isProjectNamespace('Tests\\Unit'));
        self::assertFalse($resolver->isProjectNamespace('Vendor\\Package'));
    }

    #[Test]
    public function itSortsPrefixesByLengthDescending(): void
    {
        $resolver = new ProjectNamespaceResolver(
            prefixes: ['A\\', 'App\\', 'App\\Service\\'],
        );

        $prefixes = $resolver->getProjectPrefixes();

        self::assertSame(['App\\Service', 'App', 'A'], $prefixes);
    }

    #[Test]
    public function itRemovesDuplicatePrefixes(): void
    {
        $resolver = new ProjectNamespaceResolver(
            prefixes: ['App\\', 'App\\', 'Tests\\'],
        );

        self::assertSame(['Tests', 'App'], $resolver->getProjectPrefixes());
    }

    #[Test]
    public function itConsidersEmptyNamespaceAsProjectNamespace(): void
    {
        $resolver = new ProjectNamespaceResolver(
            prefixes: ['App\\'],
        );

        self::assertTrue($resolver->isProjectNamespace(''));
    }

    #[Test]
    #[DataProvider('namespaceMatchingProvider')]
    public function itMatchesNamespace(string $prefix, string $namespace, bool $expected): void
    {
        $resolver = new ProjectNamespaceResolver(
            prefixes: [$prefix],
        );

        self::assertSame($expected, $resolver->isProjectNamespace($namespace));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function namespaceMatchingProvider(): iterable
    {
        yield 'exact match' => ['App', 'App', true];
        yield 'child namespace' => ['App', 'App\\Service', true];
        yield 'nested child' => ['App', 'App\\Service\\UserService', true];
        yield 'prefix mismatch' => ['App', 'Application', false];
        yield 'no boundary' => ['App', 'AppService', false];
        yield 'different prefix' => ['App', 'Vendor\\Package', false];
        yield 'longer prefix' => ['App\\Service', 'App\\Service\\UserService', true];
        yield 'shorter prefix' => ['App\\Service', 'App', false];
        yield 'with leading backslash' => ['App', '\\App\\Service', true];
        yield 'with trailing backslash' => ['App\\', 'App\\Service', true];
    }

    #[Test]
    public function itGracefullyDegradeIfComposerJsonNotFound(): void
    {
        $resolver = new ProjectNamespaceResolver();
        $resolver->bind((new \Qualimetrix\Infrastructure\Composer\ComposerManifestReader())->read(AbsolutePath::fromString(\dirname($this->tempDir . '/nonexistent.json'))));

        // All namespaces treated as project when composer.json is missing
        self::assertTrue($resolver->isProjectNamespace('Any\\Namespace'));
        self::assertSame([], $resolver->getProjectPrefixes());
    }

    #[Test]
    public function itGracefullyDegradeIfComposerJsonIsInvalid(): void
    {
        $path = $this->tempDir . '/composer.json';
        file_put_contents($path, 'invalid json');

        $resolver = new ProjectNamespaceResolver();
        $resolver->bind((new \Qualimetrix\Infrastructure\Composer\ComposerManifestReader())->read(AbsolutePath::fromString(\dirname($path))));

        self::assertTrue($resolver->isProjectNamespace('Any\\Namespace'));
        self::assertSame([], $resolver->getProjectPrefixes());
    }

    #[Test]
    public function itGracefullyDegradeIfNoPsr4ConfigFound(): void
    {
        $composerJson = <<<JSON
{
    "name": "test/package"
}
JSON;

        $path = $this->tempDir . '/composer.json';
        file_put_contents($path, $composerJson);

        $resolver = new ProjectNamespaceResolver();
        $resolver->bind((new \Qualimetrix\Infrastructure\Composer\ComposerManifestReader())->read(AbsolutePath::fromString(\dirname($path))));

        self::assertTrue($resolver->isProjectNamespace('Any\\Namespace'));
        self::assertSame([], $resolver->getProjectPrefixes());
    }

    #[Test]
    public function itDoesNotReadComposerJsonFromCwdInTheConstructor(): void
    {
        $composerJson = <<<JSON
{
    "autoload": {
        "psr-4": {
            "TestApp\\\\": "src/"
        }
    }
}
JSON;

        $path = $this->tempDir . '/composer.json';
        file_put_contents($path, $composerJson);

        $originalCwd = getcwd();
        if ($originalCwd === false) {
            self::fail('Cannot get current directory');
        }

        chdir($this->tempDir);

        try {
            $resolver = new ProjectNamespaceResolver();
            self::assertSame([], $resolver->getProjectPrefixes());
            self::assertTrue($resolver->isProjectNamespace('TestApp\\Service'));
        } finally {
            chdir($originalCwd);
        }
    }

    #[Test]
    public function itGracefullyDegradeIfComposerJsonNotInCwd(): void
    {
        // Subdirectory without composer.json — no longer searches parent dirs
        $subDir = $this->tempDir . '/subdir';
        mkdir($subDir);

        $originalCwd = getcwd();
        if ($originalCwd === false) {
            self::fail('Cannot get current directory');
        }

        chdir($subDir);

        try {
            $resolver = new ProjectNamespaceResolver();
            // All namespaces treated as project when composer.json is missing
            self::assertTrue($resolver->isProjectNamespace('Any\\Namespace'));
            self::assertSame([], $resolver->getProjectPrefixes());
        } finally {
            chdir($originalCwd);
        }
    }

    #[Test]
    public function itHandlesMultiplePsr4Prefixes(): void
    {
        $composerJson = <<<JSON
{
    "autoload": {
        "psr-4": {
            "App\\\\": "src/",
            "Domain\\\\": "src/Domain/",
            "Infrastructure\\\\": "src/Infrastructure/"
        }
    }
}
JSON;

        $path = $this->tempDir . '/composer.json';
        file_put_contents($path, $composerJson);

        $resolver = new ProjectNamespaceResolver();
        $resolver->bind((new \Qualimetrix\Infrastructure\Composer\ComposerManifestReader())->read(AbsolutePath::fromString(\dirname($path))));

        self::assertTrue($resolver->isProjectNamespace('App\\Service'));
        self::assertTrue($resolver->isProjectNamespace('Domain\\Entity'));
        self::assertTrue($resolver->isProjectNamespace('Infrastructure\\Repository'));
        self::assertFalse($resolver->isProjectNamespace('Vendor\\Package'));

        // Check sorting by length
        $prefixes = $resolver->getProjectPrefixes();
        self::assertSame(['Infrastructure', 'Domain', 'App'], $prefixes);
    }

    #[Test]
    public function itMatchesEverythingWithEmptyPrefix(): void
    {
        $resolver = new ProjectNamespaceResolver(
            prefixes: [''],
        );

        self::assertTrue($resolver->isProjectNamespace('App\\Service'));
        self::assertTrue($resolver->isProjectNamespace('Vendor\\Package'));
        self::assertTrue($resolver->isProjectNamespace(''));
    }
}
