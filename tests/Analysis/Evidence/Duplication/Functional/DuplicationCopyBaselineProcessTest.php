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
        self::assertSame(19, $newCopy['metricValue'], 'the comment leaves the new copy with the same covered code lines');
        self::assertNull($newCopy['acceptedLevel']);
    }

    /**
     * A comment widens the physical span without adding covered code lines.
     * Neither short copy reaches min_lines, so adding the comment after
     * baseline generation must not admit the block.
     */
    #[Test]
    public function itDoesNotAdmitCopiesWhenACommentOnlyWidensTheirPhysicalSpan(): void
    {
        file_put_contents($this->tmpDir . '/src/Alpha.php', self::shortFunction('alpha', ''));
        file_put_contents($this->tmpDir . '/src/Beta.php', self::shortFunction('beta', ''));

        $generated = $this->qmx('baseline:generate', 'baseline.json', 'src', '--config=qmx.yaml', '--no-progress');
        self::assertSame(0, $generated['exitCode'], $generated['stderr'] . "\n" . $generated['stdout']);

        file_put_contents($this->tmpDir . '/src/Alpha.php', self::shortFunction('alpha', '    // explain y'));

        $checked = $this->qmx('check', 'src', '--config=qmx.yaml', '--baseline=baseline.json', '--fail-on=warning', '--format=json', '--no-progress', '--no-cache', '--workers=0');
        self::assertSame(0, $checked['exitCode'], $checked['stderr'] . "\n" . $checked['stdout']);
        self::assertSame([], self::violations($checked['stdout']));
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

    /** Each file explains only its own accepted or newly reported copy. */
    #[Test]
    public function itTellsTheExplainedCopiesApartByOccurrenceAndFile(): void
    {
        $generated = $this->qmx('baseline:generate', 'baseline.json', 'src', '--config=qmx.yaml', '--no-progress');
        self::assertSame(0, $generated['exitCode'], $generated['stderr'] . "\n" . $generated['stdout']);
        file_put_contents($this->tmpDir . '/src/Gamma.php', self::copiedClass('Gamma'));

        $baselineByFile = [];
        $occurrences = [];
        foreach (['Alpha', 'Beta', 'Gamma'] as $class) {
            $explained = $this->qmx('baseline:explain', 'file:src/' . $class . '.php', 'src', '--config=qmx.yaml', '--baseline=baseline.json', '--no-progress');
            self::assertSame(0, $explained['exitCode'], $explained['stderr'] . "\n" . $explained['stdout']);

            preg_match_all('/Occurrence: ([0-9a-f]{16})\n    Reported at: (\S+)\n    baseline: +(.+)\n    now: +(.+)\n/', $explained['stdout'], $sections, \PREG_SET_ORDER);
            self::assertCount(1, $sections, $explained['stdout']);
            foreach ($sections as [, $occurrence, $at, $baseline, $now]) {
                $baselineByFile[$at] = ['baseline' => $baseline, 'now' => $now];
                $occurrences[] = $occurrence;
            }
        }
        ksort($baselineByFile);

        self::assertSame(
            [
                'src/Alpha.php:4' => ['baseline' => 'accepted 19', 'now' => '19'],
                'src/Beta.php:4' => ['baseline' => 'accepted 19', 'now' => '19'],
                'src/Gamma.php:4' => ['baseline' => '(none)', 'now' => '19'],
            ],
            $baselineByFile,
        );
        self::assertCount(1, array_unique($occurrences), 'file subject distinguishes copies with the same occurrence');
    }

    #[Test]
    public function itSuppressesOnlyTheDuplicateCopyInTheExcludedPath(): void
    {
        file_put_contents(
            $this->tmpDir . '/qmx.yaml',
            "onlyRules: ['duplication.clone']\nsuppress_paths: [{exact: 'src/Alpha.php'}]\n",
        );

        $checked = $this->qmx('check', 'src', '--config=qmx.yaml', '--format=json', '--no-progress', '--no-cache', '--workers=0');
        self::assertSame(0, $checked['exitCode'], $checked['stderr'] . "\n" . $checked['stdout']);

        /** @var array{violations: list<array{file: string, subject: string, channel: string}>} $report */
        $report = json_decode($checked['stdout'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(
            [['src/Beta.php', 'file:src/Beta.php', 'duplication.clone']],
            array_map(static fn(array $v): array => [$v['file'], $v['subject'], $v['channel']], $report['violations']),
        );
    }

    #[Test]
    public function itKeepsBothFileCopiesUnderBroadNamespaceRegexes(): void
    {
        foreach (['.*', '^$', '^(?!App).*'] as $expression) {
            file_put_contents(
                $this->tmpDir . '/qmx.yaml',
                "onlyRules: ['duplication.clone']\nsuppress_namespaces: [{regex: '" . $expression . "'}]\n",
            );

            $checked = $this->qmx('check', 'src', '--config=qmx.yaml', '--format=json', '--no-progress', '--no-cache', '--workers=0');
            self::assertSame(0, $checked['exitCode'], $checked['stderr'] . "\n" . $checked['stdout']);
            /** @var array{violations: list<array{file: string, subject: string}>} $report */
            $report = json_decode($checked['stdout'], true, flags: \JSON_THROW_ON_ERROR);
            $copies = array_map(static fn(array $v): array => [$v['file'], $v['subject']], $report['violations']);
            sort($copies);
            self::assertSame([
                ['src/Alpha.php', 'file:src/Alpha.php'],
                ['src/Beta.php', 'file:src/Beta.php'],
            ], $copies, $checked['stdout']);
        }
    }

    #[Test]
    public function itRefusesTheRetiredProjectSelectorAndDisablesTheFileProducer(): void
    {
        file_put_contents($this->tmpDir . '/qmx.yaml', "failOn: none\n");

        $active = $this->qmx('check', 'src', '--config=qmx.yaml', '--format=json', '--no-progress', '--no-cache', '--workers=0');
        self::assertSame(0, $active['exitCode'], $active['stderr'] . "\n" . $active['stdout']);
        /** @var array{violations: list<array{channel: string}>} $activeReport */
        $activeReport = json_decode($active['stdout'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertCount(2, array_filter($activeReport['violations'], static fn(array $v): bool => $v['channel'] === 'duplication.clone'));

        $retired = $this->qmx('check', 'src', '--config=qmx.yaml', '--disable-rule=duplication.clone:project', '--format=json', '--no-progress', '--no-cache', '--workers=0');
        self::assertSame(3, $retired['exitCode'], $retired['stderr'] . "\n" . $retired['stdout']);
        /** @var array{error: string} $refusal */
        $refusal = json_decode($retired['stdout'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertStringContainsString('levels available are "file"', $refusal['error']);

        $disabled = $this->qmx('check', 'src', '--config=qmx.yaml', '--disable-rule=duplication.clone:file', '--format=json', '--no-progress', '--no-cache', '--workers=0');
        self::assertSame(0, $disabled['exitCode'], $disabled['stderr'] . "\n" . $disabled['stdout']);
        /** @var array{violations: list<array{channel: string}>} $report */
        $report = json_decode($disabled['stdout'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame([], array_values(array_filter($report['violations'], static fn(array $v): bool => $v['channel'] === 'duplication.clone')));
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
     * Four code lines and over 70 tokens; an inserted comment changes only
     * the physical span.
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
