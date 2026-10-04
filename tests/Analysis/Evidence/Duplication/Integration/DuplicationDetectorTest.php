<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Duplication\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Duplication\DuplicationDetector;
use Qualimetrix\Analysis\Evidence\Duplication\DuplicationResultProvider;
use Qualimetrix\Analysis\Evidence\Duplication\Matching\DuplicateBlock;
use Qualimetrix\Analysis\Evidence\Duplication\Matching\DuplicateLocation;
use Qualimetrix\Analysis\Evidence\Duplication\Normalization\TokenNormalizer;
use Qualimetrix\Analysis\Evidence\Duplication\Normalization\TokenStream;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;
use SplFileInfo;

#[CoversClass(DuplicationDetector::class)]
#[CoversClass(TokenNormalizer::class)]
#[CoversClass(TokenStream::class)]
#[CoversClass(DuplicateBlock::class)]
#[CoversClass(DuplicateLocation::class)]
final class DuplicationDetectorTest extends TestCase
{
    private string $tmpDir;
    private DuplicationResultProvider $resultProvider;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/qmx_dup_test_' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    #[Test]
    public function itDetectsExactDuplicateAcrossFiles(): void
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
        $blocks = $this->inspect($detector, [$file1, $file2]);

        self::assertNotEmpty($blocks, 'Should detect duplication between identical files');
        self::assertCount(1, $blocks);

