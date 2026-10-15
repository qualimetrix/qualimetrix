<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Functional;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Baseline\BaselineDocumentReader;
use Qualimetrix\Analysis\Policy\Baseline\BaselineDocumentWriter;
use Qualimetrix\Analysis\Policy\Baseline\BaselineLockFailure;
use Qualimetrix\Analysis\Policy\Baseline\BaselineReadFailure;

#[CoversClass(BaselineDocumentReader::class)]
#[CoversClass(BaselineDocumentWriter::class)]
final class BaselineEnvironmentFailureTest extends TestCase
{
    #[Test]
    public function itNamesTheBaselineLockOperationAndKeepsTheDestinationIntact(): void
    {
        $root = sys_get_temp_dir() . '/qmx-baseline-lock-' . bin2hex(random_bytes(6));
        mkdir($root, 0700);
        $path = $root . '/baseline.json';
        file_put_contents($path, 'before');
        $lock = fopen($path . '.lock', 'w');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, \LOCK_EX | \LOCK_NB));
        try {
            $snapshot = BaselineDocumentWriter::snapshot($path);
            try {
                (new BaselineDocumentWriter(0.02))->replace($snapshot['target'], 'after', $snapshot['hash']);
                self::fail('The held sibling lock must refuse the replacement.');
            } catch (BaselineLockFailure $failure) {
                self::assertSame($path, $failure->path);
                self::assertStringContainsString('exclusive lock', $failure->getMessage());
                self::assertStringContainsString('retry', $failure->getMessage());
                self::assertNotNull($failure->getPrevious());
                self::assertSame('before', file_get_contents($path));
            }
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
            unlink($path . '.lock');
            unlink($path);
            rmdir($root);
        }
    }

    #[Test]
    public function itClassifiesAnExistingUnreadableBaselineAsAnEnvironmentFailure(): void
    {
        $path = sys_get_temp_dir() . '/qmx-baseline-read-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($path, '{}');
        chmod($path, 0000);
        try {
            if (is_readable($path)) {
                self::markTestSkipped('The current user can read a mode-zero file.');
            }
            self::expectException(BaselineReadFailure::class);
            self::expectExceptionMessage($path);
            (new BaselineDocumentReader())->preflight($path);
        } finally {
            chmod($path, 0600);
            unlink($path);
        }
    }
}
