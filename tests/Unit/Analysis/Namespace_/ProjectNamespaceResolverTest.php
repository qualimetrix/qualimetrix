<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Namespace_;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Namespace_\ProjectNamespaceResolver;
use RuntimeException;

#[CoversClass(ProjectNamespaceResolver::class)]
final class ProjectNamespaceResolverTest extends TestCase
{
    /**
     */
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/qmx_test_' . uniqid();
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

    public function testOverridePrefixesTakesPrecedence(): void
    {
        $resolver = new ProjectNamespaceResolver(
            composerJsonPath: null,
            overridePrefixes: ['App\\', 'Tests\\'],
        );

        self::assertTrue($resolver->isProjectNamespace('App\\Service\\UserService'));
        self::assertTrue($resolver->isProjectNamespace('Tests\\Unit\\CoreTest'));
        self::assertFalse($resolver->isProjectNamespace('Symfony\\Component\\Console'));

        self::assertSame(['Tests', 'App'], $resolver->getProjectPrefixes());
    }

    public function testExtractPrefixesFromComposerJson(): void
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

        $resolver = new ProjectNamespaceResolver(composerJsonPath: $path);

        self::assertTrue($resolver->isProjectNamespace('App\\Service'));
        self::assertTrue($resolver->isProjectNamespace('Tests\\Unit'));
        self::assertFalse($resolver->isProjectNamespace('Vendor\\Package'));
    }

    public function testPrefixesAreSortedByLengthDescending(): void
    {
        $resolver = new ProjectNamespaceResolver(
            composerJsonPath: null,
            overridePrefixes: ['A\\', 'App\\', 'App\\Service\\'],
        );

        $prefixes = $resolver->getProjectPrefixes();

        self::assertSame(['App\\Service', 'App', 'A'], $prefixes);
    }

    public function testDuplicatePrefixesAreRemoved(): void
    {
        $resolver = new ProjectNamespaceResolver(
            composerJsonPath: null,
            overridePrefixes: ['App\\', 'App\\', 'Tests\\'],
        );

        self::assertSame(['Tests', 'App'], $resolver->getProjectPrefixes());
    }

    public function testEmptyNamespaceIsConsideredProjectNamespace(): void
    {
        $resolver = new ProjectNamespaceResolver(
            composerJsonPath: null,
            overridePrefixes: ['App\\'],
        );

        self::assertTrue($resolver->isProjectNamespace(''));
    }

    #[DataProvider('namespaceMatchingProvider')]
    public function testNamespaceMatching(string $prefix, string $namespace, bool $expected): void
    {
        $resolver = new ProjectNamespaceResolver(
            composerJsonPath: null,
            overridePrefixes: [$prefix],
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

    public function testThrowsExceptionIfComposerJsonNotFound(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('composer.json not found at:');

        new ProjectNamespaceResolver(composerJsonPath: $this->tempDir . '/nonexistent.json');
    }

    public function testThrowsExceptionIfComposerJsonIsInvalid(): void
    {
        $path = $this->tempDir . '/composer.json';
        file_put_contents($path, 'invalid json');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid JSON in composer.json');

        new ProjectNamespaceResolver(composerJsonPath: $path);
    }

    public function testThrowsExceptionIfNoPsr4ConfigFound(): void
    {
        $composerJson = <<<JSON
{
    "name": "test/package"
}
JSON;

        $path = $this->tempDir . '/composer.json';
        file_put_contents($path, $composerJson);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No PSR-4 autoload configuration found');

        new ProjectNamespaceResolver(composerJsonPath: $path);
    }

    public function testAutoDetectsComposerJsonInCurrentDirectory(): void
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

        // Change to temp directory
        $originalCwd = getcwd();
        if ($originalCwd === false) {
            self::fail('Cannot get current directory');
        }

        chdir($this->tempDir);

        try {
            $resolver = new ProjectNamespaceResolver();
            self::assertTrue($resolver->isProjectNamespace('TestApp\\Service'));
        } finally {
            chdir($originalCwd);
        }
    }

    public function testAutoDetectsComposerJsonInParentDirectory(): void
    {
        $composerJson = <<<JSON
{
    "autoload": {
        "psr-4": {
            "ParentApp\\\\": "src/"
        }
    }
}
JSON;

        $path = $this->tempDir . '/composer.json';
        file_put_contents($path, $composerJson);

        // Create subdirectory
        $subDir = $this->tempDir . '/subdir';
        mkdir($subDir);

        // Change to subdirectory
        $originalCwd = getcwd();
        if ($originalCwd === false) {
            self::fail('Cannot get current directory');
        }

        chdir($subDir);

        try {
            $resolver = new ProjectNamespaceResolver();
            self::assertTrue($resolver->isProjectNamespace('ParentApp\\Service'));
        } finally {
            chdir($originalCwd);
        }
    }

    public function testThrowsExceptionIfComposerJsonNotFoundInParentDirectories(): void
    {
        // Use system temp directory which likely doesn't have composer.json
        $originalCwd = getcwd();
        if ($originalCwd === false) {
            self::fail('Cannot get current directory');
        }

        chdir('/tmp');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('composer.json not found in current or parent directories');

            new ProjectNamespaceResolver();
        } finally {
            chdir($originalCwd);
        }
    }

    public function testHandlesMultiplePsr4Prefixes(): void
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

        $resolver = new ProjectNamespaceResolver(composerJsonPath: $path);

        self::assertTrue($resolver->isProjectNamespace('App\\Service'));
        self::assertTrue($resolver->isProjectNamespace('Domain\\Entity'));
        self::assertTrue($resolver->isProjectNamespace('Infrastructure\\Repository'));
        self::assertFalse($resolver->isProjectNamespace('Vendor\\Package'));

        // Check sorting by length
        $prefixes = $resolver->getProjectPrefixes();
        self::assertSame(['Infrastructure', 'Domain', 'App'], $prefixes);
    }

    public function testEmptyPrefixMatchesEverything(): void
    {
        $resolver = new ProjectNamespaceResolver(
            composerJsonPath: null,
            overridePrefixes: [''],
        );

        self::assertTrue($resolver->isProjectNamespace('App\\Service'));
        self::assertTrue($resolver->isProjectNamespace('Vendor\\Package'));
        self::assertTrue($resolver->isProjectNamespace(''));
    }
}