        $block = $blocks[0];
        self::assertCount(2, $block->locations);
        self::assertGreaterThanOrEqual(3, $block->locations[0]->codeLines);
    }

    #[Test]
    public function itDetectsNearMissDuplication(): void
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
        $blocks = $this->inspect($detector, [$file1, $file2]);

        // Should detect duplication because variable names are normalized
        self::assertNotEmpty($blocks, 'Should detect near-miss duplication (different variable names)');
    }

    #[Test]
    public function itHashesTheCompleteNormalizedTokenSequenceRatherThanBlockSize(): void
    {
        $first = <<<'PHP'
<?php
function alpha() {
    $firstValue = Source::load()->one()->two();
    return $firstValue;
}
PHP;
        $second = <<<'PHP'
<?php
function beta() {
    $secondValue = Source::load()->one()->two();
    return $secondValue;
}
PHP;
        $third = <<<'PHP'
<?php
function gamma() {
    $thirdValue = Other::read()->four()->five();
    return $thirdValue;
}
PHP;
        $fourth = <<<'PHP'
<?php
function delta() {
    $fourthValue = Other::read()->four()->five();
    return $fourthValue;
}
PHP;

        $blocks = $this->inspect($this->createDetector(minTokens: 10, minLines: 3), [
            $this->createFile('first.php', $first),
            $this->createFile('second.php', $second),
            $this->createFile('third.php', $third),
            $this->createFile('fourth.php', $fourth),
        ]);

        self::assertCount(2, $blocks);
        self::assertSame($blocks[0]->locations[0]->codeLines, $blocks[1]->locations[0]->codeLines);
        self::assertSame($blocks[0]->tokens, $blocks[1]->tokens);
        self::assertNotSame($blocks[0]->contentHash, $blocks[1]->contentHash);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $blocks[0]->contentHash);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $blocks[1]->contentHash);
    }

    #[Test]
    public function itFindsNoDuplicationInDifferentCode(): void
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
        $blocks = $this->inspect($detector, [$file1, $file2]);

        self::assertEmpty($blocks, 'Should not detect duplication in structurally different code');
    }

    #[Test]
    public function itAppliesMinLinesFilter(): void
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
        $blocks = $this->inspect($detector, [$file1, $file2]);

        self::assertEmpty($blocks, 'Should not detect duplication below minLines threshold');
    }

    #[Test]
    public function itAppliesMinTokensFilter(): void
    {
        // Very short code below minTokens
        $code = '<?php $x = 1;';

        $file1 = $this->createFile('tiny1.php', $code);
        $file2 = $this->createFile('tiny2.php', $code);

        $detector = $this->createDetector(minTokens: 70, minLines: 3);
        $blocks = $this->inspect($detector, [$file1, $file2]);

        self::assertEmpty($blocks, 'Should skip files with fewer tokens than minTokens');
    }

    #[Test]
    public function itDetectsSameFileDuplication(): void
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
        $blocks = $this->inspect($detector, [$file]);

        self::assertNotEmpty($blocks, 'Should detect duplication within the same file');
    }

    #[Test]
    public function itRetainsDistantFirstAndAllSubsequentDuplicateOccurrences(): void
    {
        $duplicate = <<<'PHP'
function sharedBlock($items) {
    $result = [];
    foreach ($items as $item) {
        $result[] = $item->transform();
    }
    return $result;
}
PHP;
        $filler = implode("\n", array_map(
            static fn(int $i): string => "function unrelated{$i}() { return {$i}; }",
            range(1, 400),
        ));

        $file = $this->createFile('distant_occurrences.php', "<?php\n{$duplicate}\n{$filler}\n{$duplicate}\n{$duplicate}");

        $blocks = $this->inspect($this->createDetector(minTokens: 20, minLines: 3), [$file]);

        self::assertNotEmpty($blocks);
        self::assertGreaterThanOrEqual(2, \count($blocks));

        $allLocations = array_merge(...array_map(static fn(DuplicateBlock $block): array => $block->locations, $blocks));
        $startLines = array_unique(array_map(static fn(DuplicateLocation $location): int => $location->startLine, $allLocations));

        self::assertContains(2, $startLines);
        self::assertGreaterThanOrEqual(3, \count($startLines));
    }

    #[Test]
    public function itDoesNotReportSameFileSelfDuplication(): void
    {
        // Create a file with a large repetitive array where different
        // token windows can hash-match but extend to the same line range.
        // Built as a local variable (not a const/property declaration) so
        // the const-array data suppression never applies here — this test
        // targets the same-file overlap guard in findDuplicateBlocks(),
        // a different mechanism entirely.
        $code = "<?php\nfunction buildList() {\n    \$list = [\n";
        for ($i = 0; $i < 50; $i++) {
            $code .= "        'Class{$i}' => true,\n";
        }
        $code .= "    ];\n\n    return \$list;\n}\n";

        $file = $this->createFile('repetitive_array.php', $code);

        $detector = $this->createDetector(minTokens: 30, minLines: 5);
        $blocks = $this->inspect($detector, [$file]);

        self::assertNotEmpty($blocks, 'The repetitive array should still produce candidate blocks');

        // No block may carry two locations of one file that share a line:
        // that is the structure matching itself at a shifted offset.
        foreach ($blocks as $block) {
            foreach ($block->locations as $i => $first) {
                foreach (\array_slice($block->locations, $i + 1) as $second) {
                    $sharesALine = $first->pathString() === $second->pathString()
                        && $first->startLine <= $second->endLine
                        && $second->startLine <= $first->endLine;

                    self::assertFalse($sharesALine, 'A block should not be reported as a duplicate of itself');
                }
            }
        }
    }

    #[Test]
    public function itDoesNotReportDuplicationEntirelyInsideConstArrays(): void
    {
        // Same shape, different literal content, two rows of the same const
        // array in one file — mirrors the real bug: HealthMetricCatalog's
        // METRICS/RANGES tables have many rows sharing this shape, so pairs
        // of rows normalize to identical token sequences and used to match
        // each other. A single-file, multi-row fixture keeps the matched
        // window comfortably inside the array on both sides (unlike a
        // cross-file, single-row fixture, where the window's start/end can
        // land on the file's own boilerplate instead).
        $file = $this->createFile('const_rows.php', $this->constArrayFixture('MetricHints', 'METRICS'));

        // 17 tokens is the exact size of one row; a match must span at
        // least that much to be found by the rolling hash at all.
        $detector = $this->createDetector(minTokens: 17, minLines: 3);
        $blocks = $this->inspect($detector, [$file]);

        self::assertEmpty($blocks, 'A duplicate block entirely inside a const array declaration must not be reported by default');
    }

    #[Test]
    public function itDoesNotReportDuplicationEntirelyInsidePropertyArrayInitializers(): void
    {
        $file = $this->createFile('prop_rows.php', $this->propertyArrayFixture('Defaults'));

        $detector = $this->createDetector(minTokens: 17, minLines: 3);
        $blocks = $this->inspect($detector, [$file]);

        self::assertEmpty($blocks, 'A duplicate block entirely inside a static property array initializer must not be reported by default');
    }

    #[Test]
    public function itReportsDuplicationInMethodBodyArrayLiterals(): void
    {
        // Same array-literal shape as the const-array fixtures above, but
        // built inside a method body — must still be detected, proving the
        // suppression is scoped to const/property declarations only.
        $code = static fn(string $label, string $direction, string $goodValue): string => <<<PHP
<?php

final class Builder
{
    public function build(): array
    {
        return [
            'complexity.ccn' => [
                'label' => '{$label}',
                'direction' => '{$direction}',
                'goodValue' => '{$goodValue}',
            ],
            'complexity.wmc' => [
                'label' => '{$label}2',
                'direction' => '{$direction}',
                'goodValue' => '{$goodValue}2',
            ],
        ];
    }
}
PHP;

        $fileA = $this->createFile('method_array_a.php', $code('Cyclomatic', 'lower', 'below four'));
        $fileB = $this->createFile('method_array_b.php', $code('Complexity', 'down', 'under five'));

        $detector = $this->createDetector(minTokens: 30, minLines: 5);
        $blocks = $this->inspect($detector, [$fileA, $fileB]);

        self::assertNotEmpty($blocks, 'Array literals built in a method body are executable code and must still be detected');
    }

    #[Test]
    public function itReportsDuplicationCrossingTheDataCodeBoundary(): void
    {
        // Const array followed immediately by an identical method: the
        // rolling hash match window naturally extends from inside the
        // `const` declaration across its terminating `;` into the method
        // body. Only one side of that window is data, so it must NOT be
        // suppressed.
        $code = <<<'PHP'
<?php

final class BoundaryCase
{
    private const array MAP = [
        'complexity.ccn' => [
            'label' => 'Cyclomatic',
            'direction' => 'lower',
            'goodValue' => 'below four',
        ],
        'complexity.wmc' => [
            'label' => 'Weighted',
            'direction' => 'lower',
            'goodValue' => 'below ten',
        ],
    ];

    public function identicalHelper(): int
    {
        $value = 1;
        $value += 2;
        $value += 3;
        $value += 4;

        return $value;
    }
}
PHP;

        // Only the class name differs, so the match cannot start there —
        // the hash search finds the next window where everything (from
        // `private const ...` onward) is byte-for-byte identical.
        $fileA = $this->createFile('boundary_a.php', str_replace('BoundaryCase', 'BoundaryCaseA', $code));
        $fileB = $this->createFile('boundary_b.php', str_replace('BoundaryCase', 'BoundaryCaseB', $code));

        $detector = $this->createDetector(minTokens: 30, minLines: 5);
        $blocks = $this->inspect($detector, [$fileA, $fileB]);

        self::assertNotEmpty($blocks, 'A block spanning both a const array and executable code must still be reported');
    }

    #[Test]
    public function itReportsTheLinesTheMatchedTokensActuallyOccupy(): void
    {
        // The two files differ only in the return type on line 4, so the
        // match starts at the `{` alone on line 5 and ends at the class's
        // closing method brace on line 13; the unmatched class brace is excluded.
        $body = <<<'PHP'
    {
        $out = [];
        foreach ($rows as $row) {
            if ($row->isActive()) {
                $out[] = $row->name();
            }
        }
        return $out;
    }
}
PHP;
        $first = "<?php\nfinal class First\n{\n    public function compute(array \$rows): array\n{$body}\n";
        $second = "<?php\nfinal class Second\n{\n    public function compute(array \$rows): iterable\n{$body}\n";

        $blocks = $this->inspect($this->createDetector(minTokens: 20, minLines: 5), [
            $this->createFile('first.php', $first),
            $this->createFile('second.php', $second),
        ]);

        self::assertCount(1, $blocks);
        self::assertSame(
            ['first.php:5-13', 'second.php:5-13'],
            array_map($this->shortLocation(...), $blocks[0]->locations),
        );
        self::assertSame(9, $blocks[0]->locations[0]->codeLines);
        self::assertNotNull($blocks[0]->locations[0]->hint);
        self::assertStringStartsWith('$out = [];', $blocks[0]->locations[0]->hint);
    }

    #[Test]
    public function itReportsIdenticalBlocksInOneFileThatTouchWithoutSharingALine(): void
    {
        $block = <<<'PHP'
    $x = alpha($a, 1, 2);
    $y = beta($x, 3, 4);
    $z = gamma($y, 5, 6);
    $w = delta($z, 7, 8);
    $v = epsilon($w, 9, 10);
PHP;
        $file = $this->createFile('touching.php', "<?php\nfunction f(\$a) {\n{$block}\n{$block}\n    return \$v;\n}\n");

        $blocks = $this->inspect($this->createDetector(minTokens: 20, minLines: 5), [$file]);

        self::assertCount(1, $blocks);
        self::assertSame(
            ['touching.php:3-7', 'touching.php:8-12'],
            array_map($this->shortLocation(...), $blocks[0]->locations),
        );
        self::assertSame(5, $blocks[0]->locations[0]->codeLines);
    }

    #[Test]
    public function itReportsEveryCopyOfARepeatedBlockInOneBlock(): void
    {
        $files = [];
        foreach (['one', 'two', 'three'] as $name) {
            $files[] = $this->createFile("{$name}.php", $this->repeatedClassFixture($name));
        }

        $blocks = $this->inspect($this->createDetector(minTokens: 20, minLines: 5), $files);

        self::assertCount(1, $blocks);
        self::assertSame(3, $blocks[0]->occurrences());
    }

    #[Test]
    public function itReportsABlockRepeatedMoreThanAHundredTimes(): void
    {
        $files = [];
        for ($i = 0; $i < 101; $i++) {
            $files[] = $this->createFile("copy{$i}.php", $this->repeatedClassFixture("Copy{$i}"));
        }

        $blocks = $this->inspect($this->createDetector(minTokens: 20, minLines: 5), $files);

        self::assertCount(1, $blocks, 'A block copied 101 times must be reported, not skipped');
        self::assertSame(101, $blocks[0]->occurrences());
    }

    #[Test]
    public function itKeepsTheLongerMatchOfTwoCopiesWhenAThirdCopyDivergesEarlier(): void
    {
        $shared = implode("\n", array_map(
            static fn(int $i): string => "    \$v{$i} = shared{$i}(\$a, {$i});",
            range(1, 6),
        ));
        $tail = implode("\n", array_map(
            static fn(int $i): string => "    \$w{$i} = tail{$i}(\$a, {$i});",
            range(1, 6),
        ));
        $long = "<?php\nfunction f(\$a) {\n{$shared}\n{$tail}\n}\n";
        $short = "<?php\nfunction f(\$a) {\n{$shared}\n    echo \$a;\n    echo \$a;\n}\n";

        $blocks = $this->inspect($this->createDetector(minTokens: 20, minLines: 3), [
            $this->createFile('a.php', $long),
            $this->createFile('b.php', $long),
            $this->createFile('c.php', $short),
        ]);

        $shapes = array_map(
            fn(DuplicateBlock $block): string => implode(', ', array_map($this->shortLocation(...), $block->locations)),
            $blocks,
        );
        sort($shapes);

        self::assertSame(
            ['a.php:2-15, b.php:2-15', 'a.php:2-8, b.php:2-8, c.php:2-8'],
            $shapes,
        );
    }

    #[Test]
    public function itHandlesEmptyFileList(): void
    {
        $detector = $this->createDetector();
        $blocks = $this->inspect($detector, []);

        self::assertSame([], $blocks);
    }

    #[Test]
    public function itReportsBothEligibleSegmentsAcrossAnUnmatchedMethodCloser(): void
    {
        $blocks = $this->inspectSegmentFixture(4, 4);

        self::assertSame(
            [['segment_a.php:11-17', 'segment_b.php:11-17'], ['segment_a.php:6-9', 'segment_b.php:6-9']],
            array_map(fn(DuplicateBlock $block): array => array_map($this->shortLocation(...), $block->locations), $blocks),
        );
    }

    #[Test]
    public function itPreservesSixFilePairsAcrossDisconnectedLongerMatches(): void
    {
        $common = implode("\n", array_map(static fn(int $i): string => "    \$value = common{$i}(\$input, 1, 2);", range(1, 4)));
        $files = [];
        foreach (['a' => 'left', 'b' => 'left', 'c' => 'right', 'd' => 'right'] as $name => $side) {
            $tail = implode("\n", array_map(static fn(int $i): string => "    \$value = {$side}{$i}(\$input, 3, 4);", range(1, 4)));
            $files[] = $this->createFile("{$name}.php", "<?php\nfunction run(\$input) {\n{$common}\n{$tail}\n    return \$value;\n}\n");
        }
        $blocks = $this->inspect($this->createDetector(minTokens: 30, minLines: 4), $files);

        self::assertSame([2, 2, 4], array_map(static fn(DuplicateBlock $block): int => $block->occurrences(), $blocks));
        $pairs = [];
        foreach ($blocks as $block) {
            foreach ($block->locations as $i => $left) {
                foreach (\array_slice($block->locations, $i + 1) as $right) {
                    $pairs[basename($left->pathString()) . ':' . basename($right->pathString())] = true;
                }
            }
        }
        $pairs = array_keys($pairs);
        sort($pairs);
        self::assertSame(['a.php:b.php', 'a.php:c.php', 'a.php:d.php', 'b.php:c.php', 'b.php:d.php', 'c.php:d.php'], $pairs);
    }

    #[Test]
    public function itKeepsTokenAdjacentCopiesAtASemicolonAndForeachBoundary(): void
    {
        $statementCopy = "\$x = alpha(\$input, 1, 2);\n\$y = beta(\$x, 3, 4);\n\$z = gamma(\$y, 5, 6);";
        $loopCopy = "foreach (\$rows as \$row) {\n    \$x = alpha(\$row, 1, 2);\n    \$y = beta(\$x, 3, 4);\n}";
        foreach ([[$statementCopy, ['boundary.php:2-4', 'boundary.php:4-6']], [$loopCopy, ['boundary.php:2-5', 'boundary.php:5-8']]] as [$copy, $expected]) {
            $blocks = $this->inspect($this->createDetector(minTokens: 20, minLines: 3), [
                $this->createFile('boundary.php', "<?php\n{$copy} {$copy}\n"),
            ]);
            self::assertCount(1, $blocks);
            self::assertSame($expected, array_map($this->shortLocation(...), $blocks[0]->locations));
        }
    }

    #[Test]
    public function itKeepsTheWholeMatchWhenNoBalancedSegmentIsEligible(): void
    {
        $blocks = $this->inspectSegmentFixture(2, 1);

        self::assertCount(1, $blocks);
        self::assertSame(['segment_a.php:5-13', 'segment_b.php:5-13'], array_map($this->shortLocation(...), $blocks[0]->locations));
        self::assertSame(9, $blocks[0]->locations[0]->codeLines);
    }

    #[Test]
    public function itLeavesASmallTailUnreportedBesideAnEligibleFollowingMethod(): void
    {
        $blocks = $this->inspectSegmentFixture(1, 4);

        self::assertCount(1, $blocks);
        self::assertSame(['segment_a.php:8-14', 'segment_b.php:8-14'], array_map($this->shortLocation(...), $blocks[0]->locations));
    }

    #[Test]
    public function itReportsAdjacentTokenCopiesThatShareAClosingBraceLine(): void
    {
        $copy = "if (\$ready) {\n    \$x = alpha(\$input, 1, 2);\n    \$y = beta(\$x, 3, 4);\n}";
        $source = "<?php\n{$copy} {$copy}\n";
        $blocks = $this->inspect($this->createDetector(minTokens: 20, minLines: 3), [$this->createFile('adjacent.php', $source)]);

        self::assertCount(1, $blocks);
        self::assertSame(['adjacent.php:2-5', 'adjacent.php:5-8'], array_map($this->shortLocation(...), $blocks[0]->locations));
    }

    #[Test]
    public function itUsesEachCopysOriginalByteRangeForItsHint(): void
    {
        $first = "<?php\n// one\n\$first = consume('first literal', 1, 2);\n\$next = finish(\$first, 3, 4);\n";
        $second = "<?php\n// two\n\$second = consume('second literal', 8, 9);\n\$last = finish(\$second, 5, 6);\n";
        $blocks = $this->inspect($this->createDetector(minTokens: 20, minLines: 2), [
            $this->createFile('hint_a.php', $first),
            $this->createFile('hint_b.php', $second),
        ]);

        self::assertCount(1, $blocks);
        self::assertSame("\$first = consume('first literal', 1, 2); \$next = finish(\$first, 3, 4);", $blocks[0]->locations[0]->hint);
        self::assertSame("\$second = consume('second literal', 8, 9); \$last = finish(\$second, 5, 6);", $blocks[0]->locations[1]->hint);
    }

    #[Test]
    public function itReportsDataMatchedWithExecutableCodeInBothFileOrders(): void
    {
        $rows = "[\n    'first' => ['warning' => 1, 'error' => 2],\n    'second' => ['warning' => 3, 'error' => 4],\n    'third' => ['warning' => 5, 'error' => 6],\n]";
        $data = $this->createFile('data.php', "<?php\nclass Data { const MAP = {$rows}; }\n");
        $code = $this->createFile('code.php', "<?php\nfunction make() { return {$rows}; }\n");
        foreach ([[$data, $code], [$code, $data]] as $files) {
            $blocks = $this->inspect($this->createDetector(minTokens: 30, minLines: 3), $files);
            self::assertCount(1, $blocks);
            self::assertSame(['code.php', 'data.php'], array_map(static fn(DuplicateLocation $copy): string => basename($copy->pathString()), $blocks[0]->locations));
        }
    }

    #[Test]
    public function itCountsAllRowsOfAMultilineHeredocToken(): void
    {
        $source = static fn(string $name): string => "<?php\nfunction {$name}() {\n    return <<<SQL\nSELECT id\nFROM records\nWHERE active = 1\nSQL;\n}\n";
        $blocks = $this->inspect($this->createDetector(minTokens: 5, minLines: 7), [
            $this->createFile('heredoc_a.php', $source('first')),
            $this->createFile('heredoc_b.php', $source('second')),
        ]);

        self::assertCount(1, $blocks);
        self::assertSame(['heredoc_a.php:2-8', 'heredoc_b.php:2-8'], array_map($this->shortLocation(...), $blocks[0]->locations));
        self::assertSame([7, 7], array_map(static fn(DuplicateLocation $copy): int => $copy->codeLines, $blocks[0]->locations));
    }

    /** @return list<DuplicateBlock> */
    private function inspectSegmentFixture(int $tailLines, int $summaryLines): array
    {
        $tail = implode("\n", array_map(static fn(int $i): string => "        \$x = tail{$i}(\$input, 1, 2);", range(1, $tailLines)));
        $summary = implode("\n", array_map(static fn(int $i): string => "        \$y = summary{$i}(\$input, 3, 4);", range(1, $summaryLines)));
        $source = static fn(string $name): string => "<?php\nfinal class {$name}\n{\n    public function prepare() {\n        \$prefix = unique{$name};\n{$tail}\n    }\n    public function summarize() {\n{$summary}\n        return \$input;\n    }\n}\n";

        return $this->inspect($this->createDetector(minTokens: 30, minLines: 4), [
            $this->createFile('segment_a.php', $source('A')),
            $this->createFile('segment_b.php', $source('B')),
        ]);
    }

    #[Test]
    public function itExercisesDuplicateBlockVoMethods(): void
    {
        $block = new DuplicateBlock(
            locations: [
                new DuplicateLocation(RelativePath::fromString('a.php'), 10, 20, 11, null),
                new DuplicateLocation(RelativePath::fromString('b.php'), 30, 40, 11, null),
                new DuplicateLocation(RelativePath::fromString('c.php'), 50, 60, 11, null),
            ],
            tokens: 50,
            contentHash: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        );

        self::assertSame(3, $block->occurrences());
        self::assertSame('a.php', $block->locations[0]->file->value());
        self::assertCount(3, $block->locations);
        self::assertSame('b.php', $block->locations[1]->file->value());
        self::assertSame('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $block->contentHash);
    }

    #[Test]
    public function itExercisesDuplicateLocationVo(): void
    {
        $loc = new DuplicateLocation(RelativePath::fromString('src/Foo.php'), 10, 25, 16, null);

        self::assertSame(16, $loc->lineCount());
        self::assertSame('src/Foo.php:10-25', $loc->toString());
    }

    #[Test]
    public function itReadsTheCurrentTypedThresholdsOnEachInspectionWithoutTheRawDoor(): void
    {
        $options = new \Qualimetrix\Analysis\Evidence\Duplication\CodeDuplicationOptions(min_tokens: 1000, min_lines: 0);
        $configuration = $this->createMock(RawDoorRuleConfiguration::class);
        $configuration->expects(self::never())->method('all');
        $configuration->method('resolvedOptions')->willReturnCallback(static function () use (&$options): \Qualimetrix\Analysis\Finding\Contract\ResolvedRuleOptions {
            return new \Qualimetrix\Analysis\Finding\Contract\ResolvedRuleOptions(
                ['duplication.clone' => $options],
                ['duplication.clone' => new \Qualimetrix\Analysis\Finding\Contract\RuleSuppression()],
            );
        });
        $this->resultProvider = new DuplicationResultProvider();
        $detector = new DuplicationDetector($configuration, $this->resultProvider);
        $files = [$this->createFile('first.php', '<?php echo 1;'), $this->createFile('second.php', '<?php echo 2;')];
        self::assertSame([], $this->inspect($detector, $files));

        $options = new \Qualimetrix\Analysis\Evidence\Duplication\CodeDuplicationOptions(min_tokens: 1, min_lines: 0);
        self::assertNotEmpty($this->inspect($detector, $files));
        $detector->resetForRun();
        self::assertSame([], $this->resultProvider->all());
        $options = new \Qualimetrix\Analysis\Evidence\Duplication\CodeDuplicationOptions(min_tokens: 1000, min_lines: 0);
        self::assertSame([], $this->inspect($detector, $files));
    }

    private function createDetector(int $minTokens = 70, int $minLines = 5): DuplicationDetector
    {
        $ruleConfiguration = new RuleOptionsRegistry();
        $metadata = [new \Qualimetrix\Analysis\Finding\Contract\RuleMetadata('duplication.clone', \Qualimetrix\Analysis\Evidence\Duplication\CodeDuplicationOptions::class, '', [], false)];
        $configuration = ResolvedOptionsFixture::authoredConfiguration(['rules' => [
            'duplication.clone' => ['min_tokens' => $minTokens, 'min_lines' => $minLines],
        ]], $metadata);
        $ruleConfiguration->replace(ResolvedOptionsFixture::ready($configuration, $metadata));

        $this->resultProvider = new DuplicationResultProvider();

        return new DuplicationDetector($ruleConfiguration, $this->resultProvider);
    }

    /**
     * @param list<SplFileInfo> $files
     *
     * @return list<DuplicateBlock>
     *
     * @phpstan-impure
     */
    private function inspect(DuplicationDetector $detector, array $files): array
    {
        $detector->inspect($files, AbsolutePath::fromString($this->tmpDir));

        return $this->resultProvider->all();
    }

    /**
     * Builds a source file whose body is a `const array` with three rows
     * sharing one shape but different literal content — mirrors the real
     * bug: HealthMetricCatalog's METRICS/RANGES tables have many rows of
     * this shape, and pairs of rows normalize to identical token sequences
     * (string/number literals become placeholders), so they used to match
     * each other as duplicates.
     *
     * Deliberately single-file/multi-row rather than one row duplicated
     * across two files: a matched window here starts and ends between two
     * rows of the *same* array, so both its neighbors are also
     * const-declaration tokens. A cross-file, single-row variant risks the
     * window's start or end landing on file/class boilerplate (e.g. the
     * class's own opening `{`) that trivially agrees between any two
     * fixtures regardless of their data content — a fixture-construction
     * pitfall, not a property of the suppression logic itself.
     */
    private function constArrayFixture(string $className, string $constName): string
    {
        return <<<PHP
<?php

final class {$className}
{
    private const array {$constName} = [
        'complexity.ccn' => [
            'label' => 'Cyclomatic',
            'direction' => 'lower',
            'goodValue' => 'below four',
        ],
        'complexity.wmc' => [
            'label' => 'Weighted',
            'direction' => 'lower',
            'goodValue' => 'below ten',
        ],
        'cohesion.lcom' => [
            'label' => 'Cohesion',
            'direction' => 'higher',
            'goodValue' => 'above one',
        ],
    ];
}
PHP;
    }

    /**
     * Same shape as {@see constArrayFixture()}, but as a static property's
     * array-literal initializer instead of a class constant.
     */
    private function propertyArrayFixture(string $className): string
    {
        return <<<PHP
<?php

final class {$className}
{
    private static array \$defaults = [
        'complexity.ccn' => [
            'label' => 'Cyclomatic',
            'direction' => 'lower',
            'goodValue' => 'below four',
        ],
        'complexity.wmc' => [
            'label' => 'Weighted',
            'direction' => 'lower',
            'goodValue' => 'below ten',
        ],
        'cohesion.lcom' => [
            'label' => 'Cohesion',
            'direction' => 'higher',
            'goodValue' => 'above one',
        ],
    ];
}
PHP;
    }

    private function shortLocation(DuplicateLocation $location): string
    {
        return \sprintf('%s:%d-%d', basename($location->pathString()), $location->startLine, $location->endLine);
    }

    private function repeatedClassFixture(string $className): string
    {
        return <<<PHP
<?php

final class {$className}
{
    public function run(array \$rows, int \$limit): array
    {
        \$out = [];
        foreach (\$rows as \$key => \$row) {
            if (\$row['score'] > \$limit) {
                \$out[\$key] = strtoupper(\$row['name']);
            }
        }
        ksort(\$out);
        return \$out;
    }
}

PHP;
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

interface RawDoorRuleConfiguration extends \Qualimetrix\Analysis\Finding\Contract\RuleConfigurationInterface
{
    /** @return array<string, mixed> */
    public function all(): array;
}
