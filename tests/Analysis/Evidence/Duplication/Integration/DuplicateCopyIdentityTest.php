<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Duplication\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Duplication\CodeDuplicationOptions;
use Qualimetrix\Analysis\Evidence\Duplication\CodeDuplicationRule;
use Qualimetrix\Analysis\Evidence\Duplication\DuplicateBlock;
use Qualimetrix\Analysis\Evidence\Duplication\DuplicationDetector;
use Qualimetrix\Analysis\Evidence\Duplication\DuplicationResultProvider;
use Qualimetrix\Analysis\Evidence\Measurement\Contract\MetricRepositoryInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\AnalysisContext;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Core\Path\AbsolutePath;
use SplFileInfo;

/**
 * What a copy's identity and value survive, read off the real detector: a
 * witness that builds the block by hand already assumes the detector returns
 * the same block after the edit, which is the very thing in question.
 */
#[CoversClass(CodeDuplicationRule::class)]
#[CoversClass(DuplicationDetector::class)]
final class DuplicateCopyIdentityTest extends TestCase
{
    private const array BODY = [
        '        $total = 0;',
        '        foreach ($items as $key => $item) {',
        "            if (\$item['active'] && \$item['price'] > 10) {",
        "                \$total += \$item['price'] * \$item['qty'];",
        "            } elseif (\$item['discount'] !== null) {",
        "                \$total -= \$item['discount'] + \$key;",
        '            }',
        "            \$this->log[] = sprintf('%s:%d', \$item['name'], \$total);",
        '        }',
        "        \$result = ['total' => \$total, 'count' => count(\$items)];",
        '        return $this->finish($result, $total, $items);',
    ];

    private string $tmpDir;

    protected function setUp(): void
    {
        $tmpDir = sys_get_temp_dir() . '/qmx_dup_identity_' . bin2hex(random_bytes(6));
        mkdir($tmpDir . '/src', 0o777, true);
        // The system temporary directory may be reached through a symlink,
        // and a copy's file is keyed relative to the resolved root
        $this->tmpDir = (string) realpath($tmpDir);
    }

    protected function tearDown(): void
    {
        foreach ($this->sourceFiles() as $file) {
            unlink($file);
        }
        rmdir($this->tmpDir . '/src');
        rmdir($this->tmpDir);
    }

    /**
     * A comment or a blank line inside one copy widens that copy alone; the
     * other copies still span what they spanned, and their value — the one a
     * baseline compares — must not move with a file nobody touched.
     */
    #[Test]
    public function itValuesEachCopyByTheLinesThatCopySpans(): void
    {
        $this->write('A', self::classWith('A', self::withComment(self::BODY)));
        $this->write('B', self::classWith('B', self::BODY));

        $analysis = $this->analyze();
        $onA = self::onlyCopyIn($analysis, 'src/A.php');
        $onB = self::onlyCopyIn($analysis, 'src/B.php');

        self::assertSame(18, $onA['value']);
        self::assertSame(17, $onB['value']);
        self::assertStringContainsString('(18 lines, 2 occurrences)', $onA['message']);
        self::assertStringContainsString('(17 lines, 2 occurrences)', $onB['message']);
    }

    /**
     * `min_lines` admits a block by its longest copy. A comment lifting one
     * copy past it admits the block, and the copy in the file nobody touched
     * is reported too, at the lines it spans itself.
     */
    #[Test]
    public function itReportsEveryCopyOnceTheLongestReachesMinLines(): void
    {
        $this->write('A', self::shortFunction('runA', '    // a note'));
        $this->write('B', self::shortFunction('runB', ''));

        $analysis = $this->analyze();

        $onA = self::onlyCopyIn($analysis, 'src/A.php');
        $onB = self::onlyCopyIn($analysis, 'src/B.php');
        self::assertSame(5, $onA['value']);
        self::assertSame(4, $onB['value']);
        self::assertStringContainsString('(4 lines, 2 occurrences)', $onB['message']);
        self::assertStringEndsWith('also at src/A.php:2-6', $onB['message']);
    }

    /**
     * Without the comment neither copy reaches `min_lines`: there is no block.
     */
    #[Test]
    public function itReportsNoCopyWhileNoCopyReachesMinLines(): void
    {
        $this->write('A', self::shortFunction('runA', ''));
        $this->write('B', self::shortFunction('runB', ''));

        self::assertSame([], $this->analyze()['copies']);
    }

