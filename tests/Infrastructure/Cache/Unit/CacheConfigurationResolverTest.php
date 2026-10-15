<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Cache\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Cache\CacheConfigurationResolver;
use Qualimetrix\Infrastructure\Cache\CacheConfigurationStore;
use Qualimetrix\Infrastructure\Cache\CacheFactory;
use Qualimetrix\Infrastructure\Cache\CacheRootUntrusted;
use Qualimetrix\Tests\Analysis\Configuration\Support\LayeredDocument;

final class CacheConfigurationResolverTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/qmx-cache-location-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/link');
        @unlink($this->root . '/blocked');
        @rmdir($this->root . '/outside');
        @rmdir($this->root . '/project');
        @rmdir($this->root);
    }

    #[Test]
    public function itRefusesExplicitCanonicalPathsInsideTheProjectAndHonoursDisabledCache(): void
    {
        mkdir($this->root . '/project', 0700);
        symlink($this->root . '/project', $this->root . '/link');
        $project = AbsolutePath::fromString($this->root . '/project');
        foreach ([$project->value(), $project->value() . '/cache', $this->root . '/link/cache'] as $directory) {
            try {
                $this->resolve($directory, $project);
                self::fail('A cache inside the checkout was accepted.');
            } catch (ConfigurationRefusal $error) {
                self::assertStringContainsString('inside the project root', $error->getMessage());
                self::assertStringContainsString('--working-dir', $error->getMessage());
                self::assertSame(['cache', 'dir'], $error->position()?->segments);
            }
        }
        self::assertFalse($this->resolve($project->value() . '/cache', $project, false)->enabled);
        self::assertDirectoryDoesNotExist($project->value() . '/cache');
    }

    #[Test]
    public function itRefusesUntrustedRootsAndAllowsTheFilesystemRootAsProjectRoot(): void
    {
        mkdir($this->root . '/outside', 0700);
        chmod($this->root . '/outside', 0777);
        try {
            $this->resolve($this->root . '/outside', AbsolutePath::fromString('/'));
            self::fail('A world-writable cache was accepted.');
        } catch (CacheRootUntrusted $error) {
            self::assertStringContainsString('0777', $error->getMessage());
            self::assertStringContainsString('cache.dir', $error->getMessage());
        }
        chmod($this->root . '/outside', 0700);
        self::assertTrue($this->resolve($this->root . '/outside', AbsolutePath::fromString('/'))->enabled);
    }

    #[Test]
    public function itRefusesUncreatableExplicitPathsWithoutCreatingAnything(): void
    {
        touch($this->root . '/blocked');
        try {
            $this->resolve($this->root . '/blocked/cache', AbsolutePath::fromString('/'));
            self::fail('A file cannot become a cache parent directory.');
        } catch (ConfigurationRefusal $error) {
            self::assertStringContainsString('not writable', $error->getMessage());
            self::assertStringContainsString('--no-cache', $error->getMessage());
        }
    }

    #[Test]
    public function itRetainsTheExplicitSourceWhenClearJudgesADisabledCache(): void
    {
        mkdir($this->root . '/project', 0700);
        $project = AbsolutePath::fromString($this->root . '/project');
        $configuration = $this->resolve($project->value() . '/cache', $project, false);
        $store = new CacheConfigurationStore();
        $store->replace($configuration);
        try {
            (new CacheFactory($store))->clear();
            self::fail('An explicit clear inside the project must refuse even with caching disabled.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame('config', $refusal->sources()[0]->locator());
            self::assertSame(['cache', 'dir'], $refusal->position()?->segments);
            self::assertStringContainsString('inside the project root', $refusal->summary());
        }
        self::assertDirectoryDoesNotExist($project->value() . '/cache');
    }

    private function resolve(string $directory, AbsolutePath $project, bool $enabled = true): \Qualimetrix\Infrastructure\Cache\Contract\CacheConfiguration
    {
        return (new CacheConfigurationResolver())->resolve(LayeredDocument::of([
            ['source' => 'config', 'values' => ['cache.dir' => $directory, 'cache.enabled' => $enabled]],
        ], $project), $project);
    }
}
