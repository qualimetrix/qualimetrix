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
 * GitLab Code Quality shows findings sharing a fingerprint as one entry, and
 * SARIF consumers match alerts across runs by the partial fingerprint: each
 * copy of a block has to carry its own, or the copies collapse into one and
 * a new copy is not new to them.
 */
#[CoversClass(CodeDuplicationRule::class)]
final class DuplicationCopyFingerprintProcessTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/qmx-duplication-fingerprint-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir . '/src', 0o755, true);
        file_put_contents($this->tmpDir . '/src/Alpha.php', self::copiedClass('Alpha'));
        file_put_contents($this->tmpDir . '/src/Beta.php', self::copiedClass('Beta'));
        file_put_contents($this->tmpDir . '/qmx.yaml', "onlyRules: ['duplication.clone']\nfailOn: none\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    #[Test]
    public function itGivesEachCopyItsOwnGitLabFingerprint(): void
    {
        /** @var list<array{fingerprint?: string, location?: array{path?: string}}> $issues */
        $issues = json_decode($this->report('gitlab'), true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(['src/Alpha.php', 'src/Beta.php'], array_map(static fn(array $issue): ?string => $issue['location']['path'] ?? null, $issues));
        self::assertCount(2, array_unique(array_column($issues, 'fingerprint')));
    }

    #[Test]
    public function itGivesEachCopyItsOwnSarifPartialFingerprint(): void
    {
        /** @var array{runs: list<array{results: list<array{partialFingerprints?: array<string, string>}>}>} $sarif */
        $sarif = json_decode($this->report('sarif'), true, flags: \JSON_THROW_ON_ERROR);
        $results = $sarif['runs'][0]['results'];

        self::assertCount(2, $results);
        self::assertNotSame($results[0]['partialFingerprints'] ?? null, $results[1]['partialFingerprints'] ?? null);
    }

    private function report(string $format): string
    {
        $result = ChildProcess::run([
            \PHP_BINARY,
            '-d',
            'xdebug.mode=off',
            \dirname(__DIR__, 5) . '/bin/qmx',
            'check',
            'src',
            '--config=qmx.yaml',
            '--format=' . $format,
            '--no-progress',
            '--no-cache',
            '--workers=0',
        ], $this->tmpDir);

        self::assertSame(0, $result['exitCode'], $result['stderr'] . "\n" . $result['stdout']);

        return $result['stdout'];
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
