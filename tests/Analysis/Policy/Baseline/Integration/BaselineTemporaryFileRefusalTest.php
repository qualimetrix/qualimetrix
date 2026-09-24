<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Policy\Baseline\BaselineDocumentWriter;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Support\TempDirectory;

/**
 * The temporary file a baseline is written to before it is moved into place,
 * refused with the system's reason and without a PHP diagnostic.
 *
 * The temporary path is `<target>.tmp.<pid>`, and a directory standing there
 * is the one input that fails that write without a read-only mount or a
 * non-root user: a stale directory of that name is enough.
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
    public function itRefusesATemporaryPathItCannotWriteWithTheSystemsReason(): void
    {
        $path = $this->tempDir . '/baseline.json';
        $temporary = $path . '.tmp.' . getmypid();
        mkdir($temporary);

        $refusal = self::refusalWithoutDiagnostics(static fn() => (new BaselineDocumentWriter(0.1))->create($path, '{}'));

        self::assertSame(
            \sprintf('Cannot write the baseline to %s: Failed to open stream: Is a directory', $temporary),
            $refusal->summary(),
        );
        self::assertFileDoesNotExist($path);
        self::assertDirectoryExists($temporary, 'the cleanup must not remove what it did not create');
    }

    /**
     * @param callable(): void $write
     */
    private static function refusalWithoutDiagnostics(callable $write): ConfigurationRefusal
    {
        $diagnostics = [];
        // PHPUnit narrows the level while its own handler is installed, which
        // would hide the warning a raw call lets out.
        $level = error_reporting(\E_ALL);
        set_error_handler(static function (int $level, string $message) use (&$diagnostics): bool {
            if ((error_reporting() & $level) !== 0) {
                $diagnostics[] = $message;
            }

            return true;
        });

        try {
            $write();
        } catch (ConfigurationRefusal $refusal) {
            return $refusal;
        } finally {
            restore_error_handler();
            error_reporting($level);
            self::assertSame([], $diagnostics);
        }

        self::fail('The writer accepted a temporary path it cannot write.');
    }
}
