<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\TestSuiteHygiene;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class SuiteCacheIsIsolatedTest extends TestCase
{
    #[Test]
    public function itExportsCacheHomeToEveryProcessChannel(): void
    {
        $root = \constant('QMX_TEST_PROCESS_ROOT');
        self::assertIsString($root);
        $expected = $root . '/xdg-cache';
        self::assertSame($expected, getenv('XDG_CACHE_HOME'));
        self::assertSame($expected, $_ENV['XDG_CACHE_HOME'] ?? null);
        self::assertSame($expected, $_SERVER['XDG_CACHE_HOME'] ?? null);
        self::assertDirectoryExists($expected);
        self::assertSame(0o700, fileperms($expected) & 0o777);
        self::assertFalse(str_starts_with($expected, \dirname(__DIR__, 2) . '/'));
        self::assertFalse(str_starts_with($expected, $root . '/cwd/'));

        $child = new Process([\PHP_BINARY, '-r', 'echo getenv("XDG_CACHE_HOME");']);
        $child->mustRun();
        self::assertSame($expected, $child->getOutput());
    }

    #[Test]
    public function itKeepsTheProductDefaultCacheUnderTheSuiteHome(): void
    {
        $root = \constant('QMX_TEST_PROCESS_ROOT');
        self::assertIsString($root);
        $cwd = $root . '/cwd';
        $source = $cwd . '/SuiteCacheProbe.php';
        $config = $cwd . '/suite-cache-probe.yaml';
        $suffix = bin2hex(random_bytes(8));
        $xdgHome = $root . '/xdg-cache/product-' . $suffix;
        $home = $root . '/home-probe-' . $suffix;

        try {
            self::assertTrue(mkdir($xdgHome, 0o700));
            self::assertTrue(mkdir($home, 0o700));
            file_put_contents($source, "<?php\nfinal class SuiteCacheProbe {}\n");
            file_put_contents($config, "{}\n");

            $process = new Process([
                \PHP_BINARY, \dirname(__DIR__, 2) . '/bin/qmx',
                '--working-dir=' . $cwd, 'check', $source,
                '--config=' . $config, '--workers=0', '--format=json', '--fail-on=none', '--no-progress',
            ], $cwd, ['XDG_CACHE_HOME' => $xdgHome, 'HOME' => $home, 'LOCALAPPDATA' => $home]);
            $process->setTimeout(30);
            $process->run();
            self::assertContains($process->getExitCode(), [0, 1, 2], $process->getErrorOutput());
            $report = json_decode($process->getOutput(), true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($report);
            self::assertGreaterThan(0, $report['coverage']['analyzed'] ?? 0);
            self::assertSame(0, iterator_count(new FilesystemIterator($home, FilesystemIterator::SKIP_DOTS)));
            $entries = glob($xdgHome . '/qmx/ast-v*/*/*');
            self::assertNotFalse($entries);
            $astEntries = array_filter($entries, static fn(string $entry): bool => is_file($entry)
                && preg_match('/^[0-9a-f]{2}$/D', basename(\dirname($entry))) === 1
                && preg_match('/^[0-9a-f]{64}$/D', basename($entry)) === 1);
            self::assertNotEmpty($astEntries, 'The product did not write an AST entry under its private XDG home.');
            self::assertDirectoryDoesNotExist($cwd . '/.qmx-cache');
        } finally {
            (new Filesystem())->remove([$source, $config, $xdgHome, $home]);
        }
    }
}
