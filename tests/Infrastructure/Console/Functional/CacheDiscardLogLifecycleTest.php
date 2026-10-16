<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Cache\AstEntryCodec;
use Qualimetrix\Infrastructure\Cache\CacheKeyGenerator;
use Qualimetrix\Infrastructure\Cache\FileCache;
use Qualimetrix\Infrastructure\Console\Application\Application;
use Qualimetrix\Infrastructure\Console\Application\ConsoleDiagnostics;
use Qualimetrix\Infrastructure\Console\Refusal\OutOfMemoryHint;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use stdClass;
use Symfony\Component\Console\Tester\ApplicationTester;

#[CoversClass(Application::class)]
final class CacheDiscardLogLifecycleTest extends TestCase
{
    #[Test]
    public function itPublishesOneCompleteReportAndLogsDiscardBeforeClosingTheTarget(): void
    {
        $root = sys_get_temp_dir() . '/qmx-discard-log-' . bin2hex(random_bytes(6));
        $project = $root . '/project';
        $cache = $root . '/cache';
        try {
            mkdir($project . '/src', 0o700, true);
            $source = '<?php namespace Demo; final class Source { public function run(): void { eval("return;"); } }';
            file_put_contents($project . '/src/Source.php', $source);
            file_put_contents($root . '/neutral.yaml', "{}\n");
            $key = (new CacheKeyGenerator())->generateForContent($source);
            self::assertNotSame('', $key);
            (new FileCache(AbsolutePath::fromString($cache)))->set($key, []);
            $entry = $cache . '/ast-v' . AstEntryCodec::FORMAT_VERSION . '/' . substr($key, 0, 2) . '/' . $key;
            file_put_contents($entry, serialize([new stdClass()]));

            $application = Application::forProduct(
                static fn(ConsoleDiagnostics $diagnostics) => (new ContainerFactory())->create($diagnostics),
                OutOfMemoryHint::register(),
            );
            $application->setAutoExit(false);
            $tester = new ApplicationTester($application);

            foreach (['poisoned', 'repaired'] as $phase) {
                $log = $root . '/' . $phase . '.jsonl';
                $tester->run([
                    'command' => 'check', 'paths' => ['src'], '--working-dir' => $project,
                    '--config' => $root . '/neutral.yaml', '--format' => 'json', '--workers' => '0',
                    '--cache-dir' => $cache, '--log-file' => $log, '--log-level' => 'debug',
                    '--fail-on' => 'none', '--no-progress' => true,
                ], ['capture_stderr_separately' => true]);

                self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay() . $tester->getErrorOutput());
                /** @var array<string, mixed> $report */
                $report = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
                self::assertArrayHasKey('coverage', $report);
                self::assertSame(true, $report['coverage']['complete']);
                self::assertSame(1, $report['coverage']['discovered']);
                self::assertSame(1, $report['coverage']['analyzed']);
                self::assertSame(0, $report['coverage']['failed']);
                $warning = '1 cache entry discarded because the AST entry was invalid.';
                $expected = $phase === 'poisoned' ? 1 : 0;
                self::assertSame($expected, substr_count($tester->getErrorOutput(), $warning));
                $records = file_get_contents($log);
                self::assertIsString($records);
                self::assertSame($expected, substr_count($records, $warning));
            }
            self::assertNotSame(serialize([new stdClass()]), file_get_contents($entry));
        } finally {
            if (is_dir($root)) {
                foreach (new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST,
                ) as $item) {
                    if ($item->isDir()) {
                        rmdir($item->getPathname());
                    } else {
                        unlink($item->getPathname());
                    }
                }
                rmdir($root);
            }
        }
    }
}
