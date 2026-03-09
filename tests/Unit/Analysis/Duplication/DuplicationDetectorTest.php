<?php

declare(strict_types=1);

namespace AiMessDetector\Tests\Unit\Analysis\Duplication;

use AiMessDetector\Analysis\Duplication\DuplicationDetector;
use AiMessDetector\Analysis\Duplication\NormalizedToken;
use AiMessDetector\Analysis\Duplication\TokenNormalizer;
use AiMessDetector\Core\Duplication\DuplicateBlock;
use AiMessDetector\Core\Duplication\DuplicateLocation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

#[CoversClass(DuplicationDetector::class)]
#[CoversClass(TokenNormalizer::class)]
#[CoversClass(NormalizedToken::class)]
#[CoversClass(DuplicateBlock::class)]
#[CoversClass(DuplicateLocation::class)]
final class DuplicationDetectorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/aimd_dup_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function testDetectsExactDuplicateAcrossFiles(): void
    {
        $code = <<<'PHP'
<?php

function processItems($items) {
    $result = [];
    foreach ($items as $item) {
        if ($item->isValid()) {
            $result[] = $item->transform();
        }
    }
    return $result;
}
PHP;

        $file1 = $this->createFile('file1.php', $code);
        $file2 = $this->createFile('file2.php', $code);

        $detector = new DuplicationDetector();
        $blocks = $detector->detect([$file1, $file2], minTokens: 20, minLines: 3);

        self::assertNotEmpty($blocks, 'Should detect duplication between identical files');
        self::assertCount(1, $blocks);

        $block = $blocks[0];
        self::assertCount(2, $block->locations);
        self::assertGreaterThanOrEqual(3, $block->lines);
    }

    public function testDetectsNearMissDuplication(): void
    {
        $code1 = <<<'PHP'
<?php

function processUsers($users) {
    $result = [];
    foreach ($users as $user) {
        if ($user->isActive()) {
            $result[] = $user->getName();
        }
    }
    return $result;
}
PHP;

        $code2 = <<<'PHP'
<?php

function processOrders($orders) {
    $result = [];
    foreach ($orders as $order) {
        if ($order->isActive()) {
            $result[] = $order->getName();
        }
    }
    return $result;
}
PHP;

        $file1 = $this->createFile('users.php', $code1);
        $file2 = $this->createFile('orders.php', $code2);

        $detector = new DuplicationDetector();
        $blocks = $detector->detect([$file1, $file2], minTokens: 20, minLines: 3);

        // Should detect duplication because variable names are normalized
        self::assertNotEmpty($blocks, 'Should detect near-miss duplication (different variable names)');
    }

    public function testNoDuplicationInDifferentCode(): void
    {
        $code1 = <<<'PHP'
<?php

function add($a, $b) {
    return $a + $b;
}
PHP;

        $code2 = <<<'PHP'
<?php

class UserService {
    public function findAll(): array {
        return $this->repository->findAll();
    }
}
PHP;

        $file1 = $this->createFile('math.php', $code1);
        $file2 = $this->createFile('service.php', $code2);

        $detector = new DuplicationDetector();
        $blocks = $detector->detect([$file1, $file2], minTokens: 20, minLines: 3);

        self::assertEmpty($blocks, 'Should not detect duplication in structurally different code');
    }

    public function testMinLinesFilter(): void
    {
        // Short duplicate — 2 lines
        $code1 = <<<'PHP'
<?php
$x = 1;
$y = 2;
PHP;

        $code2 = <<<'PHP'
<?php
$a = 1;
$b = 2;
PHP;

        $file1 = $this->createFile('short1.php', $code1);
        $file2 = $this->createFile('short2.php', $code2);

        $detector = new DuplicationDetector();
        $blocks = $detector->detect([$file1, $file2], minTokens: 5, minLines: 5);

        self::assertEmpty($blocks, 'Should not detect duplication below minLines threshold');
    }

    public function testMinTokensFilter(): void
    {
        // Very short code below minTokens
        $code = '<?php $x = 1;';

        $file1 = $this->createFile('tiny1.php', $code);
        $file2 = $this->createFile('tiny2.php', $code);

        $detector = new DuplicationDetector();
        $blocks = $detector->detect([$file1, $file2], minTokens: 70, minLines: 3);

        self::assertEmpty($blocks, 'Should skip files with fewer tokens than minTokens');
    }

    public function testSameFileDuplication(): void
    {
        $code = <<<'PHP'
<?php

function processA($items) {
    $result = [];
    foreach ($items as $item) {
        if ($item->isValid()) {
            $result[] = $item->transform();
        }
    }
    return $result;
}

function processB($data) {
    $result = [];
    foreach ($data as $item) {
        if ($item->isValid()) {
            $result[] = $item->transform();
        }
    }
    return $result;
}
PHP;

        $file = $this->createFile('same_file.php', $code);

        $detector = new DuplicationDetector();
        $blocks = $detector->detect([$file], minTokens: 20, minLines: 3);

        self::assertNotEmpty($blocks, 'Should detect duplication within the same file');
    }

    public function testEmptyFileList(): void
    {
        $detector = new DuplicationDetector();
        $blocks = $detector->detect([]);

        self::assertSame([], $blocks);
    }

    public function testDuplicateBlockVOmethods(): void
    {
        $block = new DuplicateBlock(
            locations: [
                new DuplicateLocation('a.php', 10, 20),
                new DuplicateLocation('b.php', 30, 40),
                new DuplicateLocation('c.php', 50, 60),
            ],
            lines: 11,
            tokens: 50,
        );

        self::assertSame(3, $block->occurrences());
        self::assertSame('a.php', $block->primaryLocation()->file);
        self::assertCount(2, $block->relatedLocations());
        self::assertSame('b.php', $block->relatedLocations()[0]->file);
    }

    public function testDuplicateLocationVO(): void
    {
        $loc = new DuplicateLocation('src/Foo.php', 10, 25);

        self::assertSame(16, $loc->lineCount());
        self::assertSame('src/Foo.php:10-25', $loc->toString());
    }

    private function createFile(string $name, string $content): SplFileInfo
    {
        $path = $this->tmpDir . '/' . $name;
        file_put_contents($path, $content);

        return new SplFileInfo($path);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
