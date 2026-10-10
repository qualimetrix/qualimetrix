<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Subprocess\ChildProcess;

require_once \dirname(__DIR__, 4) . '/scripts/subprocess/ChildProcess.php';

#[CoversClass(CheckCommand::class)]
final class LogTargetFailureTest extends TestCase
{
    #[Test]
    public function itReportsACollectionLogWriteFailureInsteadOfACompletedRun(): void
    {
        $directory = sys_get_temp_dir() . '/qmx-log-target-fault-' . bin2hex(random_bytes(6));
        mkdir($directory);
        file_put_contents($directory . '/Source.php', '<?php namespace Demo; final class Source {}');
        $script = <<<'PHP'
            namespace Qualimetrix\Core\FileTarget {
                function fwrite($stream, string $bytes): int|false
                {
                    if (str_contains($bytes, '"message":"Parsing file"')) {
                        ++$GLOBALS['qmx_log_hit'];
                        return 0;
                    }
                    return \fwrite($stream, $bytes);
                }
            }
            namespace {
                require $argv[1];
                $directory = $argv[2];
                chdir($directory);
                $GLOBALS['qmx_log_target'] = $directory . '/run.log';
                $GLOBALS['qmx_log_hit'] = 0;
                $command = (new \Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory())->create()->get(\Qualimetrix\Infrastructure\Console\Command\CheckCommand::class);
                $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
                $tester->execute([
                    'paths' => [$directory . '/Source.php'],
                    '--format' => 'json',
                    '--workers' => '0',
                    '--no-cache' => true,
                    '--output' => $directory . '/report.json',
                    '--log-file' => $GLOBALS['qmx_log_target'],
                    '--log-level' => 'debug',
                ], ['capture_stderr_separately' => true]);
                echo json_encode([
                    'hit' => $GLOBALS['qmx_log_hit'],
                    'exit' => $tester->getStatusCode(),
                    'stdout' => $tester->getDisplay(),
                    'stderr' => $tester->getErrorOutput(),
                    'report' => file_exists($directory . '/report.json'),
                    'log' => file_exists($GLOBALS['qmx_log_target']) ? file_get_contents($GLOBALS['qmx_log_target']) : null,
                ], JSON_THROW_ON_ERROR);
            }
            PHP;

        try {
            $run = ChildProcess::run([\PHP_BINARY, '-r', $script, \dirname(__DIR__, 4) . '/vendor/autoload.php', $directory]);
            self::assertSame(0, $run['exitCode'], $run['stderr']);
            self::assertJson($run['stdout'], $run['stdout'] . $run['stderr']);
            $result = json_decode($run['stdout'], true, flags: \JSON_THROW_ON_ERROR);
            self::assertGreaterThan(0, $result['hit'], $run['stdout']);
            self::assertSame(3, $result['exit'], $result['stdout'] . $result['stderr']);
            self::assertStringContainsString('--log-file', $result['stdout'] . $result['stderr']);
            self::assertStringContainsString('log record(s) lost', $result['stdout'] . $result['stderr']);
            self::assertFalse($result['report']);
            self::assertIsString($result['log']);
            $lines = explode("\n", trim($result['log']));
            self::assertNotEmpty($lines);
            foreach ($lines as $line) {
                self::assertIsArray(json_decode($line, true, flags: \JSON_THROW_ON_ERROR));
            }
        } finally {
            foreach (['Source.php', 'report.json', 'run.log'] as $name) {
                if (file_exists($directory . '/' . $name)) {
                    unlink($directory . '/' . $name);
                }
            }
            rmdir($directory);
        }
    }
}
