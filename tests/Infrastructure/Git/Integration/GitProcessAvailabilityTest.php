<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Git\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Subprocess\ChildProcess;

require_once \dirname(__DIR__, 4) . '/scripts/subprocess/ChildProcess.php';

final class GitProcessAvailabilityTest extends TestCase
{
    #[Test]
    public function itRefusesGitReportingAndKeepsDirectoryBasedHookOperationsWhenProcOpenIsDisabled(): void
    {
        $root = sys_get_temp_dir() . '/qmx-git-capability-' . bin2hex(random_bytes(6));
        mkdir($root . '/.git/hooks', 0700, true);
        mkdir($root . '/src', 0700);
        file_put_contents($root . '/src/Clean.php', '<?php final class Clean {}');
        $prefix = [\PHP_BINARY, '-d', 'disable_functions=proc_open', \dirname(__DIR__, 4) . '/bin/qmx'];
        try {
            $result = ChildProcess::run([...$prefix, 'check', 'src', '--workers=0', '--no-cache', '--report=git:staged', '--format=json'], $root);
            self::assertSame(3, $result['exitCode'], $result['stderr']);
            $document = json_decode($result['stdout'], true, flags: \JSON_THROW_ON_ERROR);
            self::assertStringContainsString('Environment error:', $document['error']);
            self::assertStringContainsString('proc_open', $document['error']);
            self::assertSame('', $result['stderr']);

            foreach ([[$prefix, null], [[\PHP_BINARY, \dirname(__DIR__, 4) . '/bin/qmx'], ['PATH' => $root . '/missing']]] as [$invocation, $environment]) {
                foreach (['hook:install', 'hook:status', 'hook:uninstall'] as $command) {
                    $result = ChildProcess::run([...$invocation, $command], $root, environment: $environment);
                    self::assertSame(0, $result['exitCode'], $command . ': ' . $result['stderr']);
                    self::assertSame('', $result['stderr']);
                    if ($command === 'hook:install') {
                        self::assertFileExists($root . '/.git/hooks/pre-commit');
                    }
                }
            }
            self::assertFileDoesNotExist($root . '/.git/hooks/pre-commit');
        } finally {
            $hooks = glob($root . '/.git/hooks/*');
            foreach ($hooks === false ? [] : $hooks as $path) {
                unlink($path);
            }
            unlink($root . '/src/Clean.php');
            rmdir($root . '/src');
            rmdir($root . '/.git/hooks');
            rmdir($root . '/.git');
            rmdir($root);
        }
    }
}
