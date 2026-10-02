<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Core\FileTarget\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Environment\EnvironmentFailureInterface;
use Qualimetrix\Core\FileTarget\FileTargetFailure;
use Qualimetrix\Core\FileTarget\FileTargetFailureKind;
use Qualimetrix\Core\FileTarget\ProcessOwner;
use ReflectionClass;

#[CoversClass(ProcessOwner::class)]
final class ProcessOwnerTest extends TestCase
{
    #[Test]
    public function itUsesTheEffectiveUidWhenPosixIsAvailable(): void
    {
        if (!\function_exists('posix_geteuid')) {
            self::markTestSkipped('POSIX is not available in this PHP build');
        }

        self::assertSame(posix_geteuid(), ProcessOwner::effectiveUid(sys_get_temp_dir()));
    }

    #[Test]
    public function itMarksTargetFailuresAsEnvironmentFailuresWithACompleteMessage(): void
    {
        $failure = new FileTargetFailure(FileTargetFailureKind::Unopenable, '/output/report.json', 'cannot open target', 'permission denied');

        self::assertTrue((new ReflectionClass($failure))->implementsInterface(EnvironmentFailureInterface::class));
        self::assertStringContainsString('/output/report.json', $failure->getMessage());
        self::assertStringContainsString('permission denied', $failure->getMessage());
    }

    #[Test]
    public function itRemovesFallbackProbesOnSuccessAndLaterRefusal(): void
    {
        $base = realpath(sys_get_temp_dir()) . '/qmx-owner-' . bin2hex(random_bytes(6));
        mkdir($base, 0777);
        chmod($base, 0777);
        file_put_contents($base . '/target', 'safe');
        symlink('target', $base . '/link');
        $root = \dirname(__DIR__, 4);
        $script = <<<'PHP'
require $argv[1];
$uid = \Qualimetrix\Core\FileTarget\ProcessOwner::effectiveUid($argv[2]);
try {
    \Qualimetrix\Core\FileTarget\TargetPath::resolve($argv[2] . '/link');
    echo json_encode(['uid' => $uid, 'refused' => false]);
} catch (\Qualimetrix\Core\FileTarget\FileTargetFailure $failure) {
    echo json_encode(['uid' => $uid, 'refused' => $failure->kind === \Qualimetrix\Core\FileTarget\FileTargetFailureKind::ExposedLink]);
}
PHP;

        try {
            $process = proc_open(
                [\PHP_BINARY, '-d', 'disable_functions=posix_geteuid', '-r', $script, $root . '/vendor/autoload.php', $base],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), (string) $error);
            self::assertIsString($output);
            $result = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);
            self::assertSame(posix_geteuid(), $result['uid']);
            self::assertTrue($result['refused']);
            self::assertSame([], glob($base . '/.qmx-*'));
        } finally {
            unlink($base . '/link');
            unlink($base . '/target');
            rmdir($base);
        }
    }
}
