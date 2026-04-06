<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Analysis\Duplication;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Duplication\DuplicationDetector;
use Qualimetrix\Analysis\Duplication\NormalizedToken;
use Qualimetrix\Analysis\Duplication\TokenNormalizer;
use Qualimetrix\Configuration\ConfigurationProviderInterface;
use Qualimetrix\Core\Duplication\DuplicateBlock;
use Qualimetrix\Core\Duplication\DuplicateLocation;
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
        $this->tmpDir = sys_get_temp_dir() . '/qmx_dup_test_' . uniqid();
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

        $detector = $this->createDetector(minTokens: 20, minLines: 3);
        $blocks = $detector->detect([$file1, $file2]);

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

        $detector = $this->createDetector(minTokens: 20, minLines: 3);
        $blocks = $detector->detect([$file1, $file2]);

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

        $detector = $this->createDetector(minTokens: 20, minLines: 3);
        $blocks = $detector->detect([$file1, $file2]);

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

        $detector = $this->createDetector(minTokens: 5, minLines: 5);
        $blocks = $detector->detect([$file1, $file2]);

        self::assertEmpty($blocks, 'Should not detect duplication below minLines threshold');
    }

    public function testMinTokensFilter(): void
    {
        // Very short code below minTokens
        $code = '<?php $x = 1;';

        $file1 = $this->createFile('tiny1.php', $code);
        $file2 = $this->createFile('tiny2.php', $code);

        $detector = $this->createDetector(minTokens: 70, minLines: 3);
        $blocks = $detector->detect([$file1, $file2]);

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

        $detector = $this->createDetector(minTokens: 20, minLines: 3);
        $blocks = $detector->detect([$file]);

        self::assertNotEmpty($blocks, 'Should detect duplication within the same file');
    }

    public function testSameFileSelfDuplicationIsNotReported(): void
    {
        // Create a file with a large repetitive array constant where different
        // token windows can hash-match but extend to the same line range
        $code = "<?php\nclass Foo {\n    private const LIST = [\n";
        for ($i = 0; $i < 50; $i++) {
            $code .= "        'Class{$i}' => true,\n";
        }
        $code .= "    ];\n}\n";

        $file = $this->createFile('repetitive_array.php', $code);

        $detector = $this->createDetector(minTokens: 30, minLines: 5);
        $blocks = $detector->detect([$file]);

        // No block should have two identical locations (same file + same line range)
        foreach ($blocks as $block) {
            $locations = $block->locations;
            if (\count($locations) === 2) {
                $isSelfDuplicate = $locations[0]->file === $locations[1]->file
                    && $locations[0]->startLine === $locations[1]->startLine
                    && $locations[0]->endLine === $locations[1]->endLine;

                self::assertFalse(
                    $isSelfDuplicate,
                    'A block should not be reported as a duplicate of itself',
                );
            }
        }
    }

    public function testEmptyFileList(): void
    {
        $detector = $this->createDetector();
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

    private function createDetector(int $minTokens = 70, int $minLines = 5): DuplicationDetector
    {
        $configProvider = self::createStub(ConfigurationProviderInterface::class);
        $configProvider->method('getRuleOptions')->willReturn([
            'duplication.code-duplication' => [
                'min_tokens' => $minTokens,
                'min_lines' => $minLines,
            ],
        ]);

        return new DuplicationDetector($configProvider);
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
