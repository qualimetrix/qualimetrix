<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\Infrastructure\Git;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Core\Violation\Location;
use Qualimetrix\Core\Violation\Severity;
use Qualimetrix\Core\Violation\Violation;
use Qualimetrix\Infrastructure\Git\GitClient;
use Qualimetrix\Infrastructure\Git\GitScope;
use Qualimetrix\Infrastructure\Git\GitScopeFilter;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * T10 regression at the `extractNamespace()` site: when the project root sits in
 * a strict subdirectory of the git top-level, the file that backs namespace
 * extraction must be resolved against {@see GitScopeFilter::$projectRoot},
 * not against the git top-level.
 *
 * Companion to {@see GitSubdirScopeTest} (which pins T10 at the git-output
 * translation boundary, {@see GitClient::parseNameStatus()}).
 *
 * Differential setup: two distinct files share the same project-relative path,
 * one at `{gitToplevel}/src/Service.php` and one at
 * `{projectRoot}/src/Service.php`, each declaring a different namespace.
 * Swapping the absolute-path base inside `extractNamespace()` would silently
 * pick the wrong file — and therefore the wrong namespace — and the parent-
 * namespace violation assertion below would flip.
 */
#[CoversClass(GitScopeFilter::class)]
final class GitScopeFilterProjectSubdirTest extends TestCase
{
    private string $gitToplevel;

    private string $projectRoot;

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/qmx-git-scope-filter-subdir-' . uniqid();
        if (!mkdir($dir, 0777, true)) {
            throw new RuntimeException('Failed to create temp dir: ' . $dir);
        }
        $resolved = realpath($dir);
        if ($resolved === false) {
            throw new RuntimeException('Failed to resolve temp dir');
        }
        $this->gitToplevel = $resolved;
        $this->projectRoot = $resolved . '/project';
        mkdir($this->projectRoot, 0777, true);

        $this->exec('git init', $this->gitToplevel);
        $this->exec('git config user.email "test@example.com"', $this->gitToplevel);
        $this->exec('git config user.name "Test"', $this->gitToplevel);
        $this->exec('git config commit.gpgsign false', $this->gitToplevel);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->gitToplevel)) {
            $this->removeRecursive($this->gitToplevel);
        }
    }

    #[Test]
    public function itResolvesNamespaceExtractionPathAgainstProjectRootNotGitToplevel(): void
    {
        // Identical project-relative path, two different files, two different
        // namespaces. extractNamespace must read the project copy, NOT the
        // top-level copy.
        mkdir($this->gitToplevel . '/src', 0777, true);
        file_put_contents(
            $this->gitToplevel . '/src/Service.php',
            "<?php\nnamespace App\\Wrong;\nclass Service {}\n",
        );

        mkdir($this->projectRoot . '/src', 0777, true);
        file_put_contents(
            $this->projectRoot . '/src/Service.php',
            "<?php\nnamespace App\\Right;\nclass Service {}\n",
        );

        // Stage only the project-side file so the diff carries
        // `project/src/Service.php`, which translates to `src/Service.php`
        // project-relative.
        $this->exec('git add project/src/Service.php', $this->gitToplevel);

        $projectRoot = AbsolutePath::fromString($this->projectRoot);
        $gitClient = new GitClient($projectRoot);
        $filter = new GitScopeFilter($gitClient, new GitScope('staged'), $projectRoot);

        // The namespace we *expect* to be in the index — extracted from the
        // project file, not the top-level file.
        $rightViolation = new Violation(
            location: new Location(RelativePath::fromString('src/Other.php'), null),
            symbolPath: SymbolPath::forNamespace('App\\Right'),
            ruleName: 'size',
            violationCode: 'size',
            message: 'Namespace too large',
            severity: Severity::Warning,
        );

        // The namespace that would be picked up if extractNamespace had read
        // the top-level file instead. Must NOT be in the index.
        $wrongViolation = new Violation(
            location: new Location(RelativePath::fromString('src/Other.php'), null),
            symbolPath: SymbolPath::forNamespace('App\\Wrong'),
            ruleName: 'size',
            violationCode: 'size',
            message: 'Namespace too large',
            severity: Severity::Warning,
        );

        self::assertTrue(
            $filter->shouldInclude($rightViolation),
            'extractNamespace must read the project-root file (namespace App\\Right) — failure here means it read the git-toplevel file instead',
        );
        self::assertFalse(
            $filter->shouldInclude($wrongViolation),
            'top-level-file namespace (App\\Wrong) must not appear in the index when project root is a strict git subdir',
        );
    }

    private function exec(string $command, string $cwd): void
    {
        $process = Process::fromShellCommandline($command, $cwd);
        $process->mustRun();
    }

    private function removeRecursive(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeRecursive($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
