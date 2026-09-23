<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Console\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Infrastructure\Console\ArtifactFile;

#[CoversClass(ArtifactFile::class)]
final class ArtifactFileTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/qmx-artifact-file-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o755, true);
    }

    protected function tearDown(): void
    {
        chmod($this->directory, 0o755);
        foreach (array_diff((array) scandir($this->directory), ['.', '..']) as $entry) {
            $path = $this->directory . '/' . $entry;
            is_dir($path) ? rmdir($path) : unlink($path);
        }
        rmdir($this->directory);
    }

    #[Test]
    public function itReplacesAnExistingTargetWholeAndLeavesNoTemporaryFile(): void
    {
        $target = $this->directory . '/report.json';
        file_put_contents($target, 'old');
        $file = new ArtifactFile($target, '--output');

        $file->refuseUnwritable();
        $file->replaceWith('new');

        self::assertSame('new', file_get_contents($target));
        self::assertSame(['report.json'], array_values(array_diff((array) scandir($this->directory), ['.', '..'])));
    }

    #[Test]
    public function itRefusesADirectoryNamingTheOptionThatNamedIt(): void
    {
        mkdir($this->directory . '/out');

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('Option --profile names "' . $this->directory . '/out", which is a directory.');

        (new ArtifactFile($this->directory . '/out', '--profile'))->refuseUnwritable();
    }

    #[Test]
    public function itRefusesATargetWhoseDirectoryDoesNotExist(): void
    {
        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('does not exist or is not writable');

        (new ArtifactFile($this->directory . '/missing/report.json', '--output'))->refuseUnwritable();
    }

    /** The write the precheck could not see is refused by the write itself. */
    #[Test]
    public function itRefusesAWriteThatFailsAfterThePrecheck(): void
    {
        $target = $this->directory . '/report.json';
        $file = new ArtifactFile($target, '--output');
        $file->refuseUnwritable();
        mkdir($target . '.tmp.' . getmypid());

        try {
            $file->replaceWith('content');
            self::fail('A write that fails must be refused.');
        } catch (ConfigurationRefusal $refusal) {
            self::assertStringContainsString('Failed to write the --output file', $refusal->getMessage());
            self::assertSame('--output', $refusal->origin()->locator());
        } finally {
            rmdir($target . '.tmp.' . getmypid());
        }

        self::assertFileDoesNotExist($target);
    }
}
