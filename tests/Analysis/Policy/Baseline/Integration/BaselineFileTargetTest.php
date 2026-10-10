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

#[CoversClass(BaselineDocumentWriter::class)]
final class BaselineFileTargetTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = TempDirectory::create('qmx-baseline-target-');
    }

    protected function tearDown(): void
    {
        chmod($this->tempDir, 0o700);
        TempDirectory::remove($this->tempDir);
    }

    #[Test]
    public function itLeavesTheReferentOfAnExposedSiblingLockAbsent(): void
    {
        $baseline = $this->tempDir . '/baseline.json';
        $victim = $this->tempDir . '/victim';
        $destination = BaselineDocumentWriter::snapshot($baseline);
        chmod($this->tempDir, 0o777);
        symlink($victim, $baseline . '.lock');

        try {
            (new BaselineDocumentWriter(0.1))->replace($destination['target'], '{}', $destination['hash']);
            self::fail('The writer followed an exposed sibling lock.');
        } catch (FileTargetFailure $failure) {
            self::assertSame(FileTargetFailureKind::ExposedLink, $failure->kind);
        }

        self::assertFileDoesNotExist($victim);
        self::assertFileDoesNotExist($baseline);
        self::assertTrue(is_link($baseline . '.lock'));
    }

    #[Test]
    public function itRefusesDescriptorsAndStreamsAsBaselineDocuments(): void
    {
        foreach (['php://stdout', '/dev/null'] as $spelling) {
            try {
                BaselineDocumentWriter::snapshot($spelling);
                self::fail('A baseline document accepted ' . $spelling . '.');
            } catch (FileTargetFailure $failure) {
                self::assertStringContainsString('requires a regular file path', $failure->getMessage(), $spelling);
            }
        }
    }
}