    #[Test]
    public function itKeepsTheBlockAndEveryCopysIdentityWhenAnExactCopyIsAdded(): void
    {
        $this->write('A', self::classWith('A', self::BODY));
        $this->write('B', self::classWith('B', self::BODY));
        $before = $this->analyze();

        $this->write('C', self::classWith('C', self::BODY));
        $after = $this->analyze();

        self::assertSame($before['hashes'], $after['hashes'], 'the detector must return the same block');
        self::assertSame(self::onlyCopyIn($before, 'src/A.php')['key'], self::onlyCopyIn($after, 'src/A.php')['key']);
        self::assertSame(self::onlyCopyIn($before, 'src/B.php')['key'], self::onlyCopyIn($after, 'src/B.php')['key']);
        self::assertSame(['src/C.php'], self::filesWithNewKeys($before, $after));
    }

    #[Test]
    public function itKeepsTheBlockAndEveryCopysIdentityWhenCodeOutsideTheMatchIsAdded(): void
    {
        $this->write('A', self::classWith('A', self::BODY));
        $this->write('B', self::classWith('B', self::BODY));
        $before = $this->analyze();

        $this->write('B', str_replace(
            "namespace App;\n",
            "namespace App;\n\nuse Foo\\Bar;\nuse Foo\\Baz;\n// a comment\n\n",
            self::classWith('B', self::BODY),
        ));
        $after = $this->analyze();

        self::assertSame($before['hashes'], $after['hashes'], 'the detector must return the same block');
        self::assertSame([], self::filesWithNewKeys($before, $after));
    }

    /**
     * The block is the longest run all its copies agree on, so a copy that
     * agrees with only part of it adds a block over every copy: the
     * untouched copies keep their accepted identities and gain new ones, and
     * none goes stale.
     */
    #[Test]
    public function itGivesTheUntouchedCopiesNewFindingsWhenAPartialCopyAddsABlock(): void
    {
        $this->write('A', self::classWith('A', self::BODY));
        $this->write('B', self::classWith('B', self::BODY));
        $before = $this->analyze();

        $this->write('C', self::classWith('C', [...\array_slice(self::BODY, 0, 9), '        return 1;']));
        $after = $this->analyze();

        self::assertNotSame($before['hashes'], $after['hashes']);
        self::assertSame([], array_diff(array_column($before['copies'], 'key'), array_column($after['copies'], 'key')));
        self::assertSame(['src/A.php', 'src/B.php', 'src/C.php'], self::filesWithNewKeys($before, $after));
    }

    /**
     * An edit inside one of three copies takes that copy out of the block;
     * the two untouched copies still agree on all of it and keep their
     * identities, while what all three still agree on is a new block over
     * every copy.
     */
    #[Test]
    public function itStalesOnlyTheEditedCopyWhenTheOthersStillAgreeOnTheBlock(): void
    {
        foreach (['A', 'B', 'C'] as $class) {
            $this->write($class, self::classWith($class, self::BODY));
        }
        $before = $this->analyze();

        $this->write('C', str_replace(
            "\$total -= \$item['discount'] + \$key;",
            "\$total -= \$item['discount'] * 2;",
            self::classWith('C', self::BODY),
        ));
        $after = $this->analyze();

        $afterKeys = array_column($after['copies'], 'key');
        $stale = array_values(array_filter(
            $before['copies'],
            static fn(array $copy): bool => !\in_array($copy['key'], $afterKeys, true),
        ));
        self::assertSame(['src/C.php'], array_column($stale, 'file'));
        self::assertSame(['src/A.php', 'src/B.php', 'src/C.php'], self::filesWithNewKeys($before, $after));
    }

    /**
     * The match reaches past the copied method into the context all copies
     * share; code inserted between that context and one copy narrows the
     * match in every copy, and re-keys the copy in the untouched file too.
     */
    #[Test]
    public function itRekeysTheUntouchedCopyWhenCodeIsInsertedNextToAnotherCopy(): void
    {
        $this->write('A', self::classWith('A', self::BODY));
        $this->write('B', self::classWith('B', self::BODY));
        $before = $this->analyze();

        $this->write('B', str_replace(
            "    private array \$log = [];\n",
            "    private array \$log = [];\n    public function other(): int\n    {\n        return 42;\n    }\n",
            self::classWith('B', self::BODY),
        ));
        $after = $this->analyze();

        self::assertNotSame($before['hashes'], $after['hashes']);
        self::assertSame(['src/A.php', 'src/B.php'], self::filesWithNewKeys($before, $after));
    }

