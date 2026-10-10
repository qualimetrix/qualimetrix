<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Baseline\BaselineDocumentWriter;
use Qualimetrix\Core\FileTarget\FileTargetFailure;
use Qualimetrix\Core\FileTarget\FileTargetFailureKind;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\TempDirectory;

/**
 * A refused sibling publication leaves the target and the conflicting entry
 * untouched and emits no native PHP diagnostic.
 */
#[CoversClass(BaselineDocumentWriter::class)]
final class BaselineTemporaryFileRefusalTest extends TestCase
{
    private string $tempDir = '';

    protected function setUp(): void
    {
        $this->tempDir = TempDirectory::create('qmx_baseline_temporary_');
    }

    protected function tearDown(): void
    {
        TempDirectory::remove($this->tempDir);
    }

    #[Test]
    public function itRefusesAnUnusableSiblingLockWithoutDiagnostics(): void
    {
        $path = $this->tempDir . '/baseline.json';
        $lock = $path . '.lock';
        mkdir($lock);
        $destination = BaselineDocumentWriter::snapshot($path);

        $failure = self::failureWithoutDiagnostics(
            static fn() => (new BaselineDocumentWriter(0.1))->replace($destination['target'], '{}', $destination['hash']),
        );

        self::assertSame(FileTargetFailureKind::Directory, $failure->kind);
        self::assertFileDoesNotExist($path);
        self::assertDirectoryExists($lock);
    }

    /** @param callable(): void $write */
    private static function failureWithoutDiagnostics(callable $write): FileTargetFailure
    {
        $diagnostics = [];
        $level = error_reporting(\E_ALL);
        set_error_handler(static function (int $level, string $message) use (&$diagnostics): bool {
            if ((error_reporting() & $level) !== 0) {
                $diagnostics[] = $message;
            }

            return true;
        });

        try {
            $write();
        } catch (FileTargetFailure $failure) {
            return $failure;
        } finally {
            restore_error_handler();
            error_reporting($level);
            self::assertSame([], $diagnostics);
        }

        self::fail('The writer accepted an unusable sibling lock.');
    }
}
