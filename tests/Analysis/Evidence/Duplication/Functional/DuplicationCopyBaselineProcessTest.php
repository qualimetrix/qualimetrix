<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Duplication\Functional;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Duplication\CodeDuplicationRule;
use Qualimetrix\Subprocess\ChildProcess;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

require_once \dirname(__DIR__, 5) . '/scripts/subprocess/ChildProcess.php';

/**
 * A baseline compares each accepted copy with the value that copy reports
 * now. Comments and blank lines are no tokens, so a copy spanning more lines
 * is still the same block — and it must not raise the value, and with it the
 * severity, of copies in files nobody touched.
 */
#[CoversClass(CodeDuplicationRule::class)]
final class DuplicationCopyBaselineProcessTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/qmx-duplication-copy-baseline-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir . '/src', 0o755, true);
        file_put_contents($this->tmpDir . '/src/Alpha.php', self::copiedClass('Alpha'));
        file_put_contents($this->tmpDir . '/src/Beta.php', self::copiedClass('Beta'));
        file_put_contents($this->tmpDir . '/qmx.yaml', "onlyRules: ['duplication.clone']\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    #[Test]
    public function itKeepsTheAcceptedCopiesAcceptedWhenANewCopySpansMoreLines(): void
    {
        $generated = $this->qmx('baseline:generate', 'baseline.json', 'src', '--config=qmx.yaml', '--no-progress');
        self::assertSame(0, $generated['exitCode'], $generated['stderr'] . "\n" . $generated['stdout']);

        file_put_contents(
            $this->tmpDir . '/src/Gamma.php',
            str_replace("        \$out = [];\n", "        \$out = [];\n        // one more line, no more tokens\n", self::copiedClass('Gamma')),
        );

        $checked = $this->qmx('check', 'src', '--config=qmx.yaml', '--baseline=baseline.json', '--format=json', '--no-progress', '--no-cache', '--workers=0');
        self::assertSame(0, $checked['exitCode'], $checked['stderr'] . "\n" . $checked['stdout']);

        /** @var array{violations: list<array{file: string, severity: string, metricValue: int|float|null, acceptedLevel: mixed}>} $report */
        $report = json_decode($checked['stdout'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertCount(1, $report['violations'], $checked['stdout']);
        [$newCopy] = $report['violations'];
        self::assertSame('src/Gamma.php', $newCopy['file']);
        self::assertSame('warning', $newCopy['severity']);
        self::assertSame(20, $newCopy['metricValue'], 'the new copy spans one line more than the accepted ones');
        self::assertNull($newCopy['acceptedLevel']);
    }

    /**
     * `min_lines` admits a block by its longest copy. A comment lifting the
     * longest copy past it adds the block, so every copy is a new finding —
     * the one in the file nobody touched too, at a value of its own below
     * `min_lines` and reported as a warning.
     */
    #[Test]
    public function itReportsEveryCopyWhenACommentLiftsTheLongestPastMinLines(): void
    {
        file_put_contents($this->tmpDir . '/src/Alpha.php', self::shortFunction('alpha', ''));
        file_put_contents($this->tmpDir . '/src/Beta.php', self::shortFunction('beta', ''));

        $generated = $this->qmx('baseline:generate', 'baseline.json', 'src', '--config=qmx.yaml', '--no-progress');
        self::assertSame(0, $generated['exitCode'], $generated['stderr'] . "\n" . $generated['stdout']);

        file_put_contents($this->tmpDir . '/src/Alpha.php', self::shortFunction('alpha', '    // explain y'));

        $checked = $this->qmx('check', 'src', '--config=qmx.yaml', '--baseline=baseline.json', '--fail-on=warning', '--format=json', '--no-progress', '--no-cache', '--workers=0');
        self::assertSame(1, $checked['exitCode'], $checked['stderr'] . "\n" . $checked['stdout']);
        self::assertSame([['src/Alpha.php', 5, 'warning'], ['src/Beta.php', 4, 'warning']], self::violations($checked['stdout']));
    }

    /**
     * A new copy written on fewer lines than the accepted ones — the same
     * tokens without their blank lines — is a copy of the accepted block, and
     * a new finding on the new copy alone, whatever its own line count.
     */
    #[Test]
    public function itReportsADenselyWrittenNewCopyOfAnAcceptedBlock(): void
    {
        file_put_contents($this->tmpDir . '/src/Alpha.php', self::spaciousFunction('alpha'));
        file_put_contents($this->tmpDir . '/src/Beta.php', self::spaciousFunction('beta'));

        $generated = $this->qmx('baseline:generate', 'baseline.json', 'src', '--config=qmx.yaml', '--no-progress');
        self::assertSame(0, $generated['exitCode'], $generated['stderr'] . "\n" . $generated['stdout']);

        file_put_contents($this->tmpDir . '/src/Gamma.php', self::denseFunction('gamma'));

        $checked = $this->qmx('check', 'src', '--config=qmx.yaml', '--baseline=baseline.json', '--fail-on=warning', '--format=json', '--no-progress', '--no-cache', '--workers=0');
        self::assertSame(1, $checked['exitCode'], $checked['stderr'] . "\n" . $checked['stdout']);
        self::assertSame([['src/Gamma.php', 3, 'warning']], self::violations($checked['stdout']));
    }

    /**
     * @return list<array{string, int|float|null, string}> file, value and severity of each reported finding
     */
    private static function violations(string $stdout): array
    {
        /** @var array{violations: list<array{file: string, severity: string, metricValue: int|float|null}>} $report */
        $report = json_decode($stdout, true, flags: \JSON_THROW_ON_ERROR);
        $violations = array_map(
            static fn(array $violation): array => [$violation['file'], $violation['metricValue'], $violation['severity']],
            $report['violations'],
        );
        sort($violations);

        return $violations;
    }

    /**
     * Every copy is a boundary of its own under the one project subject, so
     * `baseline:explain` prints a section per copy; without the copy's
     * occurrence and file the sections read the same.
     */
    #[Test]
    public function itTellsTheExplainedCopiesApartByOccurrenceAndFile(): void
    {
        $generated = $this->qmx('baseline:generate', 'baseline.json', 'src', '--config=qmx.yaml', '--no-progress');
        self::assertSame(0, $generated['exitCode'], $generated['stderr'] . "\n" . $generated['stdout']);
        file_put_contents($this->tmpDir . '/src/Gamma.php', self::copiedClass('Gamma'));

        $explained = $this->qmx('baseline:explain', 'project:', 'src', '--config=qmx.yaml', '--baseline=baseline.json', '--no-progress');
        self::assertSame(0, $explained['exitCode'], $explained['stderr'] . "\n" . $explained['stdout']);

        preg_match_all('/Occurrence: ([0-9a-f]{16})\n    Reported at: (\S+)\n    baseline: +(.+)\n/', $explained['stdout'], $sections, \PREG_SET_ORDER);
        $baselineByFile = [];
        foreach ($sections as [, , $at, $baseline]) {
            $baselineByFile[$at] = $baseline;
        }
        ksort($baselineByFile);

        self::assertSame(
            ['src/Alpha.php:4' => 'accepted 19; now 19', 'src/Beta.php:4' => 'accepted 19; now 19', 'src/Gamma.php:4' => '(none)'],
            $baselineByFile,
            $explained['stdout'],
        );
        self::assertCount(3, array_unique(array_column($sections, 1)));
    }

    /**
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private function qmx(string ...$arguments): array
    {
        return ChildProcess::run([
            \PHP_BINARY,
            '-d',
            'xdebug.mode=off',
            \dirname(__DIR__, 5) . '/bin/qmx',
            ...array_values($arguments),
        ], $this->tmpDir);
    }

    private static function copiedClass(string $className): string
    {
        return <<<PHP
            <?php

            final class {$className}
            {
                public function run(array \$rows, int \$limit): array
                {
                    \$out = [];
                    foreach (\$rows as \$key => \$row) {
                        if (\$row['status'] === 'active' && \$row['score'] > \$limit) {
                            \$out[\$key] = [
                                'name' => strtoupper(\$row['name']),
                                'score' => \$row['score'] * 2 + \$limit,
                                'tags' => array_values(array_filter(\$row['tags'])),
                            ];
                        } elseif (\$row['status'] === 'pending') {
                            \$out[\$key] = null;
                        }
                    }
                    ksort(\$out);
                    return array_filter(\$out, static fn (\$v) => \$v !== null);
                }
            }

            PHP;
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
     * Twelve lines: the statements of {@see denseFunction()} with blank lines between them.
     */
    private static function spaciousFunction(string $name): string
    {
        return implode("\n", [
            '<?php',
            "function {$name}(\$a, \$b, \$c)",
            '{',
            '    $x = $a + $b * 2 - $c / 3 + $a * $b - $c + $a % 7;',
            '',
            '    $x = $x + $b % 5 - $c * $a + $b / 2 - $c + 11 * $a;',
            '',
            '    $y = $x - $a / 3 + $b * $c - $x % 4 + $a * $a;',
            '',
            '    $y = $y - $b * $b + $c * $c - $x / 9 + $a - $b + $c;',
            '',
            '    return $x * $y + $a - $b + $c * $x - $y / 2 + $a * $b * $c;',
            '}',
            '',
        ]);
    }

    /**
     * The tokens of {@see spaciousFunction()} on three lines — fewer than `min_lines`.
     */
    private static function denseFunction(string $name): string
    {
        return implode("\n", [
            '<?php',
            "function {$name}(\$a, \$b, \$c) { " . '$x = $a + $b * 2 - $c / 3 + $a * $b - $c + $a % 7; $x = $x + $b % 5 - $c * $a + $b / 2 - $c + 11 * $a;',
            '    $y = $x - $a / 3 + $b * $c - $x % 4 + $a * $a; $y = $y - $b * $b + $c * $c - $x / 9 + $a - $b + $c;',
            '    return $x * $y + $a - $b + $c * $x - $y / 2 + $a * $b * $c; }',
            '',
        ]);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            if ($item->isDir() && !$item->isLink()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($directory);
    }
}
