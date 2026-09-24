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
