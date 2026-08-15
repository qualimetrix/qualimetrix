<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Duplication\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Duplication\DuplicateBlock;
use Qualimetrix\Analysis\Evidence\Duplication\DuplicateLocation;
use Qualimetrix\Analysis\Evidence\Duplication\DuplicationDetector;
use Qualimetrix\Analysis\Evidence\Duplication\DuplicationResultProvider;
use Qualimetrix\Analysis\Evidence\Duplication\NormalizedToken;
use Qualimetrix\Analysis\Evidence\Duplication\TokenNormalizer;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use SplFileInfo;

#[CoversClass(DuplicationDetector::class)]
#[CoversClass(TokenNormalizer::class)]
#[CoversClass(NormalizedToken::class)]
#[CoversClass(DuplicateBlock::class)]
#[CoversClass(DuplicateLocation::class)]
final class DuplicationDetectorTest extends TestCase
{
    private string $tmpDir;
    private DuplicationResultProvider $resultProvider;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/qmx_dup_test_' . uniqid();
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
        self::assertGreaterThanOrEqual(3, $block->lines);
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
        self::assertSame($blocks[0]->lines, $blocks[1]->lines);
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
            'ccn' => [
                'label' => '{$label}',
                'direction' => '{$direction}',
                'goodValue' => '{$goodValue}',
            ],
            'wmc' => [
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
        'ccn' => [
            'label' => 'Cyclomatic',
            'direction' => 'lower',
            'goodValue' => 'below four',
        ],
        'wmc' => [
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
    public function itHandlesEmptyFileList(): void
    {
        $detector = $this->createDetector();
        $blocks = $this->inspect($detector, []);

        self::assertSame([], $blocks);
    }

    #[Test]
    public function itExercisesDuplicateBlockVoMethods(): void
    {
        $block = new DuplicateBlock(
            locations: [
                new DuplicateLocation(RelativePath::fromString('a.php'), 10, 20),
                new DuplicateLocation(RelativePath::fromString('b.php'), 30, 40),
                new DuplicateLocation(RelativePath::fromString('c.php'), 50, 60),
            ],
            lines: 11,
            tokens: 50,
            contentHash: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        );

        self::assertSame(3, $block->occurrences());
        self::assertSame('a.php', $block->primaryLocation()->file->value());
        self::assertCount(2, $block->relatedLocations());
        self::assertSame('b.php', $block->relatedLocations()[0]->file->value());
        self::assertSame('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $block->contentHash);
    }

    #[Test]
    public function itExercisesDuplicateLocationVo(): void
    {
        $loc = new DuplicateLocation(RelativePath::fromString('src/Foo.php'), 10, 25);

        self::assertSame(16, $loc->lineCount());
        self::assertSame('src/Foo.php:10-25', $loc->toString());
    }

    private function createDetector(int $minTokens = 70, int $minLines = 5): DuplicationDetector
    {
        $ruleConfiguration = new RuleOptionsRegistry();
        $ruleConfiguration->setConfigFileOptions([
            'duplication.code-duplication' => [
                'min_tokens' => $minTokens,
                'min_lines' => $minLines,
            ],
        ]);

        $this->resultProvider = new DuplicationResultProvider();

        return new DuplicationDetector($ruleConfiguration, $this->resultProvider);
    }

    /**
     * @param list<SplFileInfo> $files
     *
     * @return list<DuplicateBlock>
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
        'ccn' => [
            'label' => 'Cyclomatic',
            'direction' => 'lower',
            'goodValue' => 'below four',
        ],
        'wmc' => [
            'label' => 'Weighted',
            'direction' => 'lower',
            'goodValue' => 'below ten',
        ],
        'lcom' => [
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
        'ccn' => [
            'label' => 'Cyclomatic',
            'direction' => 'lower',
            'goodValue' => 'below four',
        ],
        'wmc' => [
            'label' => 'Weighted',
            'direction' => 'lower',
            'goodValue' => 'below ten',
        ],
        'lcom' => [
            'label' => 'Cohesion',
            'direction' => 'higher',
            'goodValue' => 'above one',
        ],
    ];
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
