<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Cache\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Cache\CacheConfigurationStore;
use Qualimetrix\Infrastructure\Cache\CacheFactory;
use Qualimetrix\Infrastructure\Cache\CacheSource;
use Qualimetrix\Infrastructure\Cache\Contract\CacheConfiguration;
use Qualimetrix\Infrastructure\Cache\DefaultCacheDirectory;
use Symfony\Component\Process\Process;

#[CoversClass(DefaultCacheDirectory::class)]
final class DefaultCacheDirectoryTest extends TestCase
{
    #[Test]
    public function itUsesOnlyAbsoluteEnvironmentBasesOnEachPlatform(): void
    {
        self::assertSame('/xdg/qmx', DefaultCacheDirectory::for(['XDG_CACHE_HOME' => '/xdg', 'HOME' => '/owner'], 'Linux')?->value());
        self::assertSame('/owner/.cache/qmx', DefaultCacheDirectory::for(['XDG_CACHE_HOME' => 'relative', 'HOME' => '/owner'], 'Linux')?->value());
        self::assertSame('/owner/Library/Caches/qmx', DefaultCacheDirectory::for(['HOME' => '/owner'], 'Darwin')?->value());
        self::assertSame('/local/qmx', DefaultCacheDirectory::for(['LOCALAPPDATA' => '/local'], 'Windows')?->value());
        self::assertNull(DefaultCacheDirectory::for(['LOCALAPPDATA' => 'C:\\Local'], 'Windows'));
        self::assertNull(DefaultCacheDirectory::for(['LOCALAPPDATA' => '\\\\server\\cache'], 'Windows'));
        self::assertNull(DefaultCacheDirectory::for([], 'Linux'));
        self::assertNull(DefaultCacheDirectory::for(['HOME' => 'relative'], 'Darwin'));
        self::assertNull(DefaultCacheDirectory::for(['XDG_CACHE_HOME' => '/../../cache', 'HOME' => '/owner'], 'Linux'));
        self::assertNull(DefaultCacheDirectory::for(['HOME' => '/../../owner'], 'Darwin'));
        self::assertNull(DefaultCacheDirectory::for(['LOCALAPPDATA' => '/../../local'], 'Windows'));
    }
    #[Test]
    public function itDisablesUnrepresentableNativeDriveAndUncDefaults(): void
    {
        foreach (['C:\\Local', '\\\\server\\cache'] as $native) {
            self::assertNull(DefaultCacheDirectory::for(['LOCALAPPDATA' => $native], 'Windows'));
            self::assertNull(DefaultCacheDirectory::for(['XDG_CACHE_HOME' => $native], 'Windows'));
        }
    }

    #[Test]
    public function itDisablesADefaultWhoseCanonicalPathBecomesANativeDrivePath(): void
    {
        $script = <<<'PHP'
namespace Qualimetrix\Infrastructure\Cache {
    function realpath(string $path): string|false { return 'C:\\cache'; }
}
namespace {
    require $argv[1];
    $store = new \Qualimetrix\Infrastructure\Cache\CacheConfigurationStore();
    $store->replace(new \Qualimetrix\Infrastructure\Cache\Contract\CacheConfiguration(
        \Qualimetrix\Core\Path\AbsolutePath::fromString($argv[2]),
        source: \Qualimetrix\Infrastructure\Cache\CacheSource::default(),
    ));
    $logger = new class extends \Psr\Log\AbstractLogger {
        public array $warnings = [];
        public function log($level, string|\Stringable $message, array $context = []): void {
            if ($level === 'warning') $this->warnings[] = (string) $message;
        }
    };
    $factory = new \Qualimetrix\Infrastructure\Cache\CacheFactory($store);
    $factory->configureLogger($logger);
    try {
        echo json_encode([$factory->open(), $factory->open(), $logger->warnings], JSON_THROW_ON_ERROR);
    } finally {
        @unlink($argv[2] . '/ast-v1/.sweep');
        @unlink($argv[2] . '/ast-v1/CACHEDIR.TAG');
        @rmdir($argv[2] . '/ast-v1');
        @rmdir($argv[2]);
    }
}
PHP;
        $directory = sys_get_temp_dir() . '/qmx-canonical-native-' . bin2hex(random_bytes(6));
        $process = new Process([\PHP_BINARY, '-r', $script, \dirname(__DIR__, 4) . '/vendor/autoload.php', $directory]);
        $process->mustRun();
        $result = json_decode($process->getOutput(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame([null, null], [$result[0], $result[1]]);
        self::assertCount(1, $result[2]);
        self::assertStringContainsString('cannot be represented by the POSIX path contract', $result[2][0]);
    }
    #[Test]
    public function itAcceptsPosixDoubleSlashesThroughNativeCanonicalJudgement(): void
    {
        if (\DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('This case requires a POSIX host.');
        }
        $base = sys_get_temp_dir() . '/qmx-double-slash-' . bin2hex(random_bytes(6));
        mkdir($base . '/qmx', 0700, true);
        $factory = null;
        try {
            $directory = DefaultCacheDirectory::for(['XDG_CACHE_HOME' => '/' . $base], 'Linux');
            self::assertNotNull($directory);
            $store = new CacheConfigurationStore();
            $store->replace(new CacheConfiguration($directory, source: CacheSource::default(), projectRoot: AbsolutePath::fromString('/')));
            $factory = new CacheFactory($store);
            $opened = $factory->open();
            self::assertNotNull($opened);
            self::assertSame(realpath($base . '/qmx'), realpath($opened->value()));
        } finally {
            $factory?->clear();
            @rmdir($base . '/qmx');
            @rmdir($base);
        }
    }

}