    /**
     * @param array{copies: list<array{file: string, key: string}>} $before
     * @param array{copies: list<array{file: string, key: string}>} $after
     *
     * @return list<string>
     */
    private static function filesWithNewKeys(array $before, array $after): array
    {
        $known = array_column($before['copies'], 'key');
        $files = [];

        foreach ($after['copies'] as $copy) {
            if (!\in_array($copy['key'], $known, true)) {
                $files[$copy['file']] = true;
            }
        }

        $files = array_keys($files);
        sort($files);

        return $files;
    }

    /**
     * @param array{copies: list<array{file: string, key: string, value: int|float|null, message: string}>} $analysis
     *
     * @return array{file: string, key: string, value: int|float|null, message: string}
     */
    private static function onlyCopyIn(array $analysis, string $file): array
    {
        $copies = array_values(array_filter($analysis['copies'], static fn(array $copy): bool => $copy['file'] === $file));
        self::assertCount(1, $copies, "one copy in {$file}");

        return $copies[0];
    }

    /**
     * @return array{hashes: list<string>, copies: list<array{file: string, key: string, value: int|float|null, message: string}>}
     */
    private function analyze(): array
    {
        $files = array_map(static fn(string $path): SplFileInfo => new SplFileInfo($path), $this->sourceFiles());

        $configuration = new RuleOptionsRegistry();
        $configuration->setConfigFileOptions(['duplication.clone' => []]);
        $provider = new DuplicationResultProvider();
        (new DuplicationDetector($configuration, $provider))->inspect($files, AbsolutePath::fromString($this->tmpDir));

        $findings = (new CodeDuplicationRule(new CodeDuplicationOptions(), $provider))
            ->analyze(new AnalysisContext(self::createStub(MetricRepositoryInterface::class)));

        $copies = [];
        foreach ($findings as $finding) {
            $copies[] = [
                'file' => $finding->location->pathString(),
                'key' => (string) $finding->occurrenceKey?->value,
                'value' => $finding->metricValue,
                'message' => $finding->message,
            ];
        }

        $hashes = array_map(static fn(DuplicateBlock $block): string => $block->contentHash, $provider->all());
        sort($hashes);

        return ['hashes' => $hashes, 'copies' => $copies];
    }

    /**
     * @return list<string>
     */
    private function sourceFiles(): array
    {
        $files = glob($this->tmpDir . '/src/*.php');

        return $files === false ? [] : $files;
    }

    private function write(string $class, string $source): void
    {
        file_put_contents($this->tmpDir . "/src/{$class}.php", $source);
    }

    /**
     * @param list<string> $body
     *
     * @return list<string>
     */
    private static function withComment(array $body): array
    {
        array_splice($body, 4, 0, ['        // a note']);

        return $body;
    }

    /**
     * Four lines and over 70 tokens: one line short of the default
     * `min_lines`, until `$extra` lands inside it.
     */
    private static function shortFunction(string $name, string $extra): string
    {
        return implode("\n", [
            '<?php',
            "function {$name}(\$a, \$b, \$c) { " . '$x = $a + $b * 2 - $c / 3 + $a * $b - $c + $a % 7 + $b % 5 - $c * $a + $b / 2 - $c + 11 * $a;',
            ...($extra === '' ? [] : [$extra]),
            '    $y = $x - $a / 3 + $b * $c - $x % 4 + $a * $a - $b * $b + $c * $c - $x / 9 + $a - $b + $c;',
            '    $z = $x * $y - $a;',
            '    return $x * $y + $a - $b + $c * $x - $y / 2 + $a * $b * $c - $x + $y + 42 + $a + $b + $z; }',
            '',
        ]);
    }

    /**
     * @param list<string> $body
     */
    private static function classWith(string $class, array $body): string
    {
        return implode("\n", [
            '<?php',
            '',
            'namespace App;',
            '',
            "final class {$class}",
            '{',
            '    private array $log = [];',
            '    public function run(array $items): mixed',
            '    {',
            ...$body,
            '    }',
            '}',
            '',
        ]);
    }
}
