<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Infrastructure\Storage;

use AiMessDetector\Infrastructure\Storage\ChangeDetector;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

final class ChangeDetectorTest extends TestCase
{
    private ChangeDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new ChangeDetector();
    }

    public function testGetContentHashReturnsConsistentHash(): void
    {
        $file = new SplFileInfo(__FILE__);

        $hash1 = $this->detector->getContentHash($file);
        $hash2 = $this->detector->getContentHash($file);

        $this->assertSame($hash1, $hash2);
        $this->assertNotEmpty($hash1);
    }

    public function testGetContentHashDiffersForDifferentFiles(): void
    {
        $file1 = new SplFileInfo(__FILE__);
        $file2 = new SplFileInfo(\dirname(__FILE__) . '/SqliteStorageTest.php');

        $hash1 = $this->detector->getContentHash($file1);
        $hash2 = $this->detector->getContentHash($file2);

        $this->assertNotSame($hash1, $hash2);
    }

    public function testQuickCheckReturnsTrueForMatchingMetadata(): void
    {
        $file = new SplFileInfo(__FILE__);

        $result = $this->detector->quickCheck(
            $file,
            $file->getMTime(),
            $file->getSize(),
        );

        $this->assertTrue($result);
    }

    public function testQuickCheckReturnsFalseForDifferentMtime(): void
    {
        $file = new SplFileInfo(__FILE__);

        $result = $this->detector->quickCheck(
            $file,
            $file->getMTime() - 1,
            $file->getSize(),
        );

        $this->assertFalse($result);
    }

    public function testQuickCheckReturnsFalseForDifferentSize(): void
    {
        $file = new SplFileInfo(__FILE__);

        $result = $this->detector->quickCheck(
            $file,
            $file->getMTime(),
            $file->getSize() + 1,
        );

        $this->assertFalse($result);
    }
}
