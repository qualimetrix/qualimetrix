<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Infrastructure\Git\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Run\Contract\Configuration\GeneratedFilePolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\RunConfiguration;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorDefinition;
use Qualimetrix\Core\Pattern\SelectorKind;
use Qualimetrix\Infrastructure\Git\GitScopeResolver;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[CoversClass(GitScopeResolver::class)]
final class GitScopeResolverTest extends TestCase
{
    #[Test]
    public function itUsesProjectRootForGitClient(): void
    {
        $root = sys_get_temp_dir() . '/qmx-git-scope-' . bin2hex(random_bytes(8));
        mkdir($root);
        try {
            (new Process(['git', 'init'], $root))->mustRun();
            file_put_contents($root . '/fixture.php', '<?php');
            (new Process(['git', 'add', 'fixture.php'], $root))->mustRun();
            (new Process(['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.invalid', 'commit', '-m', 'Initial fixture'], $root))->mustRun();
            $projectRoot = AbsolutePath::fromString($root);

            $resolved = new RunConfiguration(
                pathExcludes: self::patterns('vendor', 'node_modules', '.git'),
                projectRoot: $projectRoot,
                generatedFilePolicy: GeneratedFilePolicy::Exclude,
                projectScope: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement(universe: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse(projectRoot: $projectRoot, pathsAuthored: true, denominator: [], prunedTargets: [], reasons: [], namespaceMapUsable: true, pathResolutions: [], manifestFacts: (new \Qualimetrix\Infrastructure\Composer\ComposerManifestReader())->read($projectRoot)), paths: [AbsolutePath::fromString($projectRoot->value() . '/src')], scopeState: \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeState::Covered, uncoveredRoots: []),
                authoredPathExcludes: [],
                autoloadDevPolicy: \Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy::Exclude,
            );

            $resolver = new GitScopeResolver();
            $result = $resolver->resolve('git:HEAD', $resolved);

            self::assertNotNull($result->gitClient);
            self::assertTrue($result->projectRoot->equals($projectRoot));
        } finally {
            (new Filesystem())->remove($root);
        }
    }

    #[Test]
    public function itDoesNotCreateGitClientWithoutGitOptions(): void
    {
        $projectRoot = AbsolutePath::fromString('/some/project');
        $resolved = new RunConfiguration(
            pathExcludes: self::patterns('vendor', 'node_modules', '.git'),
            projectRoot: $projectRoot,
            generatedFilePolicy: GeneratedFilePolicy::Exclude,
            projectScope: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement(universe: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse(projectRoot: $projectRoot, pathsAuthored: true, denominator: [], prunedTargets: [], reasons: [], namespaceMapUsable: true, pathResolutions: [], manifestFacts: (new \Qualimetrix\Infrastructure\Composer\ComposerManifestReader())->read($projectRoot)), paths: [AbsolutePath::fromString('/some/project/src')], scopeState: \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeState::Covered, uncoveredRoots: []),
            authoredPathExcludes: [],
            autoloadDevPolicy: \Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy::Exclude,
        );

        $resolver = new GitScopeResolver();
        $result = $resolver->resolve(null, $resolved);

        self::assertNull($result->gitClient);
    }

    #[Test]
    public function itPreservesCapturedPathsAndRootWithoutGitSelection(): void
    {
        $projectRoot = AbsolutePath::fromString('/some/project');

        $resolved = new RunConfiguration(
            pathExcludes: self::patterns('vendor', 'tests'),
            projectRoot: $projectRoot,
            generatedFilePolicy: GeneratedFilePolicy::Exclude,
            projectScope: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement(universe: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse(projectRoot: $projectRoot, pathsAuthored: true, denominator: [], prunedTargets: [], reasons: [], namespaceMapUsable: true, pathResolutions: [], manifestFacts: (new \Qualimetrix\Infrastructure\Composer\ComposerManifestReader())->read($projectRoot)), paths: [AbsolutePath::fromString('/some/project/src'), AbsolutePath::fromString('/some/project/tests')], scopeState: \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeState::Covered, uncoveredRoots: []),
            authoredPathExcludes: [],
            autoloadDevPolicy: \Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy::Exclude,
        );

        $result = (new GitScopeResolver())->resolve(null, $resolved);

        self::assertSame($resolved->paths, $result->paths);
        self::assertCount(2, $result->paths);
        self::assertSame($projectRoot, $result->projectRoot);
        self::assertNull($result->reportScope);
    }

    #[Test]
    public function itReturnsTheCapturedPathsForFullAnalysis(): void
    {
        $projectRoot = AbsolutePath::fromString('/some/project');
        $resolved = new RunConfiguration(
            pathExcludes: self::patterns('vendor', 'node_modules', '.git'),
            projectRoot: $projectRoot,
            generatedFilePolicy: GeneratedFilePolicy::Exclude,
            projectScope: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeMeasurement(universe: new \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeUniverse(projectRoot: $projectRoot, pathsAuthored: true, denominator: [], prunedTargets: [], reasons: [], namespaceMapUsable: true, pathResolutions: [], manifestFacts: (new \Qualimetrix\Infrastructure\Composer\ComposerManifestReader())->read($projectRoot)), paths: [AbsolutePath::fromString('/some/project/src')], scopeState: \Qualimetrix\Analysis\Run\Contract\Configuration\ProjectScopeState::Covered, uncoveredRoots: []),
            authoredPathExcludes: [],
            autoloadDevPolicy: \Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy::Exclude,
        );

        $resolver = new GitScopeResolver();
        $result = $resolver->resolve(null, $resolved);

        self::assertSame($resolved->paths, $result->paths);
        self::assertSame($projectRoot, $result->projectRoot);
        self::assertNull($result->gitClient);
    }

    /** @return list<PathPattern> */
    private static function patterns(string ...$values): array
    {
        return array_values(array_map(
            static fn(string $value): PathPattern => new PathPattern(new SelectorDefinition(SelectorKind::Subtree, $value)),
            $values,
        ));
    }
}
