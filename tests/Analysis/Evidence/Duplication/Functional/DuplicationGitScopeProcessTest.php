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
use RuntimeException;
use SplFileInfo;

require_once \dirname(__DIR__, 5) . '/scripts/subprocess/ChildProcess.php';

/**
 * A git scope keeps a finding by where it is located, so a copy is visible to
 * `--report=git:*` only when a finding is located on that copy.
 */
#[CoversClass(CodeDuplicationRule::class)]
final class DuplicationGitScopeProcessTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/qmx-duplication-git-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir . '/src', 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    #[Test]
    public function itReportsANewCopyStagedAloneOnTheCopyItself(): void
    {
        file_put_contents($this->tmpDir . '/src/Alpha.php', self::copiedClass('Alpha'));
        file_put_contents($this->tmpDir . '/src/Beta.php', self::copiedClass('Beta'));
        file_put_contents(
            $this->tmpDir . '/qmx.yaml',
            "onlyRules: ['duplication.clone']\nfailOn: none\n",
        );
        $this->git(['init', '-q', '-b', 'main']);
        $this->git(['config', 'user.email', 'test@example.com']);
        $this->git(['config', 'user.name', 'Test']);
        $this->git(['add', '-A']);
        $this->git(['commit', '-qm', 'initial']);

        file_put_contents($this->tmpDir . '/src/Gamma.php', self::copiedClass('Gamma'));
        $this->git(['add', 'src/Gamma.php']);

        $result = ChildProcess::run([
            \PHP_BINARY,
            '-d',
            'xdebug.mode=off',
            $this->projectRoot() . '/bin/qmx',
            'check',
            'src',
            '--config=qmx.yaml',
            '--format=json',
            '--no-progress',
            '--no-cache',
            '--workers=0',
            '--report=git:staged',
        ], $this->tmpDir);

        self::assertSame(0, $result['exitCode'], $result['stderr'] . "\n" . $result['stdout']);

        /** @var array{violations?: list<array{rule?: string, file?: string, message?: string}>} $report */
        $report = json_decode($result['stdout'], true, flags: \JSON_THROW_ON_ERROR);
        $violations = $report['violations'] ?? [];

        self::assertCount(1, $violations, $result['stdout']);
        self::assertSame('duplication.clone', $violations[0]['rule'] ?? null);
        self::assertSame('src/Gamma.php', $violations[0]['file'] ?? null);
        self::assertStringContainsString('3 occurrences', $violations[0]['message'] ?? '');
    }

    /**
     * A copy written on fewer lines than the copies already there — the same
     * tokens without their blank lines — is still a copy of the block, and
     * the only place a change that adds it can see it is on that copy.
     */
    #[Test]
    public function itReportsADenselyWrittenNewCopyStagedAloneOnTheCopyItself(): void
    {
        file_put_contents($this->tmpDir . '/src/Alpha.php', self::spaciousFunction('alpha'));
        file_put_contents($this->tmpDir . '/qmx.yaml', "onlyRules: ['duplication.clone']\n");
        $this->git(['init', '-q', '-b', 'main']);
        $this->git(['config', 'user.email', 'test@example.com']);
        $this->git(['config', 'user.name', 'Test']);
        $this->git(['add', '-A']);
        $this->git(['commit', '-qm', 'initial']);

        file_put_contents($this->tmpDir . '/src/Beta.php', self::denseFunction('beta'));
        $this->git(['add', 'src/Beta.php']);

        $result = ChildProcess::run([
            \PHP_BINARY,
            '-d',
            'xdebug.mode=off',
            $this->projectRoot() . '/bin/qmx',
            'check',
            'src',
            '--config=qmx.yaml',
            '--format=json',
            '--no-progress',
            '--no-cache',
            '--workers=0',
            '--fail-on=warning',
            '--report=git:staged',
        ], $this->tmpDir);

        self::assertSame(1, $result['exitCode'], $result['stderr'] . "\n" . $result['stdout']);

        /** @var array{violations: list<array{file: string, severity: string, metricValue: int|float|null}>} $report */
        $report = json_decode($result['stdout'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(
            [['src/Beta.php', 3, 'warning']],
            array_map(static fn(array $violation): array => [$violation['file'], $violation['metricValue'], $violation['severity']], $report['violations']),
            $result['stdout'],
        );
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

    /** @param list<string> $arguments */
    private function git(array $arguments): void
    {
        $result = ChildProcess::run(['git', ...$arguments], $this->tmpDir);

        if ($result['exitCode'] !== 0) {
            throw new RuntimeException('git ' . implode(' ', $arguments) . ' failed: ' . $result['stderr']);
        }
    }

    private function projectRoot(): string
    {
        return \dirname(__DIR__, 5);
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
