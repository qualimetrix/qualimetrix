<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Integration\Infrastructure\Git;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Finding\Contract\Location;
use Qualimetrix\Analysis\Finding\Contract\Severity;
use Qualimetrix\Analysis\Finding\Contract\Violation;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Symbol\MetricSubject;
use Qualimetrix\Core\Symbol\SymbolPath;
use Qualimetrix\Infrastructure\Git\GitClient;
use Qualimetrix\Infrastructure\Git\ReportingGitScopeQuery;
use Qualimetrix\Reporting\FindingProjection\Contract\GitScopeQueryInterface;
use Qualimetrix\Reporting\FindingProjection\Contract\GitScopeRequest;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * T10 regression at the `extractNamespace()` site: when the project root sits in
 * a strict subdirectory of the git top-level, the file that backs namespace
 * extraction must be resolved against {@see ReportingGitScopeQuery::$projectRoot},
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
#[CoversClass(ReportingGitScopeQuery::class)]
final class ReportingGitScopeQueryProjectSubdirTest extends TestCase
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
        $result = (new ReportingGitScopeQuery())->resolve(new GitScopeRequest('staged', $projectRoot, true));

        // The namespace we *expect* to be in the index — extracted from the
        // project file, not the top-level file.
        $rightViolation = new Violation(
            location: new Location(RelativePath::fromString('src/Other.php'), null),
            symbolPath: SymbolPath::forNamespace('App\\Right'),
            subject: MetricSubject::aggregate(SymbolPath::forNamespace('App\\Right')),
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
            subject: MetricSubject::aggregate(SymbolPath::forNamespace('App\\Wrong')),
            ruleName: 'size',
            violationCode: 'size',
            message: 'Namespace too large',
            severity: Severity::Warning,
        );

        self::assertTrue(
            \in_array($rightViolation->symbolPath->namespace, $result->namespaces, true),
            'extractNamespace must read the project-root file (namespace App\\Right) — failure here means it read the git-toplevel file instead',
        );
        self::assertFalse(
            \in_array($wrongViolation->symbolPath->namespace, $result->namespaces, true),
            'top-level-file namespace (App\\Wrong) must not appear in the index when project root is a strict git subdir',
        );
    }

    #[Test]
    public function itUsesExplicitProjectRootArgumentNotGitClientProjectRoot(): void
    {
        // The request root is the only source of both the diff boundary and
        // namespace extraction. A sibling project contains the same relative
        // path with a different namespace to expose accidental root leakage.
        $projectA = $this->gitToplevel . '/projectA';
        $projectB = $this->gitToplevel . '/projectB';
        mkdir($projectA . '/src', 0777, true);
        mkdir($projectB . '/src', 0777, true);
        file_put_contents(
            $projectA . '/src/Service.php',
            "<?php\nnamespace App\\GitClientRoot;\nclass Service {}\n",
        );
        file_put_contents(
            $projectB . '/src/Service.php',
            "<?php\nnamespace App\\ExplicitRoot;\nclass Service {}\n",
        );

        // Stage the explicitly requested project root.
        $this->exec('git add projectB/src/Service.php', $this->gitToplevel);

        $explicitRoot = AbsolutePath::fromString($projectB);
        $result = (new ReportingGitScopeQuery())->resolve(new GitScopeRequest('staged', $explicitRoot, true));

        // The request must resolve the staged projectB file and its namespace.
        $explicit = new Violation(
            location: new Location(RelativePath::fromString('src/Other.php'), null),
            symbolPath: SymbolPath::forNamespace('App\\ExplicitRoot'),
            subject: MetricSubject::aggregate(SymbolPath::forNamespace('App\\ExplicitRoot')),
            ruleName: 'size',
            violationCode: 'size',
            message: 'Namespace too large',
            severity: Severity::Warning,
        );
        $gitClientRoots = new Violation(
            location: new Location(RelativePath::fromString('src/Other.php'), null),
            symbolPath: SymbolPath::forNamespace('App\\GitClientRoot'),
            subject: MetricSubject::aggregate(SymbolPath::forNamespace('App\\GitClientRoot')),
            ruleName: 'size',
            violationCode: 'size',
            message: 'Namespace too large',
            severity: Severity::Warning,
        );

        self::assertTrue(
            \in_array($explicit->symbolPath->namespace, $result->namespaces, true),
            'ReportingGitScopeQuery must use its explicit $projectRoot arg, not $gitClient->getProjectRoot()',
        );
        self::assertFalse(
            \in_array($gitClientRoots->symbolPath->namespace, $result->namespaces, true),
            'GitClient.projectRoot must not leak into the namespace index when an explicit projectRoot is provided',
        );
    }

    #[Test]
    public function itProjectsGitScopeThroughTheReportingPortWithoutAReverseImport(): void
    {
        $query = new ReportingGitScopeQuery();
        self::assertContains(GitScopeQueryInterface::class, class_implements($query));

        $result = $query->resolve(new GitScopeRequest(
            'staged',
            AbsolutePath::fromString($this->projectRoot),
            false,
        ));
        self::assertSame([], $result->namespaces);
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
